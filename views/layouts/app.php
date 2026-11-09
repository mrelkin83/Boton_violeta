<?php
/**
 * LAYOUT PRINCIPAL - Para usuarios autenticados
 *
 * Variables requeridas:
 * - $title: Título de la página
 * - $content: Contenido principal (usar ob_start/ob_get_clean en las vistas)
 */

if (!defined('BASE_PATH')) {
    die('Access denied');
}

// Verificar autenticación
require_auth();

// Obtener usuario actual
$current_user = auth_user();
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sistema de Gestión de Denuncias de Violencias de Género">
    <title><?= e($title ?? 'Dashboard') ?> - <?= e(APP_NAME) ?></title>

    <?php require BASE_PATH . '/views/componentes/assets.php'; ?>

</head>
<body class="bg-gray-50">

    <a href="#contenido-principal" class="saltar-contenido">Saltar al contenido principal</a>

    <!-- Sidebar (Mobile) -->
    <div id="velo-lateral" hidden
         class="fixed inset-0 z-40 bg-gray-900 bg-opacity-50 lg:hidden"></div>

    <!-- Sidebar -->
    <aside id="barra-lateral"
           aria-label="Navegación principal"
           class="fixed inset-y-0 left-0 z-50 w-64 bg-purple-800 text-white transform transition duration-300 ease-in-out -translate-x-full lg:translate-x-0">

        <!-- Logo -->
        <div class="flex items-center justify-between p-4 border-b border-purple-700">
            <div class="flex items-center space-x-2">
                <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6z"></path>
                </svg>
                <span class="text-lg font-bold">Botón Violeta</span>
            </div>
            <button type="button" id="cerrar-menu" aria-label="Cerrar menú" class="lg:hidden">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <!-- Navigation · tabla MENU_POR_ROL (v3.1 §8), no `if` sueltos -->
        <nav class="flex-1 p-4 space-y-2 overflow-y-auto">
            <?php
            $pagina_actual = $_GET['page'] ?? 'dashboard';
            foreach (menu_para_rol($current_user['rol']) as $ruta => $etiqueta):
                $activa = $pagina_actual === $ruta;
            ?>
            <a href="?page=<?= e($ruta) ?>"
               class="flex items-center space-x-3 px-3 py-2 rounded-lg transition <?= $activa ? 'bg-purple-700 font-semibold' : 'hover:bg-purple-700' ?>"
               <?= $activa ? 'aria-current="page"' : '' ?>>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
                <span><?= e($etiqueta) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <!-- User info -->
        <div class="p-4 border-t border-purple-700">
            <div class="flex items-center space-x-3 mb-3">
                <div class="w-10 h-10 bg-purple-600 rounded-full flex items-center justify-center">
                    <span class="text-sm font-bold"><?= e(strtoupper(substr($current_user['nombre'], 0, 2))) ?></span>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-medium"><?= e($current_user['nombre']) ?></p>
                    <p class="text-xs text-purple-300"><?= e(ucfirst($current_user['rol'])) ?></p>
                </div>
            </div>
            <a href="?page=logout" class="flex items-center space-x-2 px-3 py-2 bg-purple-700 rounded-lg hover:bg-purple-600 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                <span class="text-sm">Cerrar Sesión</span>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <div class="lg:pl-64">
        <!-- Header -->
        <header class="bg-white shadow-sm sticky top-0 z-30">
            <div class="flex items-center justify-between p-4">
                <!-- Mobile menu button -->
                <button type="button" id="abrir-menu" aria-label="Abrir menú" aria-expanded="false" aria-controls="barra-lateral" class="lg:hidden text-gray-600 hover:text-gray-900">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>

                <h1 class="text-xl font-bold text-gray-800 lg:text-2xl"><?= e($title ?? 'Dashboard') ?></h1>

                <!-- User menu -->
                <div class="flex items-center space-x-4">
                    <span class="hidden sm:inline text-sm text-gray-600"><?= e($current_user['email']) ?></span>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <main id="contenido-principal" class="p-4 lg:p-6" tabindex="-1">
            <!-- Flash Messages -->
            <?php if ($flash): ?>
                <div class="mb-6 p-4 rounded-lg <?= $flash['type'] === 'error' ? 'bg-red-100 border border-red-400 text-red-800' : ($flash['type'] === 'warning' ? 'bg-yellow-100 border border-yellow-400 text-yellow-800' : 'bg-green-100 border border-green-400 text-green-800') ?>"
                     data-aviso role="status">
                    <div class="flex items-center justify-between">
                        <span><?= e($flash['message']) ?></span>
                        <button type="button" data-cerrar-aviso aria-label="Cerrar aviso" class="ml-4">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Main content from view -->
            <?= $content ?? '' ?>
        </main>

        <!-- Footer -->
        <footer class="p-4 text-center text-sm text-gray-600 border-t">
            <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> - Universidad Distrital Francisco José de Caldas</p>
        </footer>
    </div>

    <?php require BASE_PATH . '/views/componentes/tabbar_privada.php'; ?>

</body>
</html>
