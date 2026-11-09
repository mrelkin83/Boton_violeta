<?php
/**
 * INICIALIZACIÓN DEL SISTEMA
 *
 * Este archivo carga toda la configuración, funciones y conexiones necesarias
 * Incluir este archivo al inicio de cada página PHP
 */

// Prevenir acceso directo
if (!defined('BASE_PATH')) {
    die('Access denied');
}

// ============================================================================
// 1. CARGAR CONFIGURACIÓN
// ============================================================================
// CLAUDE.md §5.1.4 — los secretos deben vivir FUERA del document root.
// Orden de búsqueda:
//   1. Ruta indicada en la variable de entorno BV_CONFIG
//   2. Un nivel por encima del document root  (ubicación recomendada)
//   3. Raíz del proyecto (heredado; se registra advertencia)
$config_candidatos = [];

$config_entorno = getenv('BV_CONFIG');
if (is_string($config_entorno) && $config_entorno !== '') {
    $config_candidatos[] = $config_entorno;
}

$config_candidatos[] = dirname(BASE_PATH) . '/bv_config.php';
$config_candidatos[] = BASE_PATH . '/config.php';

$config_ruta = null;
foreach ($config_candidatos as $candidato) {
    if (is_file($candidato)) {
        $config_ruta = $candidato;
        break;
    }
}

if ($config_ruta === null) {
    die('Configuration file not found. Copie config.example.php a bv_config.php un nivel por encima del document root.');
}

// Se registra después de cargar functions.php (log_error aún no existe aquí)
$config_en_arbol_web = ($config_ruta === BASE_PATH . '/config.php');

require_once $config_ruta;

// ============================================================================
// 2. INICIAR SESIÓN
// ============================================================================
if (session_status() === PHP_SESSION_NONE) {
    // Configuración de sesión segura
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', SESSION_SECURE ? '1' : '0');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_lifetime', (string)(SESSION_LIFETIME * 60));

    // Directorio de sesiones
    $session_path = BASE_PATH . '/sessions';
    if (!is_dir($session_path)) {
        mkdir($session_path, 0700, true);
    }
    ini_set('session.save_path', $session_path);

    session_name(SESSION_NAME);
    session_start();

    // Regenerar ID periódicamente
    if (!isset($_SESSION['_last_regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['_last_regenerated'] = time();
    } elseif (time() - $_SESSION['_last_regenerated'] > 300) {
        session_regenerate_id(true);
        $_SESSION['_last_regenerated'] = time();
    }

    // Validar timeout de inactividad
    $idle_timeout = SESSION_LIFETIME * 60;
    if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > $idle_timeout) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['_last_activity'] = time();
}

// ============================================================================
// 3. CARGAR FUNCIONES Y HELPERS
// ============================================================================
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/database.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/security.php';
require_once INCLUDES_PATH . '/cifrado.php';
require_once INCLUDES_PATH . '/autorizacion.php';
require_once INCLUDES_PATH . '/competencia.php';
require_once INCLUDES_PATH . '/personas_caso.php';
require_once INCLUDES_PATH . '/plazos.php';
require_once INCLUDES_PATH . '/graph/cliente.php';
require_once INCLUDES_PATH . '/notificaciones.php';
require_once INCLUDES_PATH . '/medidas.php';
require_once INCLUDES_PATH . '/riesgo.php';
require_once INCLUDES_PATH . '/reportes.php';
require_once INCLUDES_PATH . '/evidencia.php';
require_once INCLUDES_PATH . '/prevencion.php';
require_once INCLUDES_PATH . '/encuentros.php';
require_once INCLUDES_PATH . '/seguimiento_publico.php';
// v3.1 · Fases A–G
require_once INCLUDES_PATH . '/configuracion.php';
require_once INCLUDES_PATH . '/expediente.php';
require_once INCLUDES_PATH . '/compromisos.php';
require_once INCLUDES_PATH . '/remisiones.php';
require_once INCLUDES_PATH . '/sanciones.php';
require_once INCLUDES_PATH . '/turnos.php';
require_once INCLUDES_PATH . '/menu.php';
require_once INCLUDES_PATH . '/firmas.php';

// Canal de reporte preventivo anónimo (Fase 7). Su apertura es decisión del
// Comité para la Equidad de Géneros y Diversidades Sexuales (§13.1 punto 4).
if (!defined('CANAL_ANONIMO_ACTIVO')) {
    define('CANAL_ANONIMO_ACTIVO', false);
}

// Origen de los recursos del frontend.
// true  = Tailwind y Alpine desde CDN (H-21 reabierto, decisión de Elkin)
// false = assets/css/app.css y assets/js/app.js locales
// Ver views/componentes/assets.php para el contexto completo.
if (!defined('ASSETS_CDN')) {
    define('ASSETS_CDN', true);
}

// Umbral de supresión estadística en reportes (RN-10). Recomendado k = 5.
// Bajarlo es una decisión con consecuencias sobre personas identificables.
if (!defined('UMBRAL_SUPRESION')) {
    define('UMBRAL_SUPRESION', 5);
}

// Integración con Microsoft 365 (Fase 4). Requiere registro de aplicación en
// Azure AD; mientras no exista, las notificaciones se encolan y esperan.
if (!defined('GRAPH_ACTIVO')) {
    define('GRAPH_ACTIVO', false);
}

// Exigencias de idoneidad (Fase 3). Se activan cuando la Universidad haya
// cargado los acuerdos de confidencialidad y las certificaciones del equipo;
// encenderlas antes dejaría sin acceso a quienes están atendiendo casos.
if (!defined('EXIGIR_ACUERDO_CONFIDENCIALIDAD')) {
    define('EXIGIR_ACUERDO_CONFIDENCIALIDAD', false);
}
if (!defined('EXIGIR_CERTIFICACION_VIGENTE')) {
    define('EXIGIR_CERTIFICACION_VIGENTE', false);
}

// Interruptor del segundo factor en el seguimiento público (Fase 2).
// Se activa cuando exista canal de envío — Microsoft Graph, Fase 4.
if (!defined('SEGUIMIENTO_OTP_ACTIVO')) {
    define('SEGUIMIENTO_OTP_ACTIVO', false);
}

// Advertencia diferida: configuración dentro del árbol web (CLAUDE.md §5.1.4)
if ($config_en_arbol_web) {
    log_error(
        'config.php se está cargando desde el árbol web. Muévalo a ' . dirname(BASE_PATH) . '/bv_config.php',
        'warning'
    );
}

// ============================================================================
// 4. ESTABLECER HEADERS DE SEGURIDAD
// ============================================================================
set_security_headers();

// ============================================================================
// 5. VERIFICAR CONEXIÓN A BASE DE DATOS
// ============================================================================
if (!db_check_connection()) {
    if (APP_DEBUG) {
        die('Unable to connect to database. Please check your configuration.');
    } else {
        die('Service temporarily unavailable. Please try again later.');
    }
}

// ============================================================================
// 6. BUFFER DE SALIDA (para redirecciones)
// ============================================================================
ob_start();

// ============================================================================
// 7. MANEJADOR DE ERRORES
// ============================================================================
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }

    $message = "[$errno] $errstr in $errfile:$errline";
    log_error($message, 'error');

    if (APP_DEBUG) {
        echo "<div style='background:#ff5555;color:white;padding:10px;margin:10px 0;border-radius:5px;'>";
        echo "<strong>Error [$errno]:</strong> $errstr<br>";
        echo "<strong>File:</strong> $errfile:$errline";
        echo "</div>";
    }

    return true;
});

// Manejador de excepciones
set_exception_handler(function ($exception) {
    $message = get_class($exception) . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine();
    log_error($message, 'error', ['trace' => $exception->getTraceAsString()]);

    if (APP_DEBUG) {
        echo "<div style='background:#ff5555;color:white;padding:15px;margin:10px 0;border-radius:5px;'>";
        echo "<h2>Uncaught Exception</h2>";
        echo "<p><strong>Type:</strong> " . get_class($exception) . "</p>";
        echo "<p><strong>Message:</strong> " . e($exception->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . e($exception->getFile()) . ':' . $exception->getLine() . "</p>";
        echo "<pre>" . e($exception->getTraceAsString()) . "</pre>";
        echo "</div>";
    } else {
        http_response_code(500);
        echo "An error occurred. Please try again later.";
    }

    exit(1);
});

// ============================================================================
// SISTEMA LISTO
// ============================================================================
?>
