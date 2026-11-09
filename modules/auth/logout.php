<?php
/**
 * MÓDULO: LOGOUT
 *
 * Cerrar sesión y redirigir
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Cerrar sesión
logout();

// Redirigir a login con mensaje
redirect_with_message(url('?page=login'), 'Sesión cerrada correctamente.', 'success');
?>
