<?php
/**
 * VISTA: HOME PAGE
 *
 * Página de inicio pública
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Si ya está autenticado, redirigir al dashboard
if (is_authenticated()) {
    redirect(url('?page=dashboard'));
}

$title = 'Inicio';
ob_start();
?>

<!--
    Hero
    ────
    `min-w-0` en los hijos del grid no es decorativo: sin él, un elemento de
    cuadrícula no puede encogerse por debajo de su contenido mínimo. En una
    pantalla de 360 px la columna medía 367 y el contenedor la recortaba con
    `overflow-hidden`, así que el título, el buscador de radicado y el botón
    aparecían cortados por la derecha — y sin barra de desplazamiento que
    delatara el problema: `scrollWidth` seguía igual a `clientWidth`.

    Por eso el tamaño del título también arranca menor y crece a partir de `md`.
-->
<div class="bg-white rounded-lg shadow-xl overflow-hidden mb-12">
    <div class="grid md:grid-cols-2 gap-8 items-center p-6 sm:p-8 md:p-12">
        <div class="min-w-0">
            <h1 class="text-3xl sm:text-4xl md:text-5xl font-bold text-gray-900 mb-4 break-words">
                Sistema de Protección Integral
            </h1>
            <p class="text-xl text-gray-600 mb-6">
                Espacio seguro y confidencial para la atención de casos de violencias de género en nuestra comunidad universitaria.
            </p>

            <!-- Sistema de Seguimiento -->
            <div class="bg-gray-50 rounded-lg p-6 mb-4">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">Rastrear Seguimiento del Caso</h3>
                <form action="?page=seguimiento" method="GET" class="flex flex-col sm:flex-row gap-2">
                    <input type="hidden" name="page" value="seguimiento">
                    <?php
                    /*
                     * `min-w-0` en el campo y `flex-col` hasta `sm`.
                     *
                     * Un <input> trae un ancho mínimo propio de unos veinte
                     * caracteres. Dentro de un flex sin `min-w-0` ese mínimo
                     * manda, así que el campo y el botón «Buscar» no cabían en
                     * 360 px y se salían del recuadro. En pantalla estrecha se
                     * apilan, que además es más fácil de usar con el pulgar.
                     */
                    ?>
                    <input
                        type="text"
                        name="codigo"
                        placeholder="Ingresa tu código de radicado"
                        required
                        class="flex-1 min-w-0 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                        Buscar
                    </button>
                </form>
                <p class="text-sm text-gray-500 mt-2">Ejemplo: BV-20260324-0001</p>
            </div>

            <!-- Botón Login Equipo -->
            <a href="?page=login" class="inline-flex items-center justify-center px-6 py-3 bg-gray-700 hover:bg-gray-800 text-white font-medium rounded-lg transition">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                </svg>
                Equipo Administrativo
            </a>
        </div>

        <!-- Botón Violeta Grande -->
        <div class="flex items-center justify-center min-w-0">
            <a href="?page=denuncia-publica" class="block w-full transform hover:scale-105 transition-transform">
                <div class="bg-gradient-to-br from-purple-500 to-purple-700 rounded-2xl p-6 sm:p-8 md:p-12 text-center shadow-2xl hover:shadow-purple-500/50 cursor-pointer">
                    <svg class="w-24 h-24 md:w-32 md:h-32 text-white mx-auto mb-4 animate-pulse" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6z"></path>
                        <path d="M10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"></path>
                    </svg>
                    <p class="text-white text-3xl font-bold mb-2">Botón Violeta</p>
                    <p class="text-purple-100 text-xl mb-4">Estamos Aquí Para Ayudarte</p>
                    <div class="bg-white/20 backdrop-blur-sm rounded-lg px-6 py-3 inline-block">
                        <p class="text-white font-medium">HAZ CLIC AQUÍ PARA DENUNCIAR</p>
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Características -->
<div class="grid md:grid-cols-3 gap-8 mb-12">
    <div class="bg-white rounded-lg shadow p-6">
        <div class="bg-purple-100 rounded-full w-12 h-12 flex items-center justify-center mb-4">
            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
            </svg>
        </div>
        <h3 class="text-xl font-bold text-gray-900 mb-2">Confidencialidad</h3>
        <p class="text-gray-600">
            Toda la información es tratada con estricta confidencialidad y protección de datos personales.
        </p>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="bg-purple-100 rounded-full w-12 h-12 flex items-center justify-center mb-4">
            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
        </div>
        <h3 class="text-xl font-bold text-gray-900 mb-2">Equipo Multidisciplinario</h3>
        <p class="text-gray-600">
            Profesionales especializados en psicología, trabajo social y asesoría jurídica.
        </p>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="bg-purple-100 rounded-full w-12 h-12 flex items-center justify-center mb-4">
            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
            </svg>
        </div>
        <h3 class="text-xl font-bold text-gray-900 mb-2">Protección Integral</h3>
        <p class="text-gray-600">
            Acompañamiento completo desde la recepción hasta el seguimiento del caso.
        </p>
    </div>
</div>

<!-- Información de Contacto -->
<div id="contacto" class="bg-white rounded-lg shadow p-8 md:p-12">
    <div class="max-w-3xl mx-auto text-center">
        <h2 class="text-3xl font-bold text-gray-900 mb-4">¿Necesitas Ayuda?</h2>
        <p class="text-gray-600 mb-8">
            Estamos disponibles para escucharte y brindarte el apoyo que necesitas.
        </p>

        <div class="grid md:grid-cols-2 gap-6 text-left">
            <div class="bg-purple-50 rounded-lg p-6">
                <h3 class="font-bold text-gray-900 mb-3">Línea de Atención</h3>
                <p class="text-gray-700 mb-2">
                    <strong>Teléfono:</strong> 601 323 8309
                </p>
                <p class="text-gray-700 mb-2">
                    <strong>WhatsApp:</strong> 601 323 8309
                </p>
                <p class="text-gray-600 text-sm">
                    Atención: Lunes a Viernes<br>
                    Horario: 8:00 AM - 6:00 PM
                </p>
            </div>

            <div class="bg-purple-50 rounded-lg p-6">
                <h3 class="font-bold text-gray-900 mb-3">Ubicación</h3>
                <p class="text-gray-700 mb-2">
                    Universidad Distrital Francisco José de Caldas<br>
                    Edificio Administrativo, Piso 3<br>
                    Oficina Botón Violeta
                </p>
                <p class="text-gray-700">
                    <?php // break-all: el correo es una sola palabra de 32 caracteres y se salía del recuadro ?>
                    <strong>Email:</strong> <span class="break-all">vbgbienestarud@udistrital.edu.co</span>
                </p>
            </div>
        </div>

        <div class="mt-8 p-6 bg-yellow-50 border-l-4 border-yellow-400 rounded">
            <div class="flex items-start">
                <svg class="w-6 h-6 text-yellow-600 mr-3 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
                <div class="flex-1 text-left">
                    <p class="text-sm font-medium text-yellow-900">Emergencia</p>
                    <p class="text-sm text-yellow-700 mt-1">
                        Si estás en peligro inmediato, llama al <strong>123</strong> (Línea de emergencias) o acude a la estación de policía más cercana.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/guest.php';
?>
