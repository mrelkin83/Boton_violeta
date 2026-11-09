<?php
/**
 * VISTA: ERROR 404 - PÁGINA NO ENCONTRADA
 *
 * Página mostrada cuando no se encuentra un recurso
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Establecer código de respuesta HTTP
http_response_code(404);

$title = 'Página No Encontrada - 404';
ob_start();
?>

<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="max-w-2xl w-full text-center">
        <!-- Icono de Error -->
        <div class="mb-8">
            <svg class="w-32 h-32 text-purple-400 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>

        <!-- Título -->
        <h1 class="text-6xl md:text-8xl font-bold text-gray-900 mb-4">404</h1>
        <h2 class="text-2xl md:text-3xl font-bold text-gray-800 mb-4">Página No Encontrada</h2>

        <!-- Descripción -->
        <p class="text-lg text-gray-600 mb-8">
            Lo sentimos, la página que estás buscando no existe o ha sido movida.
        </p>

        <!-- Información Adicional -->
        <div class="bg-purple-50 rounded-lg p-6 mb-8 text-left">
            <h3 class="font-bold text-gray-900 mb-2">¿Qué puedes hacer?</h3>
            <ul class="space-y-2 text-gray-700">
                <li class="flex items-start">
                    <svg class="w-5 h-5 text-purple-600 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    Verifica que la URL esté escrita correctamente
                </li>
                <li class="flex items-start">
                    <svg class="w-5 h-5 text-purple-600 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    Regresa a la página de inicio
                </li>
                <li class="flex items-start">
                    <svg class="w-5 h-5 text-purple-600 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    Usa el menú de navegación
                </li>
            </ul>
        </div>

        <!-- Botones de Acción -->
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="<?= url('?page=home') ?>" class="inline-flex items-center justify-center px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                Ir al Inicio
            </a>

            <?php if (is_authenticated()): ?>
            <a href="<?= url('?page=dashboard') ?>" class="inline-flex items-center justify-center px-6 py-3 bg-white hover:bg-gray-50 text-purple-600 font-medium rounded-lg border-2 border-purple-600 transition">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Ir al Dashboard
            </a>
            <?php else: ?>
            <a href="<?= url('?page=login') ?>" class="inline-flex items-center justify-center px-6 py-3 bg-white hover:bg-gray-50 text-purple-600 font-medium rounded-lg border-2 border-purple-600 transition">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                </svg>
                Iniciar Sesión
            </a>
            <?php endif; ?>
        </div>

        <!-- Información de Contacto -->
        <div class="mt-12 pt-8 border-t border-gray-200">
            <p class="text-sm text-gray-500">
                Si crees que esto es un error, por favor contacta al soporte técnico.
            </p>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/guest.php';
?>
