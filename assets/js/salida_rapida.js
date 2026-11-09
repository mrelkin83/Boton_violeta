/**
 * BOTÓN VIOLETA · Salida rápida
 *
 * Extraído del componente en línea para que la política de seguridad de
 * contenido pueda prescindir de 'unsafe-inline' en script-src (H-21).
 *
 * Una persona puede estar denunciando desde un dispositivo compartido con
 * quien la agrede, o desde una sala donde alguien puede entrar en cualquier
 * momento. Este script existe para ese momento.
 *
 * Lo que NO puede hacer, y conviene no prometer: no borra el historial
 * completo del navegador —ninguna página web puede—, ni la caché del sistema
 * operativo, ni los registros del proveedor de internet.
 */
(function () {
    'use strict';

    var boton = document.getElementById('salida-rapida');
    if (!boton) { return; }

    var DESTINO = boton.getAttribute('data-destino') || 'https://www.udistrital.edu.co';

    function salir() {
        try {
            // Se deja el historial en un punto neutro antes de salir
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', DESTINO);
            }
        } catch (e) { /* sin efecto si el navegador lo impide */ }

        // replace() y no assign(): así el botón «atrás» no vuelve aquí
        window.location.replace(DESTINO);
    }

    boton.addEventListener('click', salir);

    // Esc tres veces en menos de dos segundos.
    // Se exigen tres para no disparar la salida al cerrar un desplegable.
    var pulsaciones = 0;
    var temporizador = null;

    document.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Escape' && evento.key !== 'Esc') { return; }

        pulsaciones++;

        if (temporizador) { clearTimeout(temporizador); }
        temporizador = setTimeout(function () { pulsaciones = 0; }, 2000);

        if (pulsaciones >= 3) { salir(); }
    });
})();
