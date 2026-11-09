<?php
/**
 * PRUEBAS DE REGLAS DE NEGOCIO · FASE 6
 *
 *   RN-10  Supresión estadística       (Ac. 002 art. 48.1)
 *   RN-13  Integridad de la auditoría  (Ac. 003 art. 45)
 *
 * Más: cadena de custodia de la evidencia y el informe anual del art. 40.
 *
 * Definition of Done del roadmap:
 *   «Informe anual del art. 40 generable con un clic y sin celdas < 5»
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config_pruebas.php php pruebas/reglas/fase6_cumplimiento.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Estas pruebas solo se ejecutan por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/init.php';

if (APP_ENV === 'production') {
    exit("ABORTADO: APP_ENV es 'production'.\n");
}

$correctas = 0;
$fallidas  = 0;

function chequear(string $n, bool $c, string $d = ''): void {
    global $correctas, $fallidas;
    if ($c) { $correctas++; echo "  [OK]    $n\n"; }
    else { $fallidas++; echo "  [FALLO] $n" . ($d !== '' ? "  ($d)" : '') . "\n"; }
}
function seccion(string $t): void { echo "\n=== $t ===\n"; }

db_execute('DELETE FROM reportes_generados');

$usuario = db_select_one('SELECT id FROM usuarios LIMIT 1');
$denuncia = db_select_one('SELECT id, codigo FROM denuncias ORDER BY id LIMIT 1');

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-13 · Auditoría encadenada por hash');

chequear('la tabla tiene las columnas de la cadena', auditoria_encadenada_disponible() === true);

db_execute('DELETE FROM auditoria');

for ($i = 1; $i <= 8; $i++) {
    audit_log($usuario['id'], 'prueba_cadena_' . $i, 'denuncias', ['motivo' => 'cadena'], $denuncia['id']);
}

$filas = db_select('SELECT * FROM auditoria ORDER BY id') ?: [];
chequear('se registraron 8 entradas', count($filas) === 8, (string)count($filas));
chequear('la primera es el registro génesis',
    $filas[0]['hash_anterior'] === str_repeat('0', 64));
chequear('cada registro enlaza con el anterior',
    $filas[1]['hash_anterior'] === $filas[0]['hash_actual']);
chequear('todos los hashes tienen 64 caracteres',
    count(array_filter($filas, fn($f) => strlen((string)$f['hash_actual']) === 64)) === 8);
chequear('no hay dos hashes iguales',
    count(array_unique(array_column($filas, 'hash_actual'))) === 8);

$v = verificar_cadena_auditoria();
chequear('la cadena completa se verifica íntegra', $v['integra'] === true, $v['motivo']);
chequear('se verificaron los 8 registros', $v['verificados'] === 8, (string)$v['verificados']);

// Alteración retroactiva del CONTENIDO
db_execute("UPDATE auditoria SET accion = 'accion_alterada' WHERE id = ?", [$filas[3]['id']]);
$v = verificar_cadena_auditoria();
chequear('una alteración del contenido rompe la cadena', $v['integra'] === false);
chequear('se señala el registro exacto', $v['primer_fallo'] === (int)$filas[3]['id'],
    (string)$v['primer_fallo']);
chequear('el motivo indica alteración posterior',
    strpos($v['motivo'], 'alterado') !== false, $v['motivo']);

// Restaurar y probar el BORRADO de una fila
db_execute("UPDATE auditoria SET accion = ? WHERE id = ?", ['prueba_cadena_4', $filas[3]['id']]);
chequear('restaurado el contenido, la cadena vuelve a ser íntegra',
    verificar_cadena_auditoria()['integra'] === true);

db_execute('DELETE FROM auditoria WHERE id = ?', [$filas[4]['id']]);
$v = verificar_cadena_auditoria();
chequear('borrar un registro intermedio rompe la cadena', $v['integra'] === false);
chequear('el motivo señala eliminación o inserción',
    strpos($v['motivo'], 'eliminado') !== false || strpos($v['motivo'], 'Ruptura') !== false,
    $v['motivo']);

chequear('el hash depende del contenido, no solo de la posición',
    calcular_hash_auditoria(['accion' => 'a'], 'x') !== calcular_hash_auditoria(['accion' => 'b'], 'x'));
chequear('el hash depende del eslabón anterior',
    calcular_hash_auditoria(['accion' => 'a'], 'x') !== calcular_hash_auditoria(['accion' => 'a'], 'y'));

// Prohibición 5: ningún código actualiza ni elimina `auditoria`
$fuentes = array_merge(glob(BASE_PATH . '/includes/*.php'), glob(BASE_PATH . '/includes/*/*.php'),
                       glob(BASE_PATH . '/modules/*/*.php'), glob(BASE_PATH . '/cron/*.php'));
$escrituras = 0;
foreach ($fuentes as $archivo) {
    $codigo = file_get_contents($archivo);
    if (preg_match('/(UPDATE|DELETE\s+FROM)\s+`?auditoria`?/i', $codigo)) {
        $escrituras++;
        echo "      ⚠ escritura sobre auditoria en " . basename($archivo) . "\n";
    }
}
chequear('ningún archivo de la aplicación actualiza ni elimina `auditoria`', $escrituras === 0);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-10 · Supresión primaria');

$tabla = [
    'fisica'      => ['nueva' => 12, 'en_proceso' => 8,  'resuelta' => 9],
    'psicologica' => ['nueva' => 3,  'en_proceso' => 15, 'resuelta' => 11],
    'sexual'      => ['nueva' => 1,  'en_proceso' => 7,  'resuelta' => 6],
];

$r = suprimir_celdas($tabla, 5);

chequear('se suprime una celda con frecuencia 3',
    $r['tabla']['psicologica']['nueva'] === CELDA_SUPRIMIDA);
chequear('se suprime una celda con frecuencia 1',
    $r['tabla']['sexual']['nueva'] === CELDA_SUPRIMIDA);
chequear('NO se suprime una celda con frecuencia 12',
    $r['tabla']['fisica']['nueva'] === 12);
chequear('NO se suprime una celda igual al umbral', suprimir_celdas(
    ['x' => ['a' => 5, 'b' => 9, 'c' => 9]], 5)['tabla']['x']['a'] === 5);

$ceros = suprimir_celdas(['x' => ['a' => 0, 'b' => 9, 'c' => 9]], 5);
chequear('el cero NO se suprime: «ningún caso» no identifica a nadie',
    $ceros['tabla']['x']['a'] === 0);

seccion('RN-10 · Supresión secundaria');

// Una sola celda baja en una fila: sin supresión secundaria se deduce restando
$tabla = ['unica' => ['a' => 2, 'b' => 20, 'c' => 30]];
$r = suprimir_celdas($tabla, 5);
$ocultas = count(array_filter($r['tabla']['unica'], fn($v) => $v === CELDA_SUPRIMIDA));

chequear('con una sola celda baja se suprimen DOS', $ocultas >= 2, (string)$ocultas);
chequear('la segunda suprimida es la de menor valor',
    $r['tabla']['unica']['b'] === CELDA_SUPRIMIDA, json_encode($r['tabla']['unica']));

// Ninguna fila ni columna debe quedar con exactamente una supresión
$tabla = [
    'f1' => ['a' => 1,  'b' => 30, 'c' => 40],
    'f2' => ['a' => 25, 'b' => 30, 'c' => 40],
    'f3' => ['a' => 25, 'b' => 30, 'c' => 40],
];
$r = suprimir_celdas($tabla, 5);

$filas_con_una = 0;
foreach ($r['tabla'] as $columnas) {
    if (count(array_filter($columnas, fn($v) => $v === CELDA_SUPRIMIDA)) === 1) {
        $filas_con_una++;
    }
}
chequear('ninguna fila queda con exactamente una celda suprimida', $filas_con_una === 0,
    (string)$filas_con_una);

$columnas_con_una = 0;
foreach (['a', 'b', 'c'] as $col) {
    $n = 0;
    foreach ($r['tabla'] as $columnas) {
        if (($columnas[$col] ?? null) === CELDA_SUPRIMIDA) { $n++; }
    }
    if ($n === 1) { $columnas_con_una++; }
}
chequear('ninguna columna queda con exactamente una celda suprimida', $columnas_con_una === 0,
    (string)$columnas_con_una);

seccion('RN-10 · El sistema se NIEGA a exportar');

$check = verificar_supresion(['x' => ['a' => 2, 'b' => 20]], 5);
chequear('verificar_supresion() detecta una celda por debajo del umbral', $check['seguro'] === false);
chequear('el motivo cita RN-10', strpos($check['motivo'], 'RN-10') !== false);
chequear('el motivo dice que el reporte NO se exporta',
    strpos($check['motivo'], 'no se exporta') !== false, $check['motivo']);

$check = verificar_supresion($r['tabla'], 5);
chequear('una tabla ya suprimida pasa la verificación', $check['seguro'] === true, $check['motivo']);

chequear('el umbral por defecto es 5', umbral_supresion() === 5, (string)umbral_supresion());
chequear('el umbral nunca baja de 2', umbral_supresion() >= 2);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Definition of Done · informe anual del art. 40');

$anio = (int)date('Y');
$resultado = generar_reporte('anual_art40', (string)$anio, $usuario['id']);

chequear('el informe anual se genera', $resultado['exito'] === true, $resultado['motivo']);

$informe = $resultado['reporte'];
chequear('destino: CSU y Corte Constitucional',
    strpos($informe['destino'], 'Corte Constitucional') !== false);
chequear('cita el art. 40', strpos($informe['fundamento'], 'art. 40') !== false);

// Contenido que el artículo exige por nombre
chequear('incluye casos atendidos', isset($informe['secciones']['casos_atendidos']));
chequear('incluye medidas adoptadas', isset($informe['secciones']['medidas_adoptadas']));
chequear('incluye tiempos de respuesta', isset($informe['secciones']['tiempos_de_respuesta']));
chequear('incluye valoraciones de riesgo', isset($informe['secciones']['valoraciones_riesgo']));
chequear('incluye nota metodológica sobre la supresión',
    strpos($informe['nota_metodologica'], 'art. 48.1') !== false);
chequear('declara el umbral aplicado', $informe['umbral'] === 5);

// Ninguna celda del informe puede estar por debajo del umbral
$bajo_umbral = 0;
foreach ($informe['secciones'] as $seccion) {
    if (!is_array($seccion)) { continue; }
    foreach ($seccion as $fila) {
        if (!is_array($fila)) { continue; }
        foreach ($fila as $valor) {
            if (is_numeric($valor) && $valor > 0 && $valor < 5) { $bajo_umbral++; }
        }
    }
}
chequear('NINGUNA celda del informe queda por debajo de 5', $bajo_umbral === 0,
    (string)$bajo_umbral);

// Trazabilidad del informe
$guardado = db_select_one('SELECT * FROM reportes_generados WHERE id = ?', [$resultado['reporte_id']]);
chequear('el informe queda persistido', $guardado !== false);
chequear('se guarda el hash del contenido', strlen((string)$guardado['hash_contenido']) === 64);
chequear('se registra el umbral aplicado', (int)$guardado['umbral_aplicado'] === 5);
chequear('el contenido guardado no contiene relatos',
    !preg_match('/descripcion|relato/i', $guardado['contenido_json']));

$aud = db_select_one("SELECT COUNT(*) AS t FROM auditoria WHERE accion = 'reporte_generado'");
chequear('la generación queda auditada', (int)$aud['t'] > 0);

chequear('un tipo de reporte desconocido se rechaza',
    generar_reporte('inventado', '2026', $usuario['id'])['exito'] === false);

// Tiempos de respuesta con muestra insuficiente
$tiempos = tiempos_de_respuesta('1900-01-01', '1900-12-31');
chequear('con muestra insuficiente los promedios se suprimen',
    $tiempos['dias_hasta_primera_medida'] === CELDA_SUPRIMIDA);
chequear('se explica por qué se suprimió',
    strpos($tiempos['nota'] ?? '', 'RN-10') !== false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Cadena de custodia de la evidencia');

$ruta_dir = UPLOAD_PATH . '/' . $denuncia['id'];
if (!is_dir($ruta_dir)) { mkdir($ruta_dir, 0750, true); }

$nombre = nombre_archivo_seguro('evidencia.pdf');
file_put_contents($ruta_dir . '/' . $nombre, "%PDF-1.4\nContenido sintetico de prueba\n");

$archivo_id = db_insert_simple('archivos', [
    'denuncia_id' => $denuncia['id'], 'nombre_original' => 'evidencia.pdf',
    'nombre_almacenado' => $nombre, 'ruta' => $denuncia['id'] . '/' . $nombre,
    'tipo_mime' => 'application/pdf', 'tamano' => 40,
    'usuario_id' => null, 'created_at' => date('Y-m-d H:i:s'),
]);

$hash = sellar_evidencia($archivo_id);
chequear('la evidencia se sella con SHA-256', is_string($hash) && strlen($hash) === 64);
chequear('la integridad se verifica', verificar_integridad_evidencia($archivo_id)['integra'] === true);

// Alteración del archivo en disco
file_put_contents($ruta_dir . '/' . $nombre, "%PDF-1.4\nCONTENIDO ALTERADO\n");
$v = verificar_integridad_evidencia($archivo_id);
chequear('alterar el archivo rompe la integridad', $v['integra'] === false);
chequear('el motivo señala que el contenido no coincide',
    strpos($v['motivo'], 'no coincide') !== false, $v['motivo']);
chequear('la alteración queda marcada en la base de datos',
    (int)db_select_one('SELECT integridad_ok FROM archivos WHERE id = ?',
        [$archivo_id])['integridad_ok'] === 0);

// Entrega autorizada
$coord = db_select_one("SELECT id, rol FROM usuarios WHERE rol='coordinacion_edheg' LIMIT 1");
$psico = db_select_one("SELECT id, rol FROM usuarios WHERE rol='psicologo' LIMIT 1");

$e = entregar_evidencia($archivo_id, $coord['id'], $coord['rol']);
chequear('una evidencia alterada NO se entrega ni a coordinación', $e['autorizado'] === false);

// Restaurar el contenido original
file_put_contents($ruta_dir . '/' . $nombre, "%PDF-1.4\nContenido sintetico de prueba\n");
sellar_evidencia($archivo_id);

$e = entregar_evidencia($archivo_id, $coord['id'], $coord['rol']);
chequear('restaurada la integridad, coordinación sí accede', $e['autorizado'] === true, $e['motivo']);
chequear('se entrega el nombre original', $e['nombre'] === 'evidencia.pdf');

if ($psico) {
    $e = entregar_evidencia($archivo_id, $psico['id'], $psico['rol']);
    chequear('un rol sin asignación NO accede a la evidencia', $e['autorizado'] === false);
    chequear('el mensaje no confirma que la evidencia exista',
        $e['motivo'] === 'Evidencia no encontrada.', $e['motivo']);
}

$aud = db_select_one("SELECT COUNT(*) AS t FROM auditoria WHERE accion = 'evidencia_descargada'");
chequear('cada descarga queda auditada', (int)$aud['t'] > 0);

$aud = db_select_one("SELECT COUNT(*) AS t FROM auditoria WHERE accion = 'evidencia_acceso_denegado'");
chequear('los accesos denegados también se auditan', (int)$aud['t'] > 0);

// Magic bytes
$falso = $ruta_dir . '/falso.pdf';
file_put_contents($falso, "<?php echo 'esto no es un PDF'; ?>");
chequear('un PHP renombrado a .pdf NO pasa la verificación de firma',
    verificar_firma_archivo($falso, 'documento.pdf') === false);
chequear('un PDF real sí pasa la verificación',
    verificar_firma_archivo($ruta_dir . '/' . $nombre, 'evidencia.pdf') === true);
chequear('una extensión no admitida se rechaza',
    verificar_firma_archivo($falso, 'script.exe') === false);
@unlink($falso);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Retención · estructura sin decisiones asumidas');

chequear('no hay políticas de retención sembradas',
    db_count('politicas_retencion') === 0);
chequear('existe la excepción por proceso activo',
    !empty(db_select("SHOW COLUMNS FROM politicas_retencion LIKE 'excepcion_proceso_activo'")));
chequear('existe el registro de solicitudes de supresión',
    !empty(db_select("SHOW TABLES LIKE 'solicitudes_supresion'")));
chequear('el aviso de privacidad es versionado',
    !empty(db_select("SHOW COLUMNS FROM avisos_privacidad LIKE 'version'")));
chequear('la denuncia registra qué versión del aviso se aceptó',
    !empty(db_select("SHOW COLUMNS FROM denuncias LIKE 'aviso_privacidad_version'")));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('-', 60) . "\n";
echo ($fallidas === 0 ? 'TODAS LAS REGLAS SE CUMPLEN' : 'HAY REGLAS INCUMPLIDAS')
   . ": $correctas correctas, $fallidas fallidas\n";

exit($fallidas === 0 ? 0 : 1);
