<?php
/**
 * BARRIDO DE RUTAS
 *
 * Carga cada ruta del enrutador en un proceso propio y comprueba que responde
 * sin error fatal, tanto sin sesión como con una sesión de cada rol.
 *
 * Es lo que ninguna de las suites hace: probar la aplicación como la usa una
 * persona, entrando por la puerta. Una función puede estar perfectamente
 * probada y la página que la usa fallar por una variable no definida, un
 * `require` que no está o un rol que el módulo no contempla.
 *
 * No comprueba que la página se vea bien. Comprueba que existe, que responde y
 * que no se cae. Para lo demás hay que mirarla.
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config.php php herramientas/barrido_rutas.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/init.php';

if (APP_ENV === 'production') {
    exit("ABORTADO: APP_ENV es 'production'.\n");
}

// ─────────────────────────────────────────────────────────────────────────────
// Rutas leídas del propio enrutador
// ─────────────────────────────────────────────────────────────────────────────
$enrutador = file_get_contents(BASE_PATH . '/index_nativo.php');

preg_match_all("/'([a-z0-9-]+)'\s*=>\s*'([^']+\.php)'/", $enrutador, $m, PREG_SET_ORDER);

$rutas = [];
foreach ($m as $r) {
    $rutas[$r[1]] = $r[2];
}

if (empty($rutas)) {
    exit("No se pudo leer ninguna ruta del enrutador. Barrido ABORTADO.\n");
}

// ─────────────────────────────────────────────────────────────────────────────
// Guion hijo
// ─────────────────────────────────────────────────────────────────────────────
$guion = BASE_PATH . '/herramientas/_barrido_hijo.php';

file_put_contents($guion, <<<'PHP'
<?php
define('BASE_PATH', dirname(__DIR__));

$peticion = json_decode(file_get_contents($argv[1]), true);
$destino  = $argv[2];

$_GET = $peticion['get'];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'barrido-de-rutas';

require_once BASE_PATH . '/init.php';

foreach ($peticion['sesion'] as $k => $v) { $_SESSION[$k] = $v; }

/*
 * Todos los avisos, no solo el último.
 *
 * La primera versión usaba `error_get_last()`, que devuelve UN error: el
 * último. Una página que emitía «Undefined variable $estado_actual» y «Trying
 * to access array offset on null» —ambos visibles en pantalla— se resumía como
 * «0 avisos», y el barrido la daba por buena.
 *
 * Un aviso no es cosmético aquí. Con `display_errors` activo imprime la ruta
 * absoluta del servidor en la página; con `display_errors` apagado, ese mismo
 * fallo es una pantalla a medias sin ninguna explicación.
 */
$GLOBALS['__avisos'] = [];

set_error_handler(function ($tipo, $mensaje, $archivo, $linea) {
    $GLOBALS['__avisos'][] = $mensaje . ' en ' . basename($archivo) . ':' . $linea;
    return false;   // se deja seguir el flujo normal de PHP
});

register_shutdown_function(function () use ($destino) {
    $html = '';
    while (ob_get_level() > 0) { $html = ob_get_clean() . $html; }

    $fatal = error_get_last();
    $grave = $fatal !== null
        && in_array($fatal['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true);

    /*
     * Código PHP saliendo por la página.
     *
     * Si un bloque de PHP se cierra antes de tiempo, el resto del archivo se
     * imprime como texto. La página responde 200, con contenido de sobra y sin
     * error fatal — es decir, pasa todas las demás comprobaciones.
     */
    $fuga = null;
    if (preg_match('/=>\s*[\'"\[]|\$[a-z_]\w*\s*=\s*[\'"\[]|<\?php|ob_start\(\)/i', $html, $m)) {
        $fuga = 'la página imprime código PHP como texto: ' . trim($m[0]);
    }

    file_put_contents($destino, json_encode([
        'html'     => $html,
        'bytes'    => strlen($html),
        'codigo'   => http_response_code(),
        'completo' => !empty($GLOBALS['__completo']),
        'fatal'    => $grave ? $fatal['message'] . ' en ' . $fatal['file'] . ':' . $fatal['line'] : null,
        'fuga'     => $fuga,
        'avisos'   => array_values(array_unique($GLOBALS['__avisos'] ?? [])),
    ]));
});

ob_start();
require BASE_PATH . '/' . $peticion['modulo'];
$GLOBALS['__completo'] = true;
PHP
);

function cargar($modulo, array $sesion, array $get) {
    global $guion;

    $entrada = tempnam(sys_get_temp_dir(), 'bvr');
    $salida  = tempnam(sys_get_temp_dir(), 'bvr');

    file_put_contents($entrada, json_encode([
        'modulo' => $modulo, 'sesion' => $sesion, 'get' => $get,
    ]));

    $consola = shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($guion) . ' '
        . escapeshellarg($entrada) . ' ' . escapeshellarg($salida) . ' 2>&1'
    );

    $r = json_decode((string)@file_get_contents($salida), true);

    @unlink($entrada);
    @unlink($salida);

    if (!is_array($r)) {
        return ['fatal' => 'el proceso murió sin dejar respuesta: ' . trim((string)$consola),
                'bytes' => 0, 'completo' => false, 'avisos' => [], 'codigo' => 0, 'html' => ''];
    }

    return $r;
}

// ─────────────────────────────────────────────────────────────────────────────
// Cuentas desechables, una por rol
// ─────────────────────────────────────────────────────────────────────────────
$marca = 'barrido-' . bin2hex(random_bytes(3));
$creadas = [];

$roles = ['administrador', 'coordinacion_edheg', 'psicologo', 'abogado',
          'instructor', 'decisor', 'unidad_academica', 'talento_humano', 'reportes',
          'comision_seguimiento']; // v3.1 · Res. 142/2026 art. 13

$sesiones = ['(sin sesión)' => []];

foreach ($roles as $rol) {
    $id = db_insert_simple('usuarios', [
        'nombre'     => 'Barrido ' . $rol,
        'email'      => "$rol@$marca.local",
        'password'   => hash_password('BarridoDeRutas2026'),
        'rol'        => $rol,
        'activo'     => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $creadas[] = (int)$id;
    $sesiones[$rol] = ['authenticated' => true, 'user_id' => (int)$id, 'last_activity' => time()];
}

// Un expediente cualquiera para las rutas que piden ?id
$expediente = db_select_one('SELECT id FROM denuncias ORDER BY id LIMIT 1');
$id_ejemplo = $expediente ? (int)$expediente['id'] : 1;

// ─────────────────────────────────────────────────────────────────────────────
// Barrido
// ─────────────────────────────────────────────────────────────────────────────
echo str_repeat('=', 67) . "\n";
echo " BARRIDO DE RUTAS · " . count($rutas) . " rutas × " . count($sesiones) . " perfiles\n";
echo str_repeat('=', 67) . "\n\n";

$fatales = [];
$vacias  = [];
$avisos  = [];
$total   = 0;

foreach ($rutas as $ruta => $modulo) {
    $get = ['page' => $ruta];

    if (in_array($ruta, ['denuncia-ver', 'denuncia-imprimir'], true)) {
        $get['id'] = $id_ejemplo;
    }

    $linea = sprintf('  %-20s ', $ruta);

    foreach ($sesiones as $perfil => $sesion) {
        $total++;
        $r = cargar($modulo, $sesion, $get);

        if (!empty($r['fatal'])) {
            $fatales[] = "$ruta [$perfil]: {$r['fatal']}";
            $linea .= 'X';
            continue;
        }

        // Una fuga de código fuente cuenta como fallo, no como advertencia:
        // la página «funciona» y está enseñando sus tripas.
        if (!empty($r['fuga'])) {
            $fatales[] = "$ruta [$perfil]: {$r['fuga']}";
            $linea .= 'F';
            continue;
        }

        if (!empty($r['avisos'])) {
            $avisos[] = "$ruta [$perfil]: " . implode(' · ', $r['avisos']);
        }

        // Una página que responde 200, termina entera y no emite nada es una
        // pantalla en blanco: cuenta como fallo aunque no haya excepción.
        if ($r['completo'] && $r['bytes'] < 200 && (int)$r['codigo'] < 300) {
            $vacias[] = "$ruta [$perfil]: {$r['bytes']} bytes";
            $linea .= 'o';
            continue;
        }

        $linea .= $r['completo'] ? '.' : '>';
    }

    echo $linea . "\n";
}

echo "\n  Leyenda:  . responde   > redirige   o vacía   X error fatal   F fuga de código\n";

// ─────────────────────────────────────────────────────────────────────────────
// Limpieza
// ─────────────────────────────────────────────────────────────────────────────
foreach ($creadas as $id) {
    db_execute('DELETE FROM asignaciones WHERE usuario_id = ?', [$id]);
    db_execute('DELETE FROM impedimentos WHERE usuario_id = ?', [$id]);
    db_execute('DELETE FROM usuarios WHERE id = ?', [$id]);
}

@unlink($guion);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 67) . "\n";
printf("  %d cargas · %d errores fatales · %d pantallas vacías · %d avisos\n",
    $total, count($fatales), count($vacias), count($avisos));
echo str_repeat('=', 67) . "\n";

foreach ($fatales as $f) { echo "  [FATAL]  $f\n"; }
foreach ($vacias  as $v) { echo "  [VACÍA]  $v\n"; }
foreach (array_slice($avisos, 0, 20) as $a) { echo "  [AVISO]  $a\n"; }

exit(($fatales || $vacias) ? 1 : 0);
