/**
 * BOTÓN VIOLETA · JavaScript de la aplicación
 *
 * Cierra el hallazgo H-21 junto con assets/css/app.css.
 *
 * Sustituye a Alpine.js, que se cargaba desde CDN. CLAUDE.md §5 exige
 * «JavaScript vanilla» y prohíbe los CDN en producción (prohibición 8).
 *
 * Todo el comportamiento que Alpine aportaba —menú lateral, menú móvil y
 * cierre de avisos— cabe en menos de cien líneas sin dependencias, y de paso
 * permite eliminar 'unsafe-inline' de la política de seguridad de contenido:
 * al no haber scripts en línea, la CSP puede restringirse a 'self'.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ── Menú lateral (vista autenticada) ─────────────────────────────
        var barraLateral = document.getElementById('barra-lateral');
        var velo         = document.getElementById('velo-lateral');
        var abrir        = document.getElementById('abrir-menu');
        var cerrar       = document.getElementById('cerrar-menu');

        function mostrarLateral(visible) {
            if (!barraLateral) { return; }

            barraLateral.classList.toggle('-translate-x-full', !visible);
            barraLateral.classList.toggle('translate-x-0', visible);

            if (velo) { velo.hidden = !visible; }

            if (abrir) { abrir.setAttribute('aria-expanded', visible ? 'true' : 'false'); }

            // El foco acompaña a la acción: quien navega con teclado no debe
            // quedarse detrás de un panel que acaba de abrirse
            if (visible && cerrar) { cerrar.focus(); }
            else if (!visible && abrir) { abrir.focus(); }
        }

        if (abrir)  { abrir.addEventListener('click', function () { mostrarLateral(true); }); }
        if (cerrar) { cerrar.addEventListener('click', function () { mostrarLateral(false); }); }
        if (velo)   { velo.addEventListener('click', function () { mostrarLateral(false); }); }

        // ── Menú móvil (vista pública) ───────────────────────────────────
        var abrirMovil = document.getElementById('abrir-menu-movil');
        var menuMovil  = document.getElementById('menu-movil');

        if (abrirMovil && menuMovil) {
            abrirMovil.addEventListener('click', function () {
                var visible = menuMovil.hidden;
                menuMovil.hidden = !visible;
                abrirMovil.setAttribute('aria-expanded', visible ? 'true' : 'false');
            });
        }

        // ── Cerrar avisos ────────────────────────────────────────────────
        var botonesCerrarAviso = document.querySelectorAll('[data-cerrar-aviso]');

        Array.prototype.forEach.call(botonesCerrarAviso, function (boton) {
            boton.addEventListener('click', function () {
                var aviso = boton.closest('[data-aviso]');
                if (aviso) { aviso.hidden = true; }
            });
        });

        // ── Escape cierra lo que esté abierto ────────────────────────────
        // Solo si NO hay salida rápida en la página: allí Esc tiene otro
        // significado y no debe competir con él.
        document.addEventListener('keydown', function (evento) {
            if (evento.key !== 'Escape' && evento.key !== 'Esc') { return; }
            if (document.getElementById('salida-rapida')) { return; }

            if (barraLateral && !barraLateral.classList.contains('-translate-x-full')) {
                mostrarLateral(false);
            }
            if (menuMovil && !menuMovil.hidden) {
                menuMovil.hidden = true;
                if (abrirMovil) { abrirMovil.setAttribute('aria-expanded', 'false'); }
            }
        });
    });
})();
