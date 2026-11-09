<?php
/**
 * COMPONENTE · TAB BAR DEL SITIO PÚBLICO (solo móvil)
 *
 * Rediseño mobile-first (docs/rediseno-movil.md · playbook §12): un solo
 * partial compartido con estilos + markup; cada página queda marcada activa
 * por la URL. Regla de oro: UNA tab bar por pantalla — este componente vive
 * solo en el layout público (guest.php); el panel autenticado tiene la suya.
 *
 * Discreción (§10.1 del CLAUDE.md): las etiquetas no mencionan violencia ni
 * denuncia — son las mismas palabras neutras del portal.
 *
 * Convivencia con la salida rápida: la tab bar usa z-index 35; el botón de
 * salida rápida y su lógica quedan por encima y siguen funcionando.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

$bvp_activa = $_GET['page'] ?? 'home';

$bvp_tabs = [
    'home' => ['Inicio', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3'],
    'denuncia-publica' => ['Atención', 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z'],
    'seguimiento' => ['Consulta', 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'],
    'portal' => ['Recursos', 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'],
];
?>
<style>
    .bv-tabbar-pub { display: none; }

    @media (max-width: 768px) {
        body { padding-bottom: 78px; }

        .bv-tabbar-pub {
            display: flex;
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 35;
            background: #ffffff;
            border-top: 1px solid #e5e7eb;
            padding: 6px 4px calc(6px + env(safe-area-inset-bottom, 0px));
            box-shadow: 0 -6px 20px rgba(15, 23, 42, .12);
        }
        .bv-tab-pub {
            flex: 1; display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: 3px; background: none; border: none;
            cursor: pointer; padding: 6px 2px; color: #6b7280;
            font-size: 11px; font-weight: 600; border-radius: 12px;
            text-decoration: none;
        }
        .bv-tab-pub svg { width: 22px; height: 22px; }
        .bv-tab-pub:active { transform: scale(.94); }
        .bv-tab-pub--on { color: #7e22ce; background: #faf5ff; }
    }

    @media (prefers-reduced-motion: reduce) {
        .bv-tab-pub:active { transform: none; }
    }
</style>

<nav class="bv-tabbar-pub" aria-label="Navegación del portal">
    <?php foreach ($bvp_tabs as $bvp_ruta => [$bvp_etiqueta, $bvp_icono]): ?>
        <a href="?page=<?= e($bvp_ruta) ?>"
           class="bv-tab-pub <?= $bvp_activa === $bvp_ruta ? 'bv-tab-pub--on' : '' ?>"
           <?= $bvp_activa === $bvp_ruta ? 'aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="<?= e($bvp_icono) ?>"></path>
            </svg>
            <span><?= e($bvp_etiqueta) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
