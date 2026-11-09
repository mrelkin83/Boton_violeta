<?php
/**
 * VISTA: ERROR 500 - ERROR DEL SERVIDOR
 *
 * Página mostrada cuando ocurre un error interno del servidor
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Establecer código de respuesta HTTP
http_response_code(500);

$title = 'Error del Servidor - 500';
ob_start();
?>

<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="max-w-2xl w-full text-center">
        <!-- Icono de Error -->
        <div class="mb-8">
            <svg class="w-32 h-32 text-red-400 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
        </div>

        <!-- Título -->
        <h1 class="text-6xl md:text-8xl font-bold text-gray-900 mb-4">500</h1>
        <h2 class="text-2xl md:text-3xl font-bold text-gray-800 mb-4">Error del Servidor</h2>

        <!-- Descripción -->
        <p class="text-lg text-gray-600 mb-8">
            Lo sentimos, ha ocurrido un error inesperado en el servidor. Nuestro equipo ha sido notificado y está trabajando para resolver el problema.
        </p>

        <!-- Información Adicional -->
        <div class="bg-red-50 rounded-lg p-6 mb-8 text-left">
            <h3 class="font-bold text-gray-900 mb-2">¿Qué está pasando?</h3>
            <p class="text-gray-700 mb-4">
                El servidor ha encontrado una situación inesperada que le impide completar tu solicitud. Este error ha sido registrado automáticamente.
            </p>

            <h3 class="font-bold text-gray-900 mb-2">¿Qué puedes hacer?</h3>
            <ul class="space-y-2 text-gray-700">
                <li class="flex items-start">
                    <svg class="w-5 h-5 text-red-600 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    Espera unos minutos e intenta nuevamente
                </li>
                <li class="flex items-start">
                    <svg class="w-5 h-5 text-red-600 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    Regresa a la página anterior y reintenta
                </li>
                <li class="flex items-start">
                    <svg class="w-5 h-5 text-red-600 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    Si el problema persiste, contacta al soporte técnico
                </li>
            </ul>
        </div>

        <!-- Botones de Acción -->
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <button type="button" onclick="window.history.back()" class="inline-flex items-center justify-center px-6 py-3 bg-gray-600 hover:bg-gray-700 text-white font-medium rounded-lg transition">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Página Anterior
            </button>

            <a href="<?= url('?page=home') ?>" class="inline-flex items-center justify-center px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                Ir al Inicio
            </a>
        </div>

        <!-- Información de Contacto -->
        <div class="mt-12 pt-8 border-t border-gray-200">
            <p class="text-sm text-gray-500 mb-2">
                <strong>Código de Error:</strong> 500 - Internal Server Error
            </p>
            <p class="text-sm text-gray-500">
                Si necesitas reportar este error, guarda la fecha y hora: <strong><?= date('Y-m-d H:i:s') ?></strong>
            </p>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/guest.php';
?>
