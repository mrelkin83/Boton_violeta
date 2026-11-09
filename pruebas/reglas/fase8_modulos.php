<?php
/**
 * PRUEBAS · FASE 8 — MÓDULOS DE INTERFAZ
 *
 * Cubre los tres módulos que hasta ahora eran un `alert('Próximamente')` o un
 * enlace roto: perfil, alta y edición de cuentas, e impresión de expedientes.
 * Añade el panel de medidas vencidas y el listado de evidencias.
 *
 * ⚠ POR QUÉ CADA RENDERIZADO OCURRE EN UN PROCESO APARTE
 *
 *   `redirect()` llama a `exit`. Un módulo que deniega el acceso mata el
 *   proceso que lo incluye, así que una suite en un solo proceso solo podría
 *   probar el camino feliz — justo el que no importa. Además `auth_user()`
 *   cachea la fila en una estática: dentro de un mismo proceso no se puede
 *   cambiar de usuario.
 *
 *   Cada comprobación arranca un PHP nuevo con una sesión simulada. Es lento
 *   —unos segundos en total— y es la única forma de comprobar de verdad que
 *   una denegación deniega.
 *
 * ⚠ LO QUE ESTA SUITE NO PRUEBA
 *
 *   No abre un navegador. No comprueba que el CSS cargue, ni que el formulario
 *   se vea, ni que se pueda usar con teclado. Comprueba lo que el servidor
 *   decide y lo que emite. Que la pantalla resultante sea usable exige mirarla.
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config.php php pruebas/reglas/fase8_modulos.php
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

// ─────────────────────────────────────────────────────────────────────────────
// Ejecución de un módulo en un proceso hijo
// ─────────────────────────────────────────────────────────────────────────────

/** Script auxiliar que el hijo ejecuta */
$GUION = BASE_PATH . '/pruebas/reglas/_renderizador.php';

file_put_contents($GUION, <<<'PHP'
<?php
/**
 * Renderizador auxiliar de la fase 8 · NO es código de producción
 *
 * Recibe en argv[1] la ruta de un archivo con la petición en JSON y en argv[2]
 * dónde escribir la respuesta.
 *
 * La comunicación va por archivos y no por tuberías porque con tuberías se
 * bloquea: el hijo llena el búfer de una mientras el padre espera leyendo la
 * otra, y ninguno de los dos avanza. El HTML de un expediente basta para
 * llenarlo.
 *
 * Lo genera y lo borra `fase8_modulos.php`. Si lo encuentras suelto en el
 * repositorio, es que una ejecución se interrumpió: puedes borrarlo.
 */
define('BASE_PATH', dirname(__DIR__, 2));

$archivo_entrada = $argv[1];
$archivo_salida  = $argv[2];

$peticion = json_decode(file_get_contents($archivo_entrada), true) ?: [];

$_GET    = $peticion['get']  ?? [];
$_POST   = $peticion['post'] ?? [];
$_SERVER['REQUEST_METHOD'] = $peticion['metodo'] ?? 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'pruebas-fase8';

require_once BASE_PATH . '/init.php';

// Sesión simulada: se escribe después de init.php para que no la pise
foreach ($peticion['sesion'] ?? [] as $clave => $valor) {
    $_SESSION[$clave] = $valor;
}

// El token CSRF se fija a un valor conocido y se refleja en el POST
if (!empty($peticion['post'])) {
    $_SESSION['csrf_token'] = 'token-de-prueba';
    $_POST['csrf_token'] = 'token-de-prueba';
    $_POST['_token'] = 'token-de-prueba';
}

// `redirect()` llama a header() y exit. En CLI header() no falla, pero no
// se puede leer: se intercepta el exit con un manejador de cierre.
$estado = ['salida' => '', 'redireccion' => null, 'flash' => null];

register_shutdown_function(function () use (&$estado, $archivo_salida) {
    $buffer = '';
    while (ob_get_level() > 0) { $buffer = ob_get_clean() . $buffer; }

    $estado['salida'] = $buffer;

    // Cómo se detecta una redirección en CLI
    //
    // `redirect()` hace header() + exit. En la SAPI de consola header() no
    // registra nada —`headers_list()` devuelve un arreglo vacío—, así que no
    // hay cabecera que leer. Lo que sí se puede observar es que el módulo NO
    // llegó a su última línea: `$GLOBALS['__completo']` solo se marca si el
    // require terminó.
    //
    // Terminar antes de tiempo puede ser una redirección o un error fatal, y
    // confundirlos haría que un módulo roto se leyera como «acceso denegado
    // correctamente». Por eso se consulta también error_get_last().
    $fatal = error_get_last();
    $murio_por_error = $fatal !== null
        && in_array($fatal['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true);

    if (empty($GLOBALS['__completo'])) {
        $estado['redireccion'] = $murio_por_error
            ? null
            : ($_SESSION['__destino'] ?? 'salida-temprana');
    }

    // `header()` no guarda nada en consola, pero el código de estado que se le
    // pasa sí queda: es lo único que permite distinguir una redirección de una
    // salida cualquiera. La URL de destino se pierde y no hay forma de
    // recuperarla desde CLI, así que esta suite comprueba QUE redirige, no
    // ADÓNDE.
    $estado['codigo'] = http_response_code();
    $estado['error_fatal'] = $murio_por_error ? $fatal['message'] : null;
    $estado['flash'] = $_SESSION['flash'] ?? null;

    file_put_contents($archivo_salida, json_encode($estado));
});

ob_start();
require BASE_PATH . '/' . $peticion['modulo'];

// Si el módulo redirigió, `exit` impide llegar hasta aquí
$GLOBALS['__completo'] = true;
PHP
);

/**
 * Renderizar un módulo en un proceso aparte
 *
 * @return array ['salida' => string, 'redireccion' => string|null, 'flash' => array|null]
 */
function renderizar_modulo($modulo, array $sesion = [], array $get = [], array $post = []) {
    global $GUION;

    $entrada = tempnam(sys_get_temp_dir(), 'bv8in');
    $salida  = tempnam(sys_get_temp_dir(), 'bv8out');

    file_put_contents($entrada, json_encode([
        'modulo' => $modulo,
        'sesion' => $sesion,
        'get'    => $get,
        'post'   => $post,
        'metodo' => empty($post) ? 'GET' : 'POST',
    ]));

    $orden = escapeshellarg(PHP_BINARY) . ' '
           . escapeshellarg($GUION) . ' '
           . escapeshellarg($entrada) . ' '
           . escapeshellarg($salida) . ' 2>&1';

    $texto_suelto = shell_exec($orden);

    $resultado = json_decode((string)@file_get_contents($salida), true);

    @unlink($entrada);
    @unlink($salida);

    // Un módulo que se estrella no puede pasar por «acceso denegado»: se avisa
    // en voz alta para que nadie lea el informe como si todo estuviera bien.
    if (is_array($resultado) && !empty($resultado['error_fatal'])) {
        echo "  [!!!]   $modulo provocó un error fatal: {$resultado['error_fatal']}\n";
    }

    if (!is_array($resultado)) {
        // El hijo murió antes de escribir. Lo que haya dicho por consola es la
        // única pista, y se devuelve tal cual en vez de fingir una respuesta
        // vacía: una prueba que no distingue «denegado» de «se estrelló» no
        // sirve para nada.
        return ['salida' => (string)$texto_suelto, 'redireccion' => null,
                'flash' => null, 'estrellado' => true];
    }

    return $resultado;
}

/** Sesión de una persona autenticada */
function sesion_de($usuario_id) {
    return [
        'authenticated' => true,
        'user_id'       => (int)$usuario_id,
        'last_activity' => time(),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Cuentas y expedientes desechables
// ─────────────────────────────────────────────────────────────────────────────

$marca = 'f8-' . bin2hex(random_bytes(4));

/**
 * Huella de las contraseñas ANTES de empezar
 *
 * Una suite anterior de este proyecto tomó «el primer usuario activo»
 * —resultó ser la cuenta real de administración— y le cambió la contraseña.
 * Se comprueba al final que ninguna cuenta ajena a la prueba fue tocada.
 */
$huella_inicial = db_select("SELECT id, password FROM usuarios WHERE email NOT LIKE '%@$marca.local'") ?: [];
$huella_inicial = array_column($huella_inicial, 'password', 'id');

$creados     = [];
$expedientes = [];

// Barrido de restos de ejecuciones anteriores
//
// Si una corrida se interrumpe a mitad —un tiempo de espera agotado, un corte—
// deja cuentas y expedientes suyos en la base. La siguiente corrida los
// encontraba al comprobar la limpieza final y se declaraba fallida por culpa
// de la anterior. Se barren antes de empezar y se dice cuántos había.
$restos = db_select("SELECT id FROM denuncias WHERE codigo LIKE 'BV-F8-%'") ?: [];

foreach ($restos as $resto) {
    db_execute('DELETE FROM denuncias WHERE id = ?', [$resto['id']]);
}

$cuentas_restantes = db_select("SELECT id FROM usuarios WHERE email LIKE '%@f8-%.local'") ?: [];

foreach ($cuentas_restantes as $resto) {
    db_execute('DELETE FROM impedimentos WHERE usuario_id = ?', [$resto['id']]);
    db_execute('DELETE FROM asignaciones WHERE usuario_id = ?', [$resto['id']]);
    db_execute('DELETE FROM usuarios WHERE id = ?', [$resto['id']]);
}

if ($restos || $cuentas_restantes) {
    echo 'Restos de ejecuciones anteriores barridos: '
       . count($restos) . " expedientes, " . count($cuentas_restantes) . " cuentas\n";
}

function crear_cuenta($rol, $nombre, $contrasena = 'PruebaFase8Larga1') {
    global $marca, $creados;

    $correo = strtolower(str_replace(' ', '.', $nombre)) . "@$marca.local";

    $id = db_insert_simple('usuarios', [
        'nombre'     => $nombre,
        'email'      => $correo,
        'password'   => hash_password($contrasena),
        'rol'        => $rol,
        'activo'     => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $creados[] = (int)$id;

    return ['id' => (int)$id, 'email' => $correo, 'password' => $contrasena];
}

$admin      = crear_cuenta('administrador', 'Admin Fase8');
$admin_dos  = crear_cuenta('administrador', 'Admin Suplente');
$psicologa  = crear_cuenta('psicologo', 'Psico Fase8');
$ajena      = crear_cuenta('psicologo', 'Psico Ajena');
$reportes   = crear_cuenta('reportes', 'Reportes Fase8');

// Expediente con la psicóloga asignada
$expediente = db_insert_simple('denuncias', [
    'codigo'         => 'BV-F8-' . strtoupper(bin2hex(random_bytes(3))),
    'tipo_violencia' => 'psicologica',
    'descripcion'    => 'RELATO-CENTINELA-FASE8 que no debe aparecer donde no corresponde.',
    'fecha_hechos'   => date('Y-m-d'),
    'lugar_hechos'   => 'Sede de prueba',
    'estado'         => 'nueva',
    'etapa_procesal' => 'investigacion',
    'usuario_id'     => $admin['id'],
    'created_at'     => date('Y-m-d H:i:s'),
]);
$expedientes[] = (int)$expediente;

db_insert_simple('asignaciones', [
    'denuncia_id' => (int)$expediente,
    'usuario_id'  => $psicologa['id'],
    'funcion'     => 'psicologia',
    'activa'      => 1,
    'created_at'  => date('Y-m-d H:i:s'),
]);

$radicado = db_select_one('SELECT codigo FROM denuncias WHERE id = ?', [$expediente])['codigo'];

// Persona del caso, con contacto: se comprobará que NO se imprime
registrar_persona_caso((int)$expediente, 'afectada', [
    'nombre'   => 'Persona Centinela',
    'correo'   => "centinela@$marca.local",
    'telefono' => '3001234567',
]);

echo "Cuentas y expediente de prueba creados (marca: $marca)\n";

// ═════════════════════════════════════════════════════════════════════════════
seccion('A · MI PERFIL');
// ═════════════════════════════════════════════════════════════════════════════

$r = renderizar_modulo('modules/perfil/index.php', sesion_de($psicologa['id']));

chequear('el módulo de perfil responde sin redirigir',
    $r['redireccion'] === null, (string)$r['redireccion']);

chequear('muestra el nombre de la persona autenticada',
    strpos($r['salida'], 'Psico Fase8') !== false);

chequear('muestra su correo institucional',
    strpos($r['salida'], $psicologa['email']) !== false);

chequear('NO ofrece un selector de rol: nadie se asciende a sí mismo',
    strpos($r['salida'], 'name="rol"') === false);

chequear('los formularios llevan token CSRF',
    substr_count($r['salida'], 'csrf') >= 3 || substr_count($r['salida'], '_token') >= 3);

chequear('lista el expediente asignado',
    strpos($r['salida'], $radicado) !== false);

chequear('NO imprime el relato en el perfil',
    strpos($r['salida'], 'RELATO-CENTINELA-FASE8') === false);

// Sin sesión
$r = renderizar_modulo('modules/perfil/index.php', []);

chequear('sin sesión no se muestra el perfil',
    strpos($r['salida'], 'Cambiar contraseña') === false);

chequear('sin sesión responde con una redirección 3xx',
    $r['redireccion'] !== null && (int)($r['codigo'] ?? 0) >= 300 && (int)$r['codigo'] < 400,
    'código ' . ($r['codigo'] ?? '?'));

seccion('A.2 · CAMBIO DE CONTRASEÑA');

function hash_actual($id) {
    $f = db_select_one('SELECT password FROM usuarios WHERE id = ?', [$id]);
    return $f ? $f['password'] : null;
}

$antes = hash_actual($psicologa['id']);

$casos_rechazo = [
    'contraseña actual incorrecta' => [
        'contrasena_actual'    => 'EstaNoEsLaSuya1',
        'contrasena_nueva'     => 'NuevaValida2026x',
        'contrasena_confirmar' => 'NuevaValida2026x',
    ],
    'contraseña nueva demasiado corta' => [
        'contrasena_actual'    => $psicologa['password'],
        'contrasena_nueva'     => 'Corta1',
        'contrasena_confirmar' => 'Corta1',
    ],
    'confirmación que no coincide' => [
        'contrasena_actual'    => $psicologa['password'],
        'contrasena_nueva'     => 'NuevaValida2026x',
        'contrasena_confirmar' => 'OtraDistinta2026x',
    ],
    'contraseña sin números' => [
        'contrasena_actual'    => $psicologa['password'],
        'contrasena_nueva'     => 'SoloLetrasSinDigito',
        'contrasena_confirmar' => 'SoloLetrasSinDigito',
    ],
    'contraseña nueva igual a la actual' => [
        'contrasena_actual'    => $psicologa['password'],
        'contrasena_nueva'     => $psicologa['password'],
        'contrasena_confirmar' => $psicologa['password'],
    ],
];

foreach ($casos_rechazo as $nombre => $datos) {
    $r = renderizar_modulo('modules/perfil/index.php', sesion_de($psicologa['id']), [],
        array_merge(['accion' => 'cambiar_contrasena'], $datos));

    chequear("rechaza: $nombre", hash_actual($psicologa['id']) === $antes);
}

// Ahora una válida
$r = renderizar_modulo('modules/perfil/index.php', sesion_de($psicologa['id']), [], [
    'accion'               => 'cambiar_contrasena',
    'contrasena_actual'    => $psicologa['password'],
    'contrasena_nueva'     => 'ContrasenaNueva2026',
    'contrasena_confirmar' => 'ContrasenaNueva2026',
]);

$despues = hash_actual($psicologa['id']);

chequear('acepta un cambio válido: el hash cambia', $despues !== $antes);
chequear('la contraseña nueva verifica', verify_password('ContrasenaNueva2026', $despues));
chequear('la contraseña anterior deja de servir', !verify_password($psicologa['password'], $despues));

$psicologa['password'] = 'ContrasenaNueva2026';

chequear('el cambio queda en la auditoría',
    (int)(db_select_one("SELECT COUNT(*) t FROM auditoria WHERE accion = 'contrasena_cambiada' AND usuario_id = ?",
        [$psicologa['id']])['t'] ?? 0) >= 1);

chequear('el intento fallido también queda en la auditoría',
    (int)(db_select_one("SELECT COUNT(*) t FROM auditoria WHERE accion = 'cambio_contrasena_fallido' AND usuario_id = ?",
        [$psicologa['id']])['t'] ?? 0) >= 1);

seccion('A.3 · DECLARACIÓN DE IMPEDIMENTO · RN-12');

// Sobre un expediente que NO tiene asignado
$r = renderizar_modulo('modules/perfil/index.php', sesion_de($ajena['id']), [], [
    'accion'      => 'declarar_impedimento',
    'denuncia_id' => (int)$expediente,
]);

chequear('no se puede declarar impedimento sobre un expediente ajeno',
    (int)(db_select_one('SELECT COUNT(*) t FROM impedimentos WHERE usuario_id = ? AND denuncia_id = ?',
        [$ajena['id'], $expediente])['t'] ?? 0) === 0);

// Sobre el suyo
chequear('antes de declararlo, la psicóloga ve el expediente',
    puede_ver_expediente($psicologa['id'], 'psicologo', (int)$expediente));

$r = renderizar_modulo('modules/perfil/index.php', sesion_de($psicologa['id']), [], [
    'accion'      => 'declarar_impedimento',
    'denuncia_id' => (int)$expediente,
]);

chequear('el impedimento queda registrado',
    (int)(db_select_one('SELECT COUNT(*) t FROM impedimentos WHERE usuario_id = ? AND denuncia_id = ?',
        [$psicologa['id'], $expediente])['t'] ?? 0) === 1);

chequear('tras declararlo, pierde el acceso al expediente',
    !puede_ver_expediente($psicologa['id'], 'psicologo', (int)$expediente));

// ═════════════════════════════════════════════════════════════════════════════
seccion('B · ALTA Y EDICIÓN DE CUENTAS');
// ═════════════════════════════════════════════════════════════════════════════

$r = renderizar_modulo('modules/usuarios/editar.php', sesion_de($reportes['id']));
chequear('un rol sin permiso es rechazado',
    $r['redireccion'] !== null, 'no redirigió');

$r = renderizar_modulo('modules/usuarios/editar.php', sesion_de($admin['id']));
chequear('administración accede al formulario',
    $r['redireccion'] === null && strpos($r['salida'], 'Crear cuenta') !== false);

chequear('el formulario NO ofrece el rol `admin` heredado',
    substr_count($r['salida'], 'value="admin"') === 0);

// Alta correcta · v3.1 RN-15: institucional del dominio + personal obligatorio
$correo_nuevo = "nueva.cuenta.$marca@udistrital.edu.co";

// Primero, el camino que la v3.1 prohíbe: alta SIN correo personal
$antes_alta = (int)db_count('usuarios');
renderizar_modulo('modules/usuarios/editar.php', sesion_de($admin['id']), [], [
    'nombre'    => 'Cuenta Sin Personal',
    'email'     => $correo_nuevo,
    'rol'       => 'abogado',
    'activo'    => 1,
    'unidad_id' => 0,
]);
chequear('un alta sin correo personal se rechaza · RN-15',
    (int)db_count('usuarios') === $antes_alta);

$r = renderizar_modulo('modules/usuarios/editar.php', sesion_de($admin['id']), [], [
    'nombre'    => 'Cuenta Nueva',
    'email'     => $correo_nuevo,
    'correo_personal' => "nueva.personal@$marca.local",
    'rol'       => 'abogado',
    'activo'    => 1,
    'unidad_id' => 0,
]);

$nueva = db_select_one('SELECT * FROM usuarios WHERE email = ?', [$correo_nuevo]);
if ($nueva) { $creados[] = (int)$nueva['id']; }

chequear('la cuenta se crea', $nueva !== false && $nueva !== null);
chequear('con el rol indicado', $nueva && $nueva['rol'] === 'abogado');

// La contraseña temporal se muestra una sola vez y debe ser la que quedó guardada
if (preg_match('/([2-9A-HJ-NP-Za-km-z]{5}-[2-9A-HJ-NP-Za-km-z]{5}-[2-9A-HJ-NP-Za-km-z]{5})/',
    $r['salida'], $m)) {
    chequear('muestra una contraseña temporal con el formato esperado', true);
    chequear('la contraseña mostrada es la que verifica contra el hash guardado',
        $nueva && verify_password($m[1], $nueva['password']));
    chequear('la contraseña temporal no contiene caracteres ambiguos',
        !preg_match('/[0O1lI]/', $m[1]), $m[1]);
} else {
    chequear('muestra una contraseña temporal con el formato esperado', false, 'no se encontró');
    chequear('la contraseña mostrada es la que verifica contra el hash guardado', false, 'sin contraseña');
    chequear('la contraseña temporal no contiene caracteres ambiguos', false, 'sin contraseña');
}

chequear('la contraseña temporal NO queda en la auditoría',
    (int)(db_select_one(
        "SELECT COUNT(*) t FROM auditoria WHERE accion = 'usuario_creado' AND datos LIKE '%-%-%' AND datos LIKE '%password%'"
    )['t'] ?? 0) === 0);

// Correo duplicado
$antes_total = (int)db_count('usuarios');

$r = renderizar_modulo('modules/usuarios/editar.php', sesion_de($admin['id']), [], [
    'nombre'    => 'Duplicada',
    'email'     => $correo_nuevo,
    'correo_personal' => "duplicada.personal@$marca.local",
    'rol'       => 'abogado',
    'activo'    => 1,
    'unidad_id' => 0,
]);

chequear('rechaza un correo ya registrado', (int)db_count('usuarios') === $antes_total);

// unidad_academica sin unidad
$antes_total = (int)db_count('usuarios');

$r = renderizar_modulo('modules/usuarios/editar.php', sesion_de($admin['id']), [], [
    'nombre'    => 'Sin Unidad',
    'email'     => "sin.unidad.$marca@udistrital.edu.co",
    'correo_personal' => "sin.unidad.personal@$marca.local",
    'rol'       => 'unidad_academica',
    'activo'    => 1,
    'unidad_id' => 0,
]);

chequear('rechaza una cuenta de unidad académica sin unidad asignada · RN-08',
    (int)db_count('usuarios') === $antes_total);

seccion('B.2 · GUARDAS SOBRE LA PROPIA CUENTA');

$r = renderizar_modulo('modules/usuarios/editar.php', sesion_de($admin['id']),
    ['id' => $admin['id']], [
        'nombre'    => 'Admin Fase8',
        'email'     => $admin['email'],
        'rol'       => 'reportes',   // degradarse
        'activo'    => 1,
        'unidad_id' => 0,
    ]);

$comprobar = db_select_one('SELECT rol, activo FROM usuarios WHERE id = ?', [$admin['id']]);
chequear('no puede cambiar su propio rol', $comprobar['rol'] === 'administrador', $comprobar['rol']);

$r = renderizar_modulo('modules/usuarios/editar.php', sesion_de($admin['id']),
    ['id' => $admin['id']], [
        'nombre'    => 'Admin Fase8',
        'email'     => $admin['email'],
        'rol'       => 'administrador',
        'activo'    => 0,            // desactivarse
        'unidad_id' => 0,
    ]);

$comprobar = db_select_one('SELECT activo FROM usuarios WHERE id = ?', [$admin['id']]);
chequear('no puede desactivar su propia cuenta', (int)$comprobar['activo'] === 1);

seccion('B.3 · LA ÚLTIMA CUENTA ADMINISTRADORA');

// Se desactivan todas las administraciones salvo `admin_dos`, y se intenta
// degradarla desde `admin`. Como `admin` sigue activa, debe permitirlo; luego
// se deja solo una y se comprueba que ya no.
$otras_admin = db_select(
    "SELECT id FROM usuarios WHERE rol IN ('admin','administrador') AND activo = 1
       AND id NOT IN (?, ?)",
    [$admin['id'], $admin_dos['id']]
) ?: [];

foreach ($otras_admin as $fila) {
    db_update_simple('usuarios', ['activo' => 0], ['id' => $fila['id']]);
}

$r = renderizar_modulo('modules/usuarios/editar.php', sesion_de($admin['id']),
    ['id' => $admin_dos['id']], [
        'nombre'    => 'Admin Suplente',
        'email'     => $admin_dos['email'],
        'rol'       => 'reportes',
        'activo'    => 1,
        'unidad_id' => 0,
    ]);

$comprobar = db_select_one('SELECT rol FROM usuarios WHERE id = ?', [$admin_dos['id']]);
chequear('con dos administraciones activas, sí se puede degradar una',
    $comprobar['rol'] === 'reportes', $comprobar['rol']);

// Ahora `admin` es la única. Se intenta degradarla desde otra sesión admin...
// no hay otra: se prueba el camino que sí es alcanzable, desde ella misma,
// que además choca con la guarda de rol propio. Se crea una tercera para
// poder aislar la regla del último administrador.
$admin_tres = crear_cuenta('administrador', 'Admin Tercera');

$r = renderizar_modulo('modules/usuarios/editar.php', sesion_de($admin_tres['id']),
    ['id' => $admin['id']], [
        'nombre'    => 'Admin Fase8',
        'email'     => $admin['email'],
        'rol'       => 'reportes',
        'activo'    => 1,
        'unidad_id' => 0,
    ]);

$comprobar = db_select_one('SELECT rol FROM usuarios WHERE id = ?', [$admin['id']]);
chequear('con dos activas todavía se puede degradar la otra',
    $comprobar['rol'] === 'reportes', $comprobar['rol']);

// Queda `admin_tres` sola: degradarla debe fallar
$r = renderizar_modulo('modules/usuarios/editar.php', sesion_de($admin_tres['id']),
    ['id' => $admin_tres['id']], [
        'nombre'    => 'Admin Tercera',
        'email'     => $admin_tres['email'],
        'rol'       => 'administrador',
        'activo'    => 0,
        'unidad_id' => 0,
    ]);

$comprobar = db_select_one('SELECT rol, activo FROM usuarios WHERE id = ?', [$admin_tres['id']]);
chequear('la última cuenta administradora activa no puede desactivarse',
    (int)$comprobar['activo'] === 1 && $comprobar['rol'] === 'administrador');

seccion('B.4 · LISTADO DE CUENTAS');

$r = renderizar_modulo('modules/usuarios/index.php', sesion_de($admin_tres['id']));

chequear('el listado ofrece «Nuevo Usuario» como enlace, no como alert()',
    strpos($r['salida'], 'page=usuario-editar') !== false
    && strpos($r['salida'], 'Próximamente') === false);

chequear('cada fila ofrece «Editar»', substr_count($r['salida'], '&amp;id=') >= 1);

chequear('el filtro de roles incluye los del modelo institucional',
    strpos($r['salida'], 'coordinacion_edheg') !== false
    && strpos($r['salida'], 'unidad_academica') !== false);

chequear('el filtro ya no ofrece el rol `consulta`, que no existe en el esquema',
    strpos($r['salida'], 'value="consulta"') === false);

// Desactivarse a uno mismo por POST directo
$r = renderizar_modulo('modules/usuarios/index.php', sesion_de($admin_tres['id']), [], [
    'toggle_active' => 1,
    'user_id'       => $admin_tres['id'],
    'nuevo_estado'  => 0,
]);

$comprobar = db_select_one('SELECT activo FROM usuarios WHERE id = ?', [$admin_tres['id']]);
chequear('un POST directo no permite desactivarse a uno mismo',
    (int)$comprobar['activo'] === 1);

// ═════════════════════════════════════════════════════════════════════════════
seccion('C · IMPRESIÓN DEL EXPEDIENTE');
// ═════════════════════════════════════════════════════════════════════════════

// La psicóloga se declaró impedida: ya no debe poder imprimirlo
$r = renderizar_modulo('modules/denuncias/imprimir.php', sesion_de($psicologa['id']),
    ['id' => (int)$expediente]);

chequear('quien se declaró impedido no puede imprimir el expediente',
    $r['redireccion'] !== null, 'no redirigió');

chequear('y el relato no aparece por ninguna parte de esa respuesta',
    strpos($r['salida'], 'RELATO-CENTINELA-FASE8') === false);

// Un rol sin acceso a expedientes
$r = renderizar_modulo('modules/denuncias/imprimir.php', sesion_de($reportes['id']),
    ['id' => (int)$expediente]);

chequear('el rol `reportes` no puede imprimir un expediente',
    $r['redireccion'] !== null);

chequear('el intento denegado queda auditado',
    (int)(db_select_one("SELECT COUNT(*) t FROM auditoria WHERE accion = 'impresion_expediente_denegada'")['t'] ?? 0) >= 1);

// Alguien con competencia: se asigna una abogada
$abogada = crear_cuenta('abogado', 'Abogada Fase8');

db_insert_simple('asignaciones', [
    'denuncia_id' => (int)$expediente,
    'usuario_id'  => $abogada['id'],
    'funcion'     => 'juridica',
    'activa'      => 1,
    'created_at'  => date('Y-m-d H:i:s'),
]);

$r = renderizar_modulo('modules/denuncias/imprimir.php', sesion_de($abogada['id']),
    ['id' => (int)$expediente]);

chequear('quien tiene competencia sí obtiene el documento',
    $r['redireccion'] === null && strpos($r['salida'], $radicado) !== false);

chequear('el documento lleva el relato para quien puede verlo',
    strpos($r['salida'], 'RELATO-CENTINELA-FASE8') !== false);

chequear('lleva marca de agua con el nombre de quien imprime',
    strpos($r['salida'], 'Abogada Fase8') !== false);

chequear('advierte que es un documento reservado',
    stripos($r['salida'], 'reservado') !== false);

chequear('NO imprime el correo de la persona afectada · RN-05',
    strpos($r['salida'], "centinela@$marca.local") === false);

chequear('NO imprime su teléfono · RN-05',
    strpos($r['salida'], '3001234567') === false);

chequear('sí nombra a la persona del caso',
    strpos($r['salida'], 'Persona Centinela') !== false);

chequear('no arrastra el menú lateral de la aplicación',
    strpos($r['salida'], 'id="sidebar"') === false
    && strpos($r['salida'], 'Cerrar sesión') === false);

chequear('no arrastra el botón de salida rápida',
    stripos($r['salida'], 'salida_rapida') === false);

chequear('lleva su propia hoja de estilo embebida, sin depender del CDN',
    strpos($r['salida'], '<style>') !== false);

chequear('la impresión queda auditada',
    (int)(db_select_one("SELECT COUNT(*) t FROM auditoria WHERE accion = 'expediente_impreso' AND usuario_id = ?",
        [$abogada['id']])['t'] ?? 0) >= 1);

chequear('el expediente inexistente responde igual que el prohibido',
    renderizar_modulo('modules/denuncias/imprimir.php', sesion_de($abogada['id']),
        ['id' => 999999])['redireccion'] !== null);

// ═════════════════════════════════════════════════════════════════════════════
seccion('D · DASHBOARD · ALCANCE DE LA PORTADA');
// ═════════════════════════════════════════════════════════════════════════════

// Una psicóloga sin ninguna asignación no debe ver radicados ajenos
$sin_casos = crear_cuenta('psicologo', 'Psico Sin Casos');

$r = renderizar_modulo('modules/dashboard/index.php', sesion_de($sin_casos['id']));

chequear('la portada carga para un rol sin expedientes',
    $r['redireccion'] === null);

chequear('sin asignaciones NO aparece ningún radicado ajeno · RN-02',
    strpos($r['salida'], $radicado) === false);

chequear('y desde luego ningún relato',
    strpos($r['salida'], 'RELATO-CENTINELA-FASE8') === false);

// La abogada asignada sí debe verlo
$r = renderizar_modulo('modules/dashboard/index.php', sesion_de($abogada['id']));

chequear('con asignación sí aparece su expediente',
    strpos($r['salida'], $radicado) !== false);

chequear('la portada tampoco imprime el relato',
    strpos($r['salida'], 'RELATO-CENTINELA-FASE8') === false);

seccion('D.2 · LA PORTADA NO OFRECE LO QUE LUEGO NIEGA');

/*
 * Un botón que rebota es peor que un botón ausente: parece un fallo del sistema
 * y no una decisión. Se comprueba que cada acción rápida ofrecida conduzca a un
 * módulo que ese rol puede realmente abrir.
 */
$portada_por_rol = [
    'administrador' => $admin_tres['id'],
    'abogado'       => $abogada['id'],
    'reportes'      => $reportes['id'],
];

foreach ($portada_por_rol as $rol => $id_usuario) {
    $portada = renderizar_modulo('modules/dashboard/index.php', sesion_de($id_usuario));

    foreach (['denuncia-nueva' => 'modules/denuncias/nueva.php',
              'usuarios'       => 'modules/usuarios/index.php'] as $destino => $modulo) {

        if (strpos($portada['salida'], "page=$destino") === false) {
            continue;   // no lo ofrece: nada que comprobar
        }

        $r = renderizar_modulo($modulo, sesion_de($id_usuario));

        // Llaves obligatorias: «» son multibyte y PHP se traga el cierre dentro
        // del nombre de la variable, dejando el mensaje sin el dato
        chequear("$rol: la portada ofrece «{$destino}» y el módulo lo admite",
            $r['redireccion'] === null,
            'ofrecido pero denegado');
    }
}

// ═════════════════════════════════════════════════════════════════════════════
seccion('E · EVIDENCIAS EN EL EXPEDIENTE');
// ═════════════════════════════════════════════════════════════════════════════

$r = renderizar_modulo('modules/denuncias/ver.php', sesion_de($abogada['id']),
    ['id' => (int)$expediente]);

chequear('la vista del expediente incluye la sección de evidencias',
    stripos($r['salida'], 'Evidencias') !== false);

chequear('indica que no hay archivos cuando no los hay',
    stripos($r['salida'], 'No hay archivos adjuntos') !== false);

chequear('«Imprimir expediente» es un enlace real, no un alert()',
    strpos($r['salida'], 'page=denuncia-imprimir') !== false
    && stripos($r['salida'], 'próximamente') === false);

// ═════════════════════════════════════════════════════════════════════════════
seccion('F · format_bytes()');
// ═════════════════════════════════════════════════════════════════════════════

chequear('0 bytes',        format_bytes(0) === '0 B',        format_bytes(0));
chequear('1023 bytes',     format_bytes(1023) === '1023 B',  format_bytes(1023));
chequear('1024 bytes → KB', strpos(format_bytes(1024), 'KB') !== false, format_bytes(1024));
chequear('10 MB legible',  strpos(format_bytes(10485760), 'MB') !== false, format_bytes(10485760));
chequear('nulo no revienta', format_bytes(null) === '0 B', (string)format_bytes(null));

// ═════════════════════════════════════════════════════════════════════════════
seccion('G · LIMPIEZA Y NO INTERFERENCIA');
// ═════════════════════════════════════════════════════════════════════════════

foreach ($expedientes as $id) {
    db_execute('DELETE FROM personas_caso WHERE denuncia_id = ?', [$id]);
    db_execute('DELETE FROM asignaciones WHERE denuncia_id = ?', [$id]);
    db_execute('DELETE FROM impedimentos WHERE denuncia_id = ?', [$id]);
    db_execute('DELETE FROM seguimientos WHERE denuncia_id = ?', [$id]);
    db_execute('DELETE FROM denuncias WHERE id = ?', [$id]);
}

foreach (array_unique($creados) as $id) {
    db_execute('DELETE FROM impedimentos WHERE usuario_id = ?', [$id]);
    db_execute('DELETE FROM asignaciones WHERE usuario_id = ?', [$id]);
    db_execute('DELETE FROM usuarios WHERE id = ?', [$id]);
}

// v3.1: las altas del formulario ahora usan correo del dominio institucional
db_execute("DELETE FROM usuarios WHERE email LIKE ?", ['%.' . $marca . '@udistrital.edu.co']);

// Se reactiva lo que la sección B.3 desactivó
foreach ($otras_admin as $fila) {
    db_update_simple('usuarios', ['activo' => 1], ['id' => $fila['id']]);
}

chequear('las cuentas de prueba se eliminan',
    (int)(db_select_one("SELECT COUNT(*) t FROM usuarios WHERE email LIKE '%@$marca.local'")['t'] ?? 0) === 0);

chequear('los expedientes de prueba se eliminan',
    (int)(db_select_one("SELECT COUNT(*) t FROM denuncias WHERE codigo LIKE 'BV-F8-%'")['t'] ?? 0) === 0);

$huella_final = db_select("SELECT id, password FROM usuarios") ?: [];
$huella_final = array_column($huella_final, 'password', 'id');

$alteradas = 0;
foreach ($huella_inicial as $id => $hash) {
    if (isset($huella_final[$id]) && $huella_final[$id] !== $hash) { $alteradas++; }
}

chequear('ninguna cuenta preexistente cambió de contraseña', $alteradas === 0,
    "$alteradas alteradas");

$reactivadas = db_select_one(
    "SELECT COUNT(*) t FROM usuarios WHERE rol IN ('admin','administrador') AND activo = 1"
);
chequear('el sistema conserva al menos una cuenta administradora activa',
    (int)($reactivadas['t'] ?? 0) >= 1);

@unlink($GUION);
chequear('el renderizador auxiliar se borra', !is_file($GUION));

// ═════════════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('-', 60) . "\n";

if ($fallidas === 0) {
    echo "TODAS LAS COMPROBACIONES PASARON: $correctas correctas, 0 fallidas\n";
    exit(0);
}

echo "RESULTADO: $correctas correctas, $fallidas FALLIDAS\n";
exit(1);
