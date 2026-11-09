<?php
/**
 * MIGRACIÓN 0009 · Separar y cifrar los contactos existentes
 *
 * Fase 2 · Debe ejecutarse DESPUÉS de 0008_seguimiento_seguro.sql
 *
 * Toma el campo heredado `denunciante_contacto`, que concatena teléfono y
 * correo como «3001234567 | persona@correo.com», los separa en columnas
 * propias, los cifra con AES-256-GCM y calcula su índice ciego.
 *
 * Es una migración en PHP y no en SQL porque el cifrado autenticado no puede
 * ejecutarse dentro del motor de base de datos.
 *
 * ⚠ La columna `denunciante_contacto` NO se borra aquí. Se conserva hasta que
 * el resultado se verifique. La migración 0010 la retirará.
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config.php php bd/migraciones/0009_cifrar_contactos.php --simular
 *   BV_CONFIG=/ruta/bv_config.php php bd/migraciones/0009_cifrar_contactos.php --ejecutar
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Esta migración solo se ejecuta por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/init.php';

$simular = !in_array('--ejecutar', $argv, true);

echo "\n=== Migración 0009 · Cifrado de contactos ===\n";
echo $simular
    ? "MODO SIMULACIÓN — no se escribirá nada. Usar --ejecutar para aplicar.\n\n"
    : "MODO EJECUCIÓN — se modificarán datos.\n\n";

// Comprobar que 0008 ya corrió
$columnas = db_select("SHOW COLUMNS FROM denuncias LIKE 'denunciante_correo_cifrado'");
if (empty($columnas)) {
    exit("ABORTADO: falta ejecutar 0008_seguimiento_seguro.sql\n");
}

$filas = db_select(
    'SELECT id, codigo, denunciante_contacto
       FROM denuncias
      WHERE denunciante_contacto IS NOT NULL
        AND denunciante_contacto <> \'\'
        AND denunciante_correo_cifrado IS NULL
      ORDER BY id'
);

if ($filas === false) {
    exit("ABORTADO: no se pudo leer la tabla denuncias\n");
}

echo 'Denuncias por migrar: ' . count($filas) . "\n\n";

$migradas = 0;
$sin_correo = 0;
$sin_telefono = 0;
$fallidas = 0;

foreach ($filas as $fila) {
    $bruto = $fila['denunciante_contacto'];

    // Formato esperado: «telefono | correo». Se admiten variantes.
    $telefono = null;
    $correo   = null;

    if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $bruto, $m)) {
        $correo = $m[0];
    }

    $resto = $correo !== null ? str_replace($correo, '', $bruto) : $bruto;
    $digitos = preg_replace('/\D+/', '', $resto);
    if (strlen($digitos) >= 7) {
        $telefono = $digitos;
    }

    if ($correo === null)   { $sin_correo++; }
    if ($telefono === null) { $sin_telefono++; }

    if ($correo === null && $telefono === null) {
        echo "  [!] {$fila['codigo']} — no se pudo extraer ningún contacto\n";
        $fallidas++;
        continue;
    }

    if ($simular) {
        echo "  {$fila['codigo']} → correo: "
           . ($correo ? enmascarar_contacto($correo, 'correo') : '(ninguno)')
           . ' · teléfono: '
           . ($telefono ? enmascarar_contacto($telefono, 'telefono') : '(ninguno)')
           . "\n";
        $migradas++;
        continue;
    }

    $datos = [
        'denunciante_correo_cifrado'   => cifrar_contacto($correo),
        'denunciante_telefono_cifrado' => cifrar_contacto($telefono),
        'denunciante_correo_indice'    => $correo   ? indice_ciego($correo, 'correo')     : null,
        'denunciante_telefono_indice'  => $telefono ? indice_ciego($telefono, 'telefono') : null,
        // Sin dato previo sobre la preferencia: el correo deja rastro escrito
        // y no depende de la cobertura celular
        'canal_preferido'              => $correo ? 'correo' : 'sms',
    ];

    $resultado = db_update_simple('denuncias', $datos, ['id' => $fila['id']]);

    if ($resultado === false) {
        echo "  [FALLO] {$fila['codigo']}\n";
        $fallidas++;
        continue;
    }

    // Verificación inmediata: si no se puede descifrar, el dato se perdió
    $control = db_select_one(
        'SELECT denunciante_correo_cifrado FROM denuncias WHERE id = ?',
        [$fila['id']]
    );

    if ($correo !== null && descifrar_contacto($control['denunciante_correo_cifrado']) !== $correo) {
        echo "  [FALLO DE VERIFICACIÓN] {$fila['codigo']} — el descifrado no coincide\n";
        $fallidas++;
        continue;
    }

    $migradas++;
}

echo "\n--- Resumen ---\n";
echo "Procesadas correctamente: $migradas\n";
echo "Sin correo detectable:    $sin_correo\n";
echo "Sin teléfono detectable:  $sin_telefono\n";
echo "Fallidas:                 $fallidas\n";

if ($simular) {
    echo "\nSimulación terminada. Revisar la muestra y ejecutar con --ejecutar.\n";
} else {
    echo "\nMigración aplicada. Verificar antes de ejecutar 0010:\n";
    echo "  SELECT COUNT(*) FROM denuncias WHERE denunciante_contacto IS NOT NULL\n";
    echo "                                   AND denunciante_correo_cifrado IS NULL;\n";
    echo "  (debe devolver solo las filas sin contacto detectable)\n";
}

exit($fallidas > 0 ? 1 : 0);
