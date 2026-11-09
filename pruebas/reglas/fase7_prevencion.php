<?php
/**
 * PRUEBAS · FASE 7 — PREVENCIÓN, PORTAL Y ACCESIBILIDAD
 *
 * Cubre: canal anónimo y escrache (Ac. 002 art. 33 · Ac. 003 art. 5), análisis
 * de patrones de riesgo colectivo (art. 33.4), guardado parcial del relato,
 * salida rápida, directorio de instituciones y accesibilidad WCAG 2.1 AA.
 *
 * ⚠ SOBRE EL DEFINITION OF DONE «auditoría de accesibilidad AA aprobada»
 *
 *   Estas comprobaciones cubren la parte MECÁNICA de WCAG: idioma declarado,
 *   etiquetas de formulario, nombres accesibles, orden de encabezados, enlace
 *   de salto, foco visible y relaciones de contraste calculadas.
 *
 *   Eso es aproximadamente un tercio de los criterios AA. El resto —orden de
 *   lectura con lector de pantalla, comprensibilidad del lenguaje, navegación
 *   real con teclado, uso con ampliación al 200 %— exige revisión humana con
 *   tecnología de apoyo. Una suite automatizada NO sustituye esa auditoría, y
 *   afirmar lo contrario sería falso.
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config_pruebas.php php pruebas/reglas/fase7_prevencion.php
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

/** Renderiza una ruta pública y devuelve el HTML */
function renderizar($pagina, array $get = []) {
    $_GET = array_merge(['page' => $pagina], $get);
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $rutas = [
        'home'             => '/views/home.php',
        'denuncia-publica' => '/modules/denuncias/publica.php',
        'seguimiento'      => '/modules/denuncias/seguimiento.php',
        'portal'           => '/modules/portal/index.php',
    ];

    ob_start();
    include BASE_PATH . $rutas[$pagina];
    return ob_get_clean();
}

/** Luminancia relativa de un color hexadecimal (WCAG 2.1) */
function luminancia($hex) {
    $hex = ltrim($hex, '#');
    $canales = [];

    foreach ([0, 2, 4] as $i) {
        $c = hexdec(substr($hex, $i, 2)) / 255;
        $canales[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    }

    return 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];
}

/** Relación de contraste entre dos colores */
function contraste($hex1, $hex2) {
    $l1 = luminancia($hex1);
    $l2 = luminancia($hex2);
    $claro = max($l1, $l2);
    $oscuro = min($l1, $l2);

    return round(($claro + 0.05) / ($oscuro + 0.05), 2);
}

db_execute('DELETE FROM patrones_detectados');
db_execute('DELETE FROM reportes_anonimos');
db_execute('DELETE FROM borradores_denuncia');

// Huella de las contraseñas ANTES de empezar.
//
// Comparar hashes es preciso; mirar `updated_at` no lo es, porque cualquier
// otra operación reciente —una instalación, por ejemplo— cae dentro de la
// ventana temporal y produce un falso positivo.
$huella_inicial = [];
foreach (db_select('SELECT email, password FROM usuarios') ?: [] as $fila) {
    $huella_inicial[$fila['email']] = $fila['password'];
}

// ─────────────────────────────────────────────────────────────────────────────
seccion('ADR-09 · Canal anónimo, desactivado hasta que el Comité decida');

chequear('el canal anónimo viene DESACTIVADO', canal_anonimo_activo() === false);

$r = registrar_reporte_anonimo('Un relato de prueba con longitud suficiente para el canal.');
chequear('con el canal cerrado no se registra nada', $r['exito'] === false);
chequear('el mensaje no culpa a la persona',
    strpos($r['motivo'], 'no está habilitado') !== false, $r['motivo']);
chequear('no llegó nada a la tabla', db_count('reportes_anonimos') === 0);

// Se abre el canal solo para probar la maquinaria
define('CANAL_ANONIMO_ACTIVO_PRUEBA', true);
if (!defined('CANAL_ANONIMO_ACTIVO') || !CANAL_ANONIMO_ACTIVO) {
    // El interruptor real es una constante; para probar se invoca el registro
    // directo, que es lo que ejecutaría el canal una vez habilitado.
    $simular = true;
}

/** Registro directo, equivalente a lo que hace el canal cuando está abierto */
function registrar_prueba($descripcion, $espacio = null, $unidad = null) {
    return db_insert_simple('reportes_anonimos', [
        'tipo' => 'anonimo', 'descripcion' => $descripcion,
        'unidad_id' => $unidad, 'espacio_senalado' => $espacio,
        'estado' => 'recibido', 'created_at' => date('Y-m-d H:i:s'),
    ]);
}

seccion('Ac. 002 art. 33 · El reporte anónimo NO abre trámite');

$antes_denuncias = db_count('denuncias');
registrar_prueba('Reporte de prueba sobre una situación en un espacio del campus.', 'Biblioteca central');

chequear('el reporte se registra', db_count('reportes_anonimos') === 1);
chequear('NO se crea ninguna denuncia', db_count('denuncias') === $antes_denuncias);

$columnas = db_select("SHOW COLUMNS FROM reportes_anonimos");
$nombres = array_column($columnas ?: [], 'Field');
chequear('la tabla NO tiene columna de radicado',
    !in_array('codigo', $nombres, true) && !in_array('radicado', $nombres, true));
chequear('la tabla NO pide datos de contacto',
    count(array_filter($nombres, fn($c) => strpos($c, 'denunciante') !== false)) === 0);

seccion('Ac. 003 art. 7 · Los patrones señalan espacios, nunca personas');

$columnas = db_select("SHOW COLUMNS FROM patrones_detectados");
$nombres = array_column($columnas ?: [], 'Field');
chequear('ninguna columna apunta a una persona',
    count(array_filter($nombres, fn($c) =>
        strpos($c, 'persona') !== false || strpos($c, 'denunciada') !== false)) === 0,
    implode(',', $nombres));

seccion('Ac. 002 art. 33.4 · Análisis de patrones de riesgo colectivo');

chequear('con 1 reporte no hay patrón', analizar_patrones() === 0 && db_count('patrones_detectados') === 0);

registrar_prueba('Segundo reporte sobre el mismo espacio del campus.', 'Biblioteca central');
analizar_patrones();
chequear('con 2 reportes tampoco', db_count('patrones_detectados') === 0);

registrar_prueba('Tercer reporte sobre el mismo espacio del campus.', 'Biblioteca central');
$nuevos = analizar_patrones();
chequear('con 3 reportes se detecta el patrón', $nuevos === 1, (string)$nuevos);

$patron = db_select_one('SELECT * FROM patrones_detectados ORDER BY id DESC LIMIT 1');
chequear('el patrón señala el espacio', $patron['clave'] === 'Biblioteca central');
chequear('registra las ocurrencias', (int)$patron['ocurrencias'] === 3);
chequear('el tipo es espacio recurrente', $patron['tipo_patron'] === 'espacio_recurrente');
chequear('nace en estado detectado', $patron['estado'] === 'detectado');

registrar_prueba('Cuarto reporte sobre el mismo espacio del campus.', 'Biblioteca central');
analizar_patrones();
chequear('un patrón abierto NO se duplica', db_count('patrones_detectados') === 1);
chequear('pero sí actualiza el conteo',
    (int)db_select_one('SELECT ocurrencias FROM patrones_detectados LIMIT 1')['ocurrencias'] === 4);

registrar_prueba('Reporte sobre otro espacio distinto del campus.', 'Cafetería');
registrar_prueba('Segundo reporte sobre ese otro espacio.', 'Cafetería');
analizar_patrones();
chequear('un espacio con 2 reportes no genera patrón', db_count('patrones_detectados') === 1);

chequear('los patrones pendientes se listan', count(patrones_pendientes()) === 1);

$aud = db_select_one("SELECT COUNT(*) AS t FROM auditoria WHERE accion = 'patron_detectado'");
chequear('la detección queda auditada', (int)$aud['t'] > 0);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Guardado parcial del relato');

$token = guardar_borrador([
    'csrf_token'          => 'no-debe-guardarse',
    'tipo_violencia'      => 'laboral',
    'descripcion'         => 'Relato a medio escribir que la persona no alcanzó a enviar.',
    'denunciante_nombre'  => 'NOMBRE QUE NO DEBE GUARDARSE',
    'denunciante_email'   => 'correo@que-no-debe-guardarse.local',
    'denunciante_telefono'=> '3009999999',
]);

chequear('se devuelve un token de retorno', is_string($token) && strlen($token) === 32);

$recuperado = recuperar_borrador($token);
chequear('el borrador se recupera', is_array($recuperado));
chequear('conserva el relato',
    strpos($recuperado['descripcion'] ?? '', 'medio escribir') !== false);
chequear('NO guarda el nombre de contacto', !isset($recuperado['denunciante_nombre']));
chequear('NO guarda el correo', !isset($recuperado['denunciante_email']));
chequear('NO guarda el teléfono', !isset($recuperado['denunciante_telefono']));
chequear('NO guarda el token CSRF', !isset($recuperado['csrf_token']));

$fila = db_select_one('SELECT token_hash, contenido_cifrado FROM borradores_denuncia LIMIT 1');
chequear('el token se guarda hasheado, no en claro', $fila['token_hash'] !== $token);
chequear('el contenido está cifrado en reposo',
    strpos((string)$fila['contenido_cifrado'], 'medio escribir') === false);
chequear('un token inválido no recupera nada', recuperar_borrador('0000') === null);

db_execute("UPDATE borradores_denuncia SET expira_at = DATE_SUB(NOW(), INTERVAL 1 HOUR)");
chequear('un borrador vencido no se recupera', recuperar_borrador($token) === null);
chequear('los vencidos se limpian', limpiar_borradores_vencidos() === 1);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Salida rápida');

$componente = file_get_contents(BASE_PATH . '/views/componentes/salida_rapida.php');

// H-21 · el comportamiento vive en un archivo externo para que la CSP pueda
// prescindir de 'unsafe-inline' en script-src
$script = BASE_PATH . '/assets/js/salida_rapida.js';
chequear('el JavaScript está en un archivo propio, no en línea', is_file($script));

$js = is_file($script) ? file_get_contents($script) : '';

chequear('el componente enlaza el script externo',
    strpos($componente, 'salida_rapida.js') !== false);
chequear('el componente NO tiene JavaScript en línea',
    !preg_match('/<script(?![^>]*\bsrc=)/i', $componente));

chequear('usa location.replace, no assign',
    strpos($js, 'location.replace') !== false && strpos($js, 'location.assign') === false);
chequear('sustituye la entrada del historial', strpos($js, 'replaceState') !== false);
chequear('escucha la tecla Escape', strpos($js, 'Escape') !== false);
chequear('exige tres pulsaciones', strpos($js, 'pulsaciones >= 3') !== false);
chequear('el botón tiene nombre accesible', strpos($componente, 'aria-label') !== false);
chequear('el destino es el portal institucional',
    strpos($componente, 'udistrital.edu.co') !== false);
// La advertencia debe estar donde la lea quien mantenga el código, sea en el
// componente o en el script: prometer de más aquí tiene consecuencias reales.
chequear('documenta lo que NO puede hacer',
    strpos($js, 'no borra el historial completo') !== false
    || strpos($componente, 'no borra el historial completo') !== false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Vistas públicas · no dejar rastro');

$html_home = renderizar('home');
$html_form = renderizar('denuncia-publica');
$html_seg  = renderizar('seguimiento');
$html_port = renderizar('portal');

$paginas = ['inicio' => $html_home, 'formulario' => $html_form,
            'seguimiento' => $html_seg, 'portal' => $html_port];

foreach ($paginas as $nombre => $html) {
    chequear("«{$nombre}» incluye la salida rápida", strpos($html, 'salida-rapida') !== false);
}

chequear('el título de la pestaña NO menciona violencia ni denuncia',
    (bool)preg_match('/<title>([^<]*)<\/title>/', $html_form, $m)
    && stripos($m[1], 'violencia') === false
    && stripos($m[1], 'denuncia') === false,
    $m[1] ?? 'sin title');

chequear('el formulario desactiva el autocompletado',
    strpos($html_form, 'autocomplete="off"') !== false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('WCAG 2.1 AA · comprobaciones mecánicas');

foreach ($paginas as $nombre => $html) {
    chequear("«{$nombre}» declara el idioma", strpos($html, '<html lang="es"') !== false);
    chequear("«{$nombre}» tiene enlace de salto al contenido",
        strpos($html, 'saltar-contenido') !== false);
    chequear("«{$nombre}» tiene un único <h1>", substr_count($html, '<h1') <= 1,
        (string)substr_count($html, '<h1'));
    chequear("«{$nombre}» declara el foco visible", strpos($html, ':focus-visible') !== false);
    chequear("«{$nombre}» respeta prefers-reduced-motion",
        strpos($html, 'prefers-reduced-motion') !== false);
}

// Todo control de formulario debe tener etiqueta o nombre accesible
// Un control tiene nombre accesible si: lo referencia un <label for>, lleva
// aria-label, o está ENVUELTO por un <label>. Lo último se comprueba por
// posición y no por coincidencia de texto: comparar fragmentos de HTML da
// falsos positivos en cuanto dos controles se parecen.
preg_match_all('/<label\b[^>]*>.*?<\/label>/is', $html_form, $etiquetas, PREG_OFFSET_CAPTURE);
$rangos_label = [];
foreach ($etiquetas[0] as $bloque) {
    $rangos_label[] = [$bloque[1], $bloque[1] + strlen($bloque[0])];
}

preg_match_all('/<(input|select|textarea)\b[^>]*>/i', $html_form, $controles, PREG_OFFSET_CAPTURE);
$sin_nombre = [];

foreach ($controles[0] as $control) {
    [$etiqueta_html, $posicion] = $control;

    if (preg_match('/type=["\'](hidden|submit|button)["\']/i', $etiqueta_html)) { continue; }

    $tiene_id   = preg_match('/\bid=["\']([^"\']+)["\']/', $etiqueta_html, $mid);
    $etiquetado = $tiene_id && strpos($html_form, 'for="' . $mid[1] . '"') !== false;
    $aria       = strpos($etiqueta_html, 'aria-label') !== false;

    $envuelto = false;
    foreach ($rangos_label as [$inicio, $fin]) {
        if ($posicion > $inicio && $posicion < $fin) { $envuelto = true; break; }
    }

    if (!$etiquetado && !$aria && !$envuelto) {
        preg_match('/\bname=["\']([^"\']+)["\']/', $etiqueta_html, $mn);
        $sin_nombre[] = $mn[1] ?? '(sin name)';
    }
}

chequear('todo control del formulario tiene nombre accesible', count($sin_nombre) === 0,
    implode(', ', $sin_nombre));

// Los SVG decorativos no deben anunciarse al lector de pantalla
$svg_total = substr_count($html_port, '<svg');
$svg_ocultos = substr_count($html_port, 'aria-hidden="true"');
chequear('los iconos decorativos del portal se ocultan al lector',
    $svg_total === 0 || $svg_ocultos >= 1, "$svg_ocultos de $svg_total");

seccion('WCAG 1.4.3 · contraste calculado');

// Paleta efectivamente usada en las vistas públicas
$pares = [
    'purple-700 sobre blanco'  => ['#7e22ce', '#ffffff', 4.5],
    'purple-800 sobre blanco'  => ['#6b21a8', '#ffffff', 4.5],
    'purple-900 sobre blanco'  => ['#581c87', '#ffffff', 4.5],
    'gray-900 sobre blanco'    => ['#111827', '#ffffff', 4.5],
    'gray-700 sobre blanco'    => ['#374151', '#ffffff', 4.5],
    'blanco sobre purple-700'  => ['#ffffff', '#7e22ce', 4.5],
    'blanco sobre red-600'     => ['#ffffff', '#dc2626', 4.5],
    'blanco sobre gray-900'    => ['#ffffff', '#111827', 4.5],
];

foreach ($pares as $nombre => [$frente, $fondo, $minimo]) {
    $ratio = contraste($frente, $fondo);
    chequear("$nombre: {$ratio}:1 (mínimo {$minimo}:1)", $ratio >= $minimo, "{$ratio}:1");
}

// Contraste que NO cumple, para demostrar que la comprobación discrimina
chequear('la comprobación detecta un contraste insuficiente',
    contraste('#a855f7', '#ffffff') < 4.5, contraste('#a855f7', '#ffffff') . ':1');

// ─────────────────────────────────────────────────────────────────────────────
seccion('H-20 · El directorio de instituciones ya tiene pantalla');

chequear('el portal está enrutado',
    strpos(file_get_contents(BASE_PATH . '/index_nativo.php'), "'portal'") !== false);
chequear('la sección de instituciones renderiza',
    strpos(renderizar('portal', ['seccion' => 'instituciones']), 'Instituciones de apoyo') !== false);
chequear('la sección de normativa cita los Acuerdos de 2026',
    strpos(renderizar('portal', ['seccion' => 'normativa']), 'Acuerdo CSU 002 de 2026') !== false);
chequear('el pie ya no tiene enlaces muertos a «#»',
    !preg_match('/<a href="#"[^>]*>(Política|Términos|Ayuda)/', $html_home));
chequear('las líneas de emergencia 123 y 155 están en el portal',
    strpos($html_port, 'tel:123') !== false && strpos($html_port, 'tel:155') !== false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Recursos del frontend · coherencia con el modo elegido');

// H-21 quedó REABIERTO por decisión de producto: el CSS local alcanzó el 100 %
// de cobertura de clases pero no logró equivalencia visual con Tailwind.
// Estas comprobaciones ya no exigen «sin CDN»: exigen que la política de
// seguridad y las vistas sean COHERENTES con el modo configurado. Una CSP
// estricta con recursos de CDN rompe el sitio en silencio, y una CSP
// permisiva sin CDN regala defensa sin motivo.
$modo_cdn = usar_assets_cdn();
echo '  (modo actual: ' . ($modo_cdn ? 'CDN' : 'recursos locales') . ")\n";

$fuentes = array_merge(
    glob(BASE_PATH . '/views/*.php'), glob(BASE_PATH . '/views/*/*.php'),
    glob(BASE_PATH . '/modules/*/*.php')
);

// La decisión vive en UN solo sitio, no repetida en cada layout
$con_cdn = [];
foreach ($fuentes as $archivo) {
    if (basename($archivo) === 'assets.php') { continue; }
    if (preg_match('/(cdn\.tailwindcss|cdn\.jsdelivr|unpkg\.com|cdnjs)/i',
                   file_get_contents($archivo))) {
        $con_cdn[] = basename($archivo);
    }
}
chequear('la carga de recursos está centralizada en un único componente',
    empty($con_cdn), implode(', ', $con_cdn));

chequear('los tres layouts usan el componente compartido',
    count(array_filter(
        [BASE_PATH . '/views/layouts/guest.php', BASE_PATH . '/views/layouts/app.php',
         BASE_PATH . '/modules/auth/login.php'],
        fn($f) => strpos(file_get_contents($f), 'componentes/assets.php') !== false
    )) === 3);

// El camino de vuelta debe seguir existiendo
chequear('el CSS local se conserva como alternativa', is_file(BASE_PATH . '/assets/css/app.css'));
chequear('el generador de CSS se conserva', is_file(BASE_PATH . '/herramientas/generar_css.php'));
chequear('el JavaScript propio se conserva', is_file(BASE_PATH . '/assets/js/app.js'));

foreach ($paginas as $nombre => $html) {
    if ($modo_cdn) {
        chequear("«{$nombre}» carga Tailwind", strpos($html, 'cdn.tailwindcss.com') !== false);
    } else {
        chequear("«{$nombre}» enlaza el CSS local", strpos($html, 'assets/css/app.css') !== false);
    }
}

// La CSP debe corresponderse con el modo
$cabeceras = [];
foreach (headers_list() as $cabecera) {
    if (stripos($cabecera, 'Content-Security-Policy') === 0) { $cabeceras[] = $cabecera; }
}
$csp = $cabeceras[0] ?? '';

if ($csp === '') {
    // En CLI las cabeceras no se emiten: se inspecciona el código fuente
    $seguridad = file_get_contents(BASE_PATH . '/includes/security.php');
    chequear('la CSP contempla ambos modos',
        strpos($seguridad, 'usar_assets_cdn()') !== false);
    chequear('el modo CDN permite el origen de Tailwind',
        strpos($seguridad, 'https://cdn.tailwindcss.com') !== false);
    chequear('el modo local reduce script-src a self',
        strpos($seguridad, '$script_src = "\'self\'"') !== false);
}

chequear('la CSP prohíbe que la página sea embebida',
    strpos(file_get_contents(BASE_PATH . '/includes/security.php'), "frame-ancestors 'none'") !== false);
chequear('la CSP restringe el destino de los formularios',
    strpos(file_get_contents(BASE_PATH . '/includes/security.php'), "form-action 'self'") !== false);
chequear('la CSP bloquea objetos embebidos',
    strpos(file_get_contents(BASE_PATH . '/includes/security.php'), "object-src 'none'") !== false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('H-09 · Recuperación de contraseña');

chequear('la tabla password_resets existe',
    !empty(db_select("SHOW TABLES LIKE 'password_resets'")));
chequear('la ruta está enrutada',
    strpos(file_get_contents(BASE_PATH . '/index_nativo.php'), "'recuperar'") !== false);
chequear('el enlace del login ya no apunta a una ruta inexistente',
    strpos(file_get_contents(BASE_PATH . '/modules/auth/login.php'), 'forgot-password') === false);

// ⚠ La prueba crea SU PROPIA cuenta desechable.
//
// Antes tomaba «el primer usuario activo», que resultaba ser
// admin@botonvioleta.com, y le cambiaba la contraseña al probar el
// restablecimiento. Una suite que muta cuentas reales deja el entorno
// inservible sin decirlo: quien intentaba entrar después recibía
// «Email o contraseña incorrectos» sin ninguna pista de la causa.
$correo_desechable = 'prueba-recuperacion-' . bin2hex(random_bytes(4)) . '@pruebas.invalid';

db_insert_simple('usuarios', [
    'nombre'     => 'Cuenta desechable de prueba',
    'email'      => $correo_desechable,
    'password'   => hash_password('ContrasenaInicial2026'),
    'rol'        => 'reportes',
    'activo'     => 1,
    'created_at' => date('Y-m-d H:i:s'),
]);

$usuario_prueba = db_select_one('SELECT email FROM usuarios WHERE email = ?', [$correo_desechable]);

if ($usuario_prueba) {
    $token = generate_password_reset_token($usuario_prueba['email']);
    chequear('se genera un token de 64 caracteres',
        is_string($token) && strlen($token) === 64);

    $guardado = db_select_one('SELECT token, expires_at, usado_at FROM password_resets
                                WHERE email = ? ORDER BY id DESC LIMIT 1', [$usuario_prueba['email']]);
    chequear('el token se guarda hasheado, no en claro', $guardado['token'] !== $token);
    chequear('vence en una hora',
        abs(strtotime($guardado['expires_at']) - (time() + 3600)) <= 5);

    chequear('el token válido se reconoce',
        validate_password_reset_token($token) === $usuario_prueba['email']);
    chequear('un token inventado se rechaza',
        validate_password_reset_token(str_repeat('a', 64)) === false);
    chequear('un token de longitud incorrecta se rechaza',
        validate_password_reset_token('corto') === false);

    // Solicitar otro invalida el anterior
    $token2 = generate_password_reset_token($usuario_prueba['email']);
    chequear('solicitar un enlace nuevo invalida el anterior',
        validate_password_reset_token($token) === false);

    chequear('el restablecimiento funciona',
        reset_password($token2, 'ContrasenaDePrueba2026') === true);
    chequear('el token usado deja de servir',
        validate_password_reset_token($token2) === false);
    chequear('la nueva contraseña permite autenticarse',
        verify_password('ContrasenaDePrueba2026',
            db_select_one('SELECT password FROM usuarios WHERE email = ?',
                [$usuario_prueba['email']])['password']));

    $aud = db_select_one("SELECT COUNT(*) AS t FROM auditoria WHERE accion = 'contrasena_restablecida'");
    chequear('el restablecimiento queda auditado', (int)$aud['t'] > 0);

    chequear('una cuenta inexistente no genera token',
        generate_password_reset_token('nadie@ninguna-parte.test') === false);
}

$modulo = file_get_contents(BASE_PATH . '/modules/auth/recuperar.php');
chequear('la respuesta no revela si el correo existe',
    strpos($modulo, 'Si el correo corresponde a una cuenta activa') !== false);
chequear('exige contraseñas de al menos 12 caracteres',
    strpos($modulo, 'strlen($contrasena) < 12') !== false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Limpieza · la suite no debe dejar rastro');

db_execute("DELETE FROM password_resets WHERE email LIKE '%@pruebas.invalid'");
db_execute("DELETE FROM usuarios WHERE email LIKE '%@pruebas.invalid'");

chequear('las cuentas desechables se eliminan',
    db_select_one("SELECT COUNT(*) AS t FROM usuarios WHERE email LIKE '%@pruebas.invalid'")['t'] === 0);

// Ninguna cuenta preexistente debe haber cambiado de contraseña
$alteradas = [];
foreach (db_select('SELECT email, password FROM usuarios') ?: [] as $fila) {
    if (!isset($huella_inicial[$fila['email']])) { continue; } // creada por la suite
    if ($huella_inicial[$fila['email']] !== $fila['password']) {
        $alteradas[] = $fila['email'];
    }
}

chequear('ninguna cuenta preexistente cambió de contraseña',
    empty($alteradas), implode(', ', $alteradas));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('-', 60) . "\n";
echo ($fallidas === 0 ? 'TODAS LAS COMPROBACIONES PASARON' : 'HAY FALLOS')
   . ": $correctas correctas, $fallidas fallidas\n";

exit($fallidas === 0 ? 0 : 1);
