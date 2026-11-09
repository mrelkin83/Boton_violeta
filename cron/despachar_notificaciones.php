<?php
/**
 * CRON · DESPACHO DE NOTIFICACIONES
 *
 * Fase 4 · CLAUDE.md §12.3
 *
 * Ejecutar cada minuto:
 *   * * * * * /usr/bin/php /ruta/al/proyecto/cron/despachar_notificaciones.php >> /dev/null 2>&1
 *
 * En cPanel: Cron Jobs → cada minuto → comando anterior.
 *
 * Bloqueo por archivo para evitar ejecuciones concurrentes: si una corrida se
 * demora más de un minuto, la siguiente no debe duplicar envíos. Reintentar el
 * envío de un código de verificación significa invalidar el que la persona ya
 * tiene en pantalla.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/init.php';

$ruta_bloqueo = BASE_PATH . '/cache/despachador.lock';
$bloqueo = fopen($ruta_bloqueo, 'c');

if ($bloqueo === false) {
    log_error('No se pudo abrir el archivo de bloqueo del despachador', 'error');
    exit(1);
}

// Bloqueo no bloqueante: si otra corrida está activa, esta se retira sin más
if (!flock($bloqueo, LOCK_EX | LOCK_NB)) {
    fclose($bloqueo);
    exit(0);
}

$inicio = microtime(true);

try {
    $resumen = despachar_notificaciones(null, 50);

    $duracion = round(microtime(true) - $inicio, 2);

    // Solo se registra cuando hubo trabajo: un cron por minuto que escribe
    // siempre convierte el log en ruido y entierra los errores reales.
    if ($resumen['procesadas'] > 0) {
        log_error(sprintf(
            'Despacho de notificaciones: %d procesadas, %d enviadas, %d reintentos, %d fallidas (%ss)',
            $resumen['procesadas'], $resumen['enviadas'],
            $resumen['reintentos'], $resumen['fallidas'], $duracion
        ), 'info');
    }

    // Alerta a coordinación cuando algo muere definitivamente
    if ($resumen['fallidas'] > 0) {
        log_error('Hay notificaciones fallidas definitivamente. Revisar '
                . 'notificaciones_salientes con estado = fallido', 'error');
    }

    echo "procesadas={$resumen['procesadas']} enviadas={$resumen['enviadas']} "
       . "reintentos={$resumen['reintentos']} fallidas={$resumen['fallidas']}\n";

} catch (Throwable $e) {
    log_error('Error en el despachador de notificaciones: ' . $e->getMessage(), 'error');
    echo "error\n";
} finally {
    flock($bloqueo, LOCK_UN);
    fclose($bloqueo);
}
