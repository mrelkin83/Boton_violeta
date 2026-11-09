<?php
/**
 * VERIFICADOR DE CREDENCIALES
 *
 * Comprueba si las contraseñas que la documentación publica abren realmente
 * las cuentas que existen.
 *
 * Nació de un problema concreto: `admin@botonvioleta.com` / `Admin123!` no
 * permitía iniciar sesión, y hubo que averiguar si era porque una prueba había
 * pisado la cuenta o porque el hash sembrado nunca correspondió a esa clave.
 *
 * ⚠ POR QUÉ AHORA MIRA LA BASE Y NO LA MIGRACIÓN
 *
 *   La primera versión leía los INSERT de `0001_esquema_inicial.sql`. Tenía
 *   sentido mientras esa migración sembraba cuentas. Al dejarla solo con
 *   esquema —las cuentas las crea el instalador calculando el hash en el
 *   momento—, la herramienta dejó de encontrar nada y respondía «(no se
 *   encontraron usuarios con hash)» con toda tranquilidad.
 *
 *   Una herramienta de verificación que no encuentra qué verificar debe
 *   decirlo como un fallo, no como un resultado. Ahora consulta la base viva,
 *   que es donde está la respuesta a la pregunta «¿puedo entrar?».
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config.php php herramientas/verificar_credenciales.php
 *   php herramientas/verificar_credenciales.php /ruta/a/un_volcado.sql
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

$raiz = dirname(__DIR__);

$claves = [
    'Admin123!', 'Coord123!', 'Psico123!',
    'Trabajo123!', 'Abogado123!', 'Consulta123!',
    'DesarrolloLocal2026',
];

/** ¿Alguna de las contraseñas conocidas abre este hash? */
function cual_abre($hash, array $claves) {
    foreach ($claves as $clave) {
        if (password_verify($clave, $hash)) {
            return $clave;
        }
    }
    return null;
}

$problemas = 0;

// ─────────────────────────────────────────────────────────────────────────────
// Modo volcado: útil para revisar un respaldo antes de restaurarlo
// ─────────────────────────────────────────────────────────────────────────────
if (isset($argv[1]) && is_file($argv[1])) {
    echo "=== Volcado: " . basename($argv[1]) . " ===\n";

    $sql = file_get_contents($argv[1]);
    $patron = "/'([\\w.@-]+@[\\w.-]+)',\\s*'(\\\$argon2i?d\\\$[^']+)'/";

    preg_match_all($patron, $sql, $filas, PREG_SET_ORDER);

    if (empty($filas)) {
        echo "  Ninguna cuenta con hash en el archivo.\n\n";
    } else {
        foreach ($filas as $fila) {
            $clave = cual_abre($fila[2], $claves);
            printf("  %-34s %s\n", $fila[1], $clave ?? 'NINGUNA de las conocidas');
        }
        echo "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Modo base de datos
// ─────────────────────────────────────────────────────────────────────────────
define('BASE_PATH', $raiz);
require_once BASE_PATH . '/init.php';

if (APP_ENV === 'production') {
    exit("ABORTADO: no se ejecuta contra producción.\n");
}

echo "=== Cuentas en la base de datos ===\n";
echo "Base: " . DB_NAME . "\n\n";

$usuarios = db_select('SELECT email, rol, activo, password FROM usuarios ORDER BY rol, email');

if ($usuarios === false || empty($usuarios)) {
    echo "  NO HAY CUENTAS. Nadie puede entrar al sistema.\n";
    echo "  Crea la primera con: php herramientas/crear_administrador.php\n";
    exit(1);
}

$sin_clave_conocida = 0;

foreach ($usuarios as $u) {
    $clave = cual_abre($u['password'], $claves);
    $estado = (int)$u['activo'] === 1 ? '' : ' [INACTIVA]';

    if ($clave === null) {
        $sin_clave_conocida++;
        printf("  %-32s %-20s (contraseña propia)%s\n", $u['email'], $u['rol'], $estado);
    } else {
        printf("  %-32s %-20s %s%s\n", $u['email'], $u['rol'], $clave, $estado);
    }
}

echo "\n";
printf("  %d cuentas · %d con contraseña documentada · %d con contraseña propia\n",
    count($usuarios), count($usuarios) - $sin_clave_conocida, $sin_clave_conocida);

// ─────────────────────────────────────────────────────────────────────────────
// Comprobaciones que sí pueden fallar
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== Comprobaciones ===\n";

$admins = db_select_one(
    "SELECT COUNT(*) t FROM usuarios WHERE rol IN ('admin','administrador') AND activo = 1"
);

if ((int)($admins['t'] ?? 0) === 0) {
    echo "  [FALLO] No hay ninguna cuenta administradora activa: el sistema es ingobernable.\n";
    $problemas++;
} else {
    echo "  [OK]    Hay {$admins['t']} cuenta(s) administradora(s) activa(s).\n";
}

// Las tres cuentas que la documentación publica
foreach ([['admin@botonvioleta.com', 'Admin123!'],
          ['coordinador@botonvioleta.com', 'Coord123!'],
          ['psicologo@botonvioleta.com', 'Psico123!']] as $par) {

    $fila = db_select_one('SELECT password, activo FROM usuarios WHERE email = ?', [$par[0]]);

    if (!$fila) {
        echo "  [AVISO] {$par[0]} no existe en esta base.\n";
        continue;
    }

    if (!password_verify($par[1], $fila['password'])) {
        echo "  [FALLO] {$par[0]}: la contraseña documentada NO abre la cuenta.\n";
        $problemas++;
    } elseif ((int)$fila['activo'] !== 1) {
        echo "  [FALLO] {$par[0]}: la contraseña es correcta pero la cuenta está inactiva.\n";
        $problemas++;
    } else {
        echo "  [OK]    {$par[0]} entra con la contraseña documentada.\n";
    }
}

// Hashes que no son Argon2id
$debiles = db_select("SELECT email FROM usuarios WHERE password NOT LIKE '\$argon2id\$%'") ?: [];

if ($debiles) {
    foreach ($debiles as $d) {
        echo "  [FALLO] {$d['email']}: su contraseña no está cifrada con Argon2id.\n";
        $problemas++;
    }
} else {
    echo "  [OK]    Todas las contraseñas usan Argon2id.\n";
}

echo "\n";

if ($problemas > 0) {
    echo "$problemas problema(s). Revísalos antes de desplegar.\n";
    exit(1);
}

echo "Sin problemas.\n";
exit(0);
