<?php
/**
 * COMPONENTE · SALIDA RÁPIDA
 *
 * Fase 7 · CLAUDE.md §10.1 · plan §3.12
 *
 * El comportamiento vive en assets/js/salida_rapida.js. Se separó del HTML
 * para que la política de seguridad de contenido pueda prescindir de
 * 'unsafe-inline' en script-src (hallazgo H-21).
 *
 * Qué hace: botón siempre visible + tecla Esc tres veces → reemplaza la
 * pestaña por el portal de la Universidad y sustituye la entrada del
 * historial, de modo que «atrás» no devuelve aquí.
 *
 * Qué NO puede hacer, y conviene decirlo: no borra el historial completo del
 * navegador —ninguna página web puede—, ni la caché del sistema operativo,
 * ni los registros del proveedor de internet.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

$destino_seguro = 'https://www.udistrital.edu.co';
?>

<!-- Salida rápida -->
<div class="fixed bottom-4 right-4 z-50 print:hidden">
    <button type="button"
            id="salida-rapida"
            data-destino="<?= e($destino_seguro) ?>"
            aria-label="Salir rápidamente de este sitio e ir al portal de la Universidad"
            class="inline-flex items-center gap-2 px-5 py-3 bg-gray-900 hover:bg-black text-white
                   font-semibold rounded-full shadow-2xl transition
                   focus:outline-none focus:ring-4 focus:ring-yellow-400">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
             viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
        </svg>
        Salir rápido
    </button>
    <p class="text-xs text-gray-700 text-right mt-1 bg-white/80 rounded px-2 py-0.5">
        o pulsa <kbd class="font-mono font-semibold">Esc</kbd> tres veces
    </p>
</div>

<script src="<?= e(url('assets/js/salida_rapida.js')) ?>" defer></script>
