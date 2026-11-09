<?php
/**
 * LAYOUT GUEST - Para páginas públicas (sin autenticación)
 *
 * Variables requeridas:
 * - $title: Título de la página
 * - $content: Contenido principal
 */

if (!defined('BASE_PATH')) {
    die('Access denied');
}

// Fase 7 · las vistas públicas no se guardan en caché: otra persona con acceso
// al mismo equipo no debe recuperarlas con el botón «atrás».
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
}

// El título de la pestaña NO menciona violencia ni denuncia (§10.1): es lo que
// se ve por encima del hombro y en el historial del navegador.
$titulo_pestana = 'Bienestar Universitario — Universidad Distrital';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Portal de Bienestar Universitario">
    <meta name="referrer" content="no-referrer">
    <title><?= e($titulo_pestana) ?></title>

    <?php require BASE_PATH . '/views/componentes/assets.php'; ?>

    <style>
        /* WCAG 2.4.7 · el foco del teclado siempre debe ser visible */
        :focus-visible {
            outline: 3px solid #7e22ce;
            outline-offset: 2px;
        }
        /* WCAG 2.4.1 · enlace para saltar al contenido */
        .saltar-contenido {
            position: absolute;
            left: -9999px;
            top: 0;
            z-index: 100;
        }
        .saltar-contenido:focus {
            left: 0;
            padding: 1rem;
            background: #ffffff;
            color: #6b21a8;
            font-weight: 600;
        }
        /* WCAG 2.3.3 · respetar la preferencia de movimiento reducido */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-purple-50 to-purple-100 min-h-screen">

    <a href="#contenido-principal" class="saltar-contenido">Saltar al contenido principal</a>

    <!-- Header -->
    <header class="bg-white shadow-sm">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <!-- Logo -->
                <a href="?page=home" class="flex items-center space-x-2">
                    <div class="bg-purple-600 text-white rounded-full p-2">
                        <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6z"></path>
                        </svg>
                    </div>
                    <span class="text-xl font-bold text-gray-800"><?= e(APP_NAME) ?></span>
                </a>

                <!-- Navigation -->
                <nav class="hidden md:flex items-center space-x-6" aria-label="Navegación principal">
                    <a href="?page=home" class="text-gray-600 hover:text-purple-600 transition">Inicio</a>
                    <a href="?page=login" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition">
                        Acceder
                    </a>
                </nav>

                <!-- Mobile menu button -->
                <button type="button" id="abrir-menu-movil" class="md:hidden text-gray-600" aria-label="Abrir menú de navegación" aria-expanded="false" aria-controls="menu-movil">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
            </div>

            <!-- Mobile menu -->
            <div id="menu-movil" class="md:hidden mt-4" hidden>
                <nav class="flex flex-col space-y-2">
                    <a href="?page=home" class="block px-4 py-2 text-gray-600 hover:bg-purple-50 rounded">Inicio</a>
                    <a href="?page=login" class="block px-4 py-2 bg-purple-600 text-white rounded text-center">Acceder</a>
                </nav>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main id="contenido-principal" class="container mx-auto px-4 py-8" tabindex="-1">
        <?= $content ?? '' ?>
    </main>

    <!-- Footer -->
    <footer class="bg-white mt-12">
        <div class="container mx-auto px-4 py-8">
            <div class="grid md:grid-cols-3 gap-8 text-center md:text-left">
                <!-- Info -->
                <div>
                    <h3 class="font-bold text-gray-800 mb-3">Botón Violeta</h3>
                    <p class="text-gray-600 text-sm">
                        Sistema de protección y atención integral para casos de violencias de género.
                    </p>
                </div>

                <!-- Contact -->
                <div>
                    <h3 class="font-bold text-gray-800 mb-3">Contacto</h3>
                    <p class="text-gray-600 text-sm">
                        Universidad Distrital Francisco José de Caldas<br>
                        Correo: <a href="mailto:vbgbienestarud@udistrital.edu.co" class="underline hover:text-purple-700">vbgbienestarud@udistrital.edu.co</a><br>
                        Línea de atención: <a href="tel:+576013238309" class="underline hover:text-purple-700">601 323 8309</a>
                    </p>
                </div>

                <!-- Links -->
                <div>
                    <h3 class="font-bold text-gray-800 mb-3">Enlaces</h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="?page=portal&seccion=privacidad" class="text-gray-700 hover:text-purple-700 underline">Política de tratamiento de datos</a></li>
                        <li><a href="?page=portal&seccion=normativa" class="text-gray-700 hover:text-purple-700 underline">Normativa aplicable</a></li>
                        <li><a href="?page=portal" class="text-gray-700 hover:text-purple-700 underline">Caja de herramientas</a></li>
                        <li><a href="?page=portal&seccion=instituciones" class="text-gray-700 hover:text-purple-700 underline">Instituciones de apoyo</a></li>
                    </ul>
                </div>
            </div>

            <div class="border-t border-gray-200 mt-8 pt-6 text-center text-sm text-gray-600">
                <p>&copy; <?= date('Y') ?> Universidad Distrital Francisco José de Caldas</p>
            </div>
        </div>
    </footer>

    <?php require BASE_PATH . '/views/componentes/salida_rapida.php'; ?>

    <?php require BASE_PATH . '/views/componentes/tabbar_publica.php'; ?>

</body>
</html>
