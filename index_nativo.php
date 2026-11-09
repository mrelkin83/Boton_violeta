<?php
/**
 * PUNTO DE ENTRADA DEL SISTEMA - BOTÓN VIOLETA
 *
 * Sistema 100% nativo PHP sin dependencias externas
 * NO requiere Composer, NPM ni build tools
 */

// Definir ruta base
define('BASE_PATH', __DIR__);

// Cargar sistema
require_once BASE_PATH . '/init.php';

// Obtener página solicitada
$page = $_GET['page'] ?? 'home';

// Rutas del sistema
$routes = [
    'home' => 'views/home.php',
    'login' => 'modules/auth/login.php',
    'logout' => 'modules/auth/logout.php',
    'recuperar' => 'modules/auth/recuperar.php', // H-09
    'denuncia-publica' => 'modules/denuncias/publica.php', // RUTA PÚBLICA - Formulario de denuncia
    'seguimiento' => 'modules/denuncias/seguimiento.php', // RUTA PÚBLICA - Seguimiento de caso
    'dashboard' => 'modules/dashboard/index.php',
    'denuncias' => 'modules/denuncias/index.php',
    'denuncia-nueva' => 'modules/denuncias/nueva.php',
    'denuncia-ver' => 'modules/denuncias/ver.php',
    'usuarios' => 'modules/usuarios/index.php',
    'usuario-editar' => 'modules/usuarios/editar.php',
    'portal' => 'modules/portal/index.php', // RUTA PÚBLICA - Ac. 002 art. 19
    'perfil' => 'modules/perfil/index.php',
    'denuncia-imprimir' => 'modules/denuncias/imprimir.php',

    // ── v3.1 · Fases A–G (§8) ───────────────────────────────────────────────
    'expediente'          => 'modules/expediente/index.php',   // despacha por ?tab=
    'oficio-medida'       => 'modules/expediente/oficio_medida.php',   // documento imprimible §10.4
    'oficio-remision'     => 'modules/expediente/oficio_remision.php', // documento imprimible §7.2
    'ordenes-medida'      => 'modules/ordenes_medida/index.php',
    'mis-compromisos'     => 'modules/compromisos/mios.php',
    'reportes'            => 'modules/reportes/index.php',
    'reporte-anonimo'     => 'modules/prevencion/publico.php', // RUTA PÚBLICA - Ac. 002 art. 33
    'prevencion-reportes' => 'modules/prevencion/reportes_anonimos.php',
    'prevencion-patrones' => 'modules/prevencion/patrones.php',
    // Consola de administración (§7.5) — el rol administrador sigue sin ver expedientes
    'admin-unidades'           => 'modules/admin/unidades.php',
    'admin-turnos'             => 'modules/admin/turnos.php',
    'admin-catalogo-medidas'   => 'modules/admin/catalogo_medidas.php',
    'admin-instrumento-riesgo' => 'modules/admin/instrumento_riesgo.php',
    'admin-plazos'             => 'modules/admin/plazos.php',
    'admin-destinos-remision'  => 'modules/admin/destinos_remision.php',
    'admin-plantillas'         => 'modules/admin/plantillas.php',
    'admin-equipo'             => 'modules/admin/equipo.php',
    'admin-outbox'             => 'modules/admin/outbox.php',
    'admin-auditoria'          => 'modules/admin/auditoria.php',
    'admin-configuracion'      => 'modules/admin/configuracion.php',
    'admin-integraciones'      => 'modules/admin/integraciones.php',
];

// `denuncia-ver` se conserva como alias del expediente (v3.1 §8)
if ($page === 'denuncia-ver' && isset($_GET['id'])) {
    header('Location: ?page=expediente&id=' . (int)$_GET['id'] . '&tab=resumen');
    exit;
}

// Verificar si la ruta existe
if (!isset($routes[$page])) {
    $page = '404';
}

// Cargar vista
$view_path = BASE_PATH . '/' . ($page === '404' ? 'views/errors/404.php' : $routes[$page]);

if (file_exists($view_path)) {
    require_once $view_path;
} else {
    http_response_code(404);
    echo '<h1>404 - Página no encontrada</h1>';
}

// Finalizar buffer
ob_end_flush();
?>
