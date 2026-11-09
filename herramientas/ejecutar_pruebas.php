<?php
/**
 * LANZADOR DE LA BATERÍA COMPLETA
 *
 * Ejecuta las ocho suites, la auditoría forense y la de frontend, y resume.
 *
 * Se apoya en el CÓDIGO DE SALIDA de cada proceso, no en su texto. Un resumen
 * hecho a base de leer la última línea con expresiones regulares dio una vez
 * «TOTAL: 0 correctas · 0 fallidas» y lo presentó con el mismo aire de calma
 * que un éxito. Cuando no se puede medir algo, hay que decir que no se pudo,
 * no contarlo como cero.
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config.php php herramientas/ejecutar_pruebas.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__));

$suites = [
    'Fase 1 · Reparación'          => 'pruebas/fase1_reparacion.php',
    'Fase 2 · Seguimiento seguro'  => 'pruebas/fase2_seguimiento.php',
    'Fase 3 · Reglas de negocio'   => 'pruebas/reglas/fase3_reglas.php',
    'Fase 4 · Notificaciones'      => 'pruebas/reglas/fase4_notificaciones.php',
    'Fase 5 · Medidas y riesgo'    => 'pruebas/reglas/fase5_medidas.php',
    'Fase 6 · Cumplimiento'        => 'pruebas/reglas/fase6_cumplimiento.php',
    'Fase 7 · Prevención'          => 'pruebas/reglas/fase7_prevencion.php',
    'Fase 8 · Módulos de interfaz' => 'pruebas/reglas/fase8_modulos.php',
];

$auditorias = [
    'Auditoría forense'  => 'herramientas/auditoria_forense.php',
    'Auditoría frontend' => 'herramientas/auditar_frontend.php',
    'Barrido de rutas'   => 'herramientas/barrido_rutas.php',
];

echo str_repeat('=', 67) . "\n";
echo " BATERÍA COMPLETA · Botón Violeta\n";
echo str_repeat('=', 67) . "\n\n";

$suma_ok = 0;
$suma_ko = 0;
$sin_medir = [];
$rojas = [];

function ejecutar($ruta) {
    $orden = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/' . $ruta) . ' 2>&1';

    $salida = [];
    $codigo = 1;
    exec($orden, $salida, $codigo);

    return ['texto' => implode("\n", $salida), 'codigo' => $codigo];
}

foreach ($suites as $nombre => $ruta) {
    $r = ejecutar($ruta);

    // Se leen los números para informar, pero quien decide es el código de
    // salida: si no cuadran, se dice que no se pudo medir.
    $ok = $ko = null;

    if (preg_match('/(\d+) correctas, (\d+) (?:fallidas|FALLIDAS)/', $r['texto'], $m)) {
        $ok = (int)$m[1];
        $ko = (int)$m[2];
    }

    if ($ok === null) {
        $sin_medir[] = $nombre;
        printf("  %-32s  SIN MEDIR (código %d)\n", $nombre, $r['codigo']);
        continue;
    }

    $suma_ok += $ok;
    $suma_ko += $ko;

    $veredicto = ($r['codigo'] === 0 && $ko === 0) ? 'OK   ' : 'FALLA';

    if ($veredicto === 'FALLA') { $rojas[] = $nombre; }

    printf("  %-32s  %s  %4d correctas · %d fallidas\n", $nombre, $veredicto, $ok, $ko);
}

echo "\n" . str_repeat('-', 67) . "\n";

foreach ($auditorias as $nombre => $ruta) {
    $r = ejecutar($ruta);

    if (preg_match('/CRÍTICOS (\d+) · ALTOS (\d+) · MEDIOS (\d+) · BAJOS (\d+)/u', $r['texto'], $m)) {
        printf("  %-32s  CRÍT %d · ALTO %d · MEDIO %d · BAJO %d\n",
            $nombre, $m[1], $m[2], $m[3], $m[4]);

        if ((int)$m[1] > 0 || (int)$m[2] > 0) { $rojas[] = $nombre; }
        continue;
    }

    if (preg_match('/(\d+) cargas · (\d+) errores fatales · (\d+) pantallas vacías/u', $r['texto'], $m)) {
        printf("  %-32s  %s cargas · %s fatales · %s vacías\n", $nombre, $m[1], $m[2], $m[3]);
        if ($r['codigo'] !== 0) { $rojas[] = $nombre; }
        continue;
    }

    // La de frontend informa distinto
    if (preg_match('/(\d+) (?:problemas?|incoherencias?)/u', $r['texto'], $m)) {
        printf("  %-32s  %s (código %d)\n", $nombre, $m[0], $r['codigo']);
        if ($r['codigo'] !== 0) { $rojas[] = $nombre; }
        continue;
    }

    printf("  %-32s  %s (código %d)\n",
        $nombre, $r['codigo'] === 0 ? 'OK' : 'REVISAR', $r['codigo']);

    if ($r['codigo'] !== 0) { $rojas[] = $nombre; }
}

echo str_repeat('=', 67) . "\n";
printf("  Comprobaciones: %d correctas · %d fallidas\n", $suma_ok, $suma_ko);

if ($sin_medir) {
    echo "  SIN MEDIR: " . implode(', ', $sin_medir) . "\n";
}

if ($rojas) {
    echo "  EN ROJO: " . implode(', ', $rojas) . "\n";
}

echo str_repeat('=', 67) . "\n";

exit(($rojas || $sin_medir) ? 1 : 0);
