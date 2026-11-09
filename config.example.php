<?php
/**
 * CONFIGURACIÓN DEL SISTEMA - BOTÓN VIOLETA
 *
 * INSTRUCCIONES:
 * 1. Copiar este archivo como config.php
 * 2. Configurar los valores según tu entorno
 * 3. NUNCA subir config.php a repositorio (solo config.example.php)
 */

// ============================================================================
// CONFIGURACIÓN DE LA APLICACIÓN
// ============================================================================
define('APP_NAME', 'Botón Violeta - Sistema de Denuncias');
define('APP_ENV', 'production'); // production | development
define('APP_DEBUG', false); // true para mostrar errores (solo desarrollo)
define('APP_URL', 'https://dominio-institucional/'); // URL base del sistema
define('APP_TIMEZONE', 'America/Bogota');

// ============================================================================
// CONFIGURACIÓN DE BASE DE DATOS
// ============================================================================
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'nombre_de_la_base_de_datos');
define('DB_USER', 'usuario_de_la_base_de_datos');
define('DB_PASS', ''); // Nunca escribir la contraseña real en este archivo
define('DB_CHARSET', 'utf8mb4');

// ============================================================================
// CONFIGURACIÓN DE SEGURIDAD
// ============================================================================

// Clave de cifrado AES-256 (generar con: openssl rand -base64 32)
// O en PHP: base64_encode(random_bytes(32))
define('ENCRYPTION_KEY', ''); // CAMBIAR EN PRODUCCIÓN

// Sesiones
define('SESSION_LIFETIME', 120); // minutos
define('SESSION_SECURE', true); // true si usa HTTPS
define('SESSION_NAME', 'BOTON_VIOLETA_SESSION');

// CSRF Protection
define('CSRF_TOKEN_LIFETIME', 3600); // segundos

// Rate Limiting (anti-brute force)
define('RATE_LIMIT_MAX_ATTEMPTS', 5);
define('RATE_LIMIT_DECAY_MINUTES', 15);

// ============================================================================
// SEGUIMIENTO PÚBLICO CON SEGUNDO FACTOR (Fase 2)
// ============================================================================

// Activa el envío del código de verificación en el seguimiento público.
//
// Debe permanecer en `false` hasta que exista canal de envío (Microsoft Graph,
// Fase 4). Con el interruptor apagado la maquinaria del segundo factor está
// construida y probada, pero no se despacha ningún código: la pantalla indica
// a la persona que se comunique con la línea de atención.
//
// Encenderlo sin canal de envío deja a las víctimas sin forma de consultar
// su caso.
define('SEGUIMIENTO_OTP_ACTIVO', false);

// ============================================================================
// RECURSOS DEL FRONTEND
// ============================================================================

// true  = Tailwind y Alpine desde CDN
// false = CSS y JS locales (assets/), generados por herramientas/generar_css.php
//
// Está en true por decisión de producto: el CSS local alcanzó el 100 %% de
// cobertura de clases pero no logró equivalencia visual con Tailwind, y un
// frontend roto en un sistema al que acude una persona en crisis es peor
// problema que una dependencia de CDN. Mantiene abierto el hallazgo H-21.
define('ASSETS_CDN', true);

// ============================================================================
// MICROSOFT 365 · GRAPH (Fase 4)
// ============================================================================

// Activa el envío real de correos y la creación de reuniones de Teams.
// Requiere registro de aplicación en Azure AD con permisos de APLICACIÓN:
//
//   Mail.Send                    → restringir con Application Access Policy
//                                  a UN ÚNICO buzón. Sin esa política el
//                                  permiso alcanza a todos los buzones del
//                                  inquilino.
//   Calendars.ReadWrite
//   OnlineMeetings.ReadWrite.All → requiere Online Meeting Application
//                                  Access Policy
//
// Con GRAPH_ACTIVO en false las notificaciones se encolan y esperan: no se
// pierden, salen en cuanto se active.
define('GRAPH_ACTIVO', false);
define('GRAPH_TENANT_ID', '');
define('GRAPH_CLIENT_ID', '');
define('GRAPH_CLIENT_SECRET', ''); // Rotar periódicamente. Nunca en el repositorio
define('GRAPH_REMITENTE', 'vbgbienestarud@udistrital.edu.co');

// ============================================================================
// CONFIGURACIÓN DE CORREO ELECTRÓNICO
// ============================================================================
define('MAIL_ENABLED', false); // Habilitar envío de correos
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', '');
define('MAIL_PASSWORD', '');
define('MAIL_ENCRYPTION', 'tls'); // tls | ssl
define('MAIL_FROM_EMAIL', 'noreply@tudominio.com');
define('MAIL_FROM_NAME', APP_NAME);

// ============================================================================
// CONFIGURACIÓN DE ARCHIVOS
// ============================================================================
define('UPLOAD_MAX_SIZE', 10485760); // 10 MB en bytes
define('UPLOAD_ALLOWED_EXTENSIONS', 'pdf,doc,docx,jpg,jpeg,png');
define('UPLOAD_PATH', __DIR__ . '/uploads');

// ============================================================================
// CONFIGURACIÓN DE LOGS
// ============================================================================
define('LOG_ENABLED', true);
define('LOG_LEVEL', 'info'); // debug | info | warning | error
define('LOG_PATH', __DIR__ . '/logs');

// ============================================================================
// CONFIGURACIÓN DE AUDITORÍA
// ============================================================================
define('AUDIT_ENABLED', true);
define('AUDIT_IP_TRACKING', true);

// ============================================================================
// RUTAS DEL SISTEMA (NO MODIFICAR)
// ============================================================================
define('BASE_PATH', __DIR__);
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('MODULES_PATH', BASE_PATH . '/modules');
define('ASSETS_PATH', BASE_PATH . '/assets');
define('VIEWS_PATH', BASE_PATH . '/views');

// ============================================================================
// CONFIGURACIÓN DE ERRORES (según entorno)
// ============================================================================
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// Configurar log de errores
if (LOG_ENABLED && is_writable(LOG_PATH)) {
    ini_set('log_errors', '1');
    ini_set('error_log', LOG_PATH . '/php_errors.log');
}

// ============================================================================
// CONFIGURACIÓN DE PHP
// ============================================================================
ini_set('memory_limit', '256M');
ini_set('max_execution_time', '300');
ini_set('upload_max_filesize', '20M');
ini_set('post_max_size', '25M');

// Configurar zona horaria
date_default_timezone_set(APP_TIMEZONE);

// Configurar locale para español
setlocale(LC_TIME, 'es_CO.UTF-8', 'es_CO', 'spanish');
setlocale(LC_NUMERIC, 'es_CO.UTF-8', 'es_CO', 'spanish');

// ============================================================================
// CONFIGURACIÓN ADICIONAL
// ============================================================================

// Días hábiles para cálculo de plazos
define('BUSINESS_DAYS_CALCULATION', true);

// Keywords de riesgo (archivo JSON)
define('RISK_KEYWORDS_FILE', BASE_PATH . '/config/risk_keywords.json');

// ============================================================================
// VALIDACIÓN DE CONFIGURACIÓN
// ============================================================================

// Validar configuración en producción
if (APP_ENV === 'production') {
    // Verificar que la clave de cifrado esté configurada
    if (empty(ENCRYPTION_KEY) || strlen(base64_decode(ENCRYPTION_KEY, true)) !== 32) {
        die('ERROR: ENCRYPTION_KEY must be a valid 32-byte base64 encoded string. Generate with: php -r "echo base64_encode(random_bytes(32));"');
    }

    // Verificar que APP_DEBUG esté desactivado
    if (APP_DEBUG === true) {
        die('ERROR: APP_DEBUG must be false in production environment');
    }

    // Verificar que SESSION_SECURE esté activado si se usa HTTPS
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') && !SESSION_SECURE) {
        die('ERROR: SESSION_SECURE must be true when using HTTPS');
    }
}

// Crear directorios necesarios si no existen
$required_dirs = [
    UPLOAD_PATH,
    LOG_PATH,
    BASE_PATH . '/sessions',
    BASE_PATH . '/cache',
    BASE_PATH . '/backups',
];

foreach ($required_dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ============================================================================
// FIN DE CONFIGURACIÓN
// ============================================================================
?>
