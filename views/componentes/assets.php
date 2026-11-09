<?php
/**
 * COMPONENTE · CARGA DE ESTILOS Y SCRIPTS
 *
 * Punto único donde se decide de dónde vienen los recursos del frontend.
 * Antes esta decisión estaba repetida en tres layouts, lo que hacía que
 * cambiarla obligara a editar los tres y arriesgarse a dejar uno atrás.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ ⚠ HALLAZGO H-21 REABIERTO — decisión de Elkin, 26 de julio de 2026  │
 * │                                                                     │
 * │ CLAUDE.md §5 y la prohibición 8 exigen «TailwindCSS compilado a CSS │
 * │ estático local» y prohíben los CDN en producción.                   │
 * │                                                                     │
 * │ Se construyó ese CSS local (assets/css/app.css, 17 KB, generado por │
 * │ herramientas/generar_css.php) y alcanzó el 100 % de cobertura de    │
 * │ clases, pero el resultado visual no fue aceptable: replicar a mano  │
 * │ ~300 utilidades de Tailwind produce un parecido, no una             │
 * │ equivalencia. Un frontend roto en un sistema al que acude una       │
 * │ persona en crisis es peor problema que una dependencia de CDN.      │
 * │                                                                     │
 * │ Coste asumido conscientemente:                                      │
 * │   · Dependencia de red: si el CDN cae, el sitio pierde el estilo    │
 * │   · La CSP vuelve a necesitar 'unsafe-inline'                       │
 * │   · ~3 MB por visita en vez de 17 KB                                │
 * │                                                                     │
 * │ Camino de vuelta: el CSS local y su generador NO se han eliminado.  │
 * │ Poner ASSETS_CDN en false los reactiva. Cerrar H-21 de verdad       │
 * │ requiere compilar Tailwind con su cadena de herramientas y          │
 * │ versionar el CSS resultante — lo que exige levantar la prohibición  │
 * │ de NPM como dependencia de construcción (no de ejecución).          │
 * ├─────────────────────────────────────────────────────────────────────┤
 * │ ACTUALIZACIÓN — decisión de Elkin, 2 de septiembre de 2026          │
 * │                                                                     │
 * │ Se volvió al CSS local: ASSETS_CDN=false en la configuración del    │
 * │ despliegue (AWS) y en el entorno local. La auditoría de frontend    │
 * │ reporta cobertura del 100 % (0 clases sin regla). Si se añade una   │
 * │ clase nueva a una vista, ejecutar herramientas/generar_css.php y    │
 * │ volver a correr herramientas/auditar_frontend.php.                  │
 * └─────────────────────────────────────────────────────────────────────┘
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

if (usar_assets_cdn()):
?>
    <!-- Tailwind desde CDN · H-21 reabierto (ver cabecera del componente) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <?php
    /*
     * Alpine.js se retiró el 26 de julio de 2026.
     *
     * Se seguía descargando en cada página desde un segundo CDN mientras no
     * quedaba ni una directiva suya en el proyecto: `assets/js/app.js` había
     * asumido el menú, el foco y los estados ARIA, que es más de lo que Alpine
     * hacía. Eran 40 KB y un tercero con capacidad de ejecutar código en un
     * sistema que maneja relatos de violencia, a cambio de nada.
     *
     * Se comprobó antes de quitarlo: `grep` de x-data, x-show, x-on, x-model,
     * x-if, x-transition y @click sobre views/ y modules/ no devuelve nada.
     */
    ?>
    <script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
<?php else: ?>
    <!-- CSS y JS locales · sin dependencias de red -->
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
<?php endif; ?>

<style>
    /* Accesibilidad · se aplica en ambos modos (WCAG 2.4.1 y 2.4.7) */
    :focus-visible { outline: 3px solid #7e22ce; outline-offset: 2px; }
    .saltar-contenido { position: absolute; left: -9999px; top: 0; z-index: 100; }
    .saltar-contenido:focus {
        left: 0; padding: 1rem; background: #ffffff; color: #6b21a8; font-weight: 600;
    }
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: 0.01ms !important;
            transition-duration: 0.01ms !important;
        }
    }
</style>
