<?php
/**
 * COMPONENTE · TAB BAR INFERIOR DEL PANEL (solo móvil)
 *
 * Rediseño mobile-first (docs/rediseno-movil.md, adaptado del playbook de
 * MisRifas). Una sola base de código: este componente solo aparece ≤768 px;
 * el sidebar de escritorio queda intacto.
 *
 * Reglas aplicadas del playbook:
 *   · Máximo 5 tabs: las 4 primeras entradas del menú del rol + «Menú», que
 *     abre el sidebar existente (no se duplica navegación).
 *   · Estado activo sincronizado con la página actual (servidor, no JS).
 *   · env(safe-area-inset-bottom) para teléfonos con gesto/notch.
 *   · Estilos propios con prefijo .bv-* embebidos aquí: no dependen del CSS
 *     generado ni pueden ser purgados.
 *   · :active con scale(.94): feedback táctil barato.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

$bv_menu = menu_para_rol($current_user['rol']);
$bv_tabs = array_slice($bv_menu, 0, 4, true);
$bv_activa = $_GET['page'] ?? 'dashboard';

// Iconos de trazo por ruta (fallback: documento)
$bv_iconos = [
    'dashboard'       => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3',
    'denuncias'       => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    'mis-compromisos' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
    'ordenes-medida'  => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
    'reportes'        => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    'usuarios'        => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197',
    'perfil'          => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    'denuncia-nueva'  => 'M12 4v16m8-8H4',
];
$bv_icono_defecto = 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z';

// Etiqueta corta de una palabra por ruta
$bv_etiquetas = [
    'dashboard' => 'Inicio', 'denuncias' => 'Expedientes', 'mis-compromisos' => 'Compromisos',
    'ordenes-medida' => 'Órdenes', 'reportes' => 'Reportes', 'usuarios' => 'Usuarios',
    'perfil' => 'Perfil', 'denuncia-nueva' => 'Radicar', 'prevencion-reportes' => 'Anónimos',
    'admin-unidades' => 'Unidades', 'admin-turnos' => 'Turnos',
];
?>
<style>
    /* Tab bar del panel · solo móvil (playbook §1) */
    .bv-tabbar { display: none; }

    @media (max-width: 768px) {
        body { padding-bottom: 78px; }

        .bv-tabbar {
            display: flex;
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 35;
            background: #581c87;
            border-top: 1px solid #6b21a8;
            padding: 6px 4px calc(6px + env(safe-area-inset-bottom, 0px));
            box-shadow: 0 -6px 20px rgba(0, 0, 0, .25);
        }
        .bv-tab {
            flex: 1; display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: 3px; background: none; border: none;
            cursor: pointer; padding: 6px 2px; color: #d8b4fe;
            font-size: 11px; font-weight: 600; border-radius: 12px;
            text-decoration: none;
        }
        .bv-tab svg { width: 22px; height: 22px; }
        .bv-tab:active { transform: scale(.94); }
        .bv-tab--on { color: #ffffff; background: rgba(255, 255, 255, .12); }

        /* App-bar compacto (playbook §11): el header pierde altura en móvil */
        .bv-tabbar ~ * .sticky, header.sticky { --bv-compacto: 1; }
        header .p-4 { padding-top: .65rem; padding-bottom: .65rem; }
    }

    @media (prefers-reduced-motion: reduce) {
        .bv-tab:active { transform: none; }
    }
</style>

<nav class="bv-tabbar" aria-label="Navegación rápida">
    <?php foreach ($bv_tabs as $bv_ruta => $bv_titulo): ?>
        <a href="?page=<?= e($bv_ruta) ?>"
           class="bv-tab <?= $bv_activa === $bv_ruta ? 'bv-tab--on' : '' ?>"
           <?= $bv_activa === $bv_ruta ? 'aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="<?= e($bv_iconos[$bv_ruta] ?? $bv_icono_defecto) ?>"></path>
            </svg>
            <span><?= e($bv_etiquetas[$bv_ruta] ?? $bv_titulo) ?></span>
        </a>
    <?php endforeach; ?>

    <button type="button" class="bv-tab" aria-label="Abrir todo el menú"
            onclick="var b = document.getElementById('abrir-menu'); if (b) { b.click(); }">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"></path>
        </svg>
        <span>Menú</span>
    </button>
</nav>
