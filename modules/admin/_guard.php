<?php
/**
 * GUARDA COMÚN DE LA CONSOLA DE ADMINISTRACIÓN
 *
 * v3.1 · Fase E · §7.5 · El rol `administrador` gestiona configuración,
 * usuarios y catálogos. SIGUE sin poder abrir expediente alguno (§9.1):
 * esa prueba vive en pruebas/reglas/fase9_v31.php.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_auth();

$usuario_actual = auth_user();

if (!in_array($usuario_actual['rol'], ['administrador', 'admin'], true)) {
    audit_log($usuario_actual['id'], 'acceso_denegado_admin', null, [
        'rol'    => $usuario_actual['rol'],
        'motivo' => $_GET['page'] ?? '',
    ]);
    http_response_code(403);
    require BASE_PATH . '/views/errors/404.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
}

$flash_local = null;
