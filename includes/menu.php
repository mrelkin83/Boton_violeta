<?php
/**
 * MENÚ LATERAL POR ROL
 *
 * v3.1 · §8 · El menú se construye desde una tabla, no con `if` sueltos.
 *
 * H-25: `unidad_academica` y `talento_humano` iniciaban sesión y no tenían
 * ninguna pantalla. Aquí ven «Órdenes de medida» y «Mi perfil», nada más.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Tabla de menú por rol: [ruta => etiqueta]
 *
 * `administrador` no ve expedientes (§9.1): su menú es la consola.
 * `comision_seguimiento` (Res. 142/2026 art. 13) equivale a `reportes`.
 */
const MENU_POR_ROL = [
    'administrador' => [
        'dashboard'            => 'Inicio',
        'usuarios'             => 'Usuarios',
        'admin-unidades'       => 'Unidades',
        'admin-turnos'         => 'Turnos de atención',
        'admin-catalogo-medidas' => 'Catálogo de medidas',
        'admin-instrumento-riesgo' => 'Instrumento de riesgo',
        'admin-plazos'         => 'Plazos y festivos',
        'admin-destinos-remision' => 'Destinos de remisión',
        'admin-plantillas'     => 'Plantillas',
        'admin-equipo'         => 'Idoneidad del equipo',
        'admin-outbox'         => 'Notificaciones',
        'admin-auditoria'      => 'Auditoría',
        'admin-configuracion'  => 'Configuración',
        'admin-integraciones'  => 'Integraciones',
        'perfil'               => 'Mi perfil',
    ],
    'admin' => [ // rol heredado: mismo alcance que administrador
        'dashboard'            => 'Inicio',
        'usuarios'             => 'Usuarios',
        'admin-unidades'       => 'Unidades',
        'admin-turnos'         => 'Turnos de atención',
        'admin-catalogo-medidas' => 'Catálogo de medidas',
        'admin-instrumento-riesgo' => 'Instrumento de riesgo',
        'admin-plazos'         => 'Plazos y festivos',
        'admin-destinos-remision' => 'Destinos de remisión',
        'admin-plantillas'     => 'Plantillas',
        'admin-equipo'         => 'Idoneidad del equipo',
        'admin-outbox'         => 'Notificaciones',
        'admin-auditoria'      => 'Auditoría',
        'admin-configuracion'  => 'Configuración',
        'admin-integraciones'  => 'Integraciones',
        'perfil'               => 'Mi perfil',
    ],
    'coordinacion_edheg' => [
        'dashboard'            => 'Inicio',
        'denuncias'            => 'Expedientes',
        'denuncia-nueva'       => 'Radicación manual',
        'mis-compromisos'      => 'Mis compromisos',
        'prevencion-reportes'  => 'Reportes anónimos',
        'prevencion-patrones'  => 'Patrones de riesgo',
        'reportes'             => 'Reportes',
        'perfil'               => 'Mi perfil',
    ],
    'coordinador' => [ // rol heredado: mismo alcance que coordinacion_edheg
        'dashboard'            => 'Inicio',
        'denuncias'            => 'Expedientes',
        'denuncia-nueva'       => 'Radicación manual',
        'mis-compromisos'      => 'Mis compromisos',
        'prevencion-reportes'  => 'Reportes anónimos',
        'prevencion-patrones'  => 'Patrones de riesgo',
        'reportes'             => 'Reportes',
        'perfil'               => 'Mi perfil',
    ],
    'psicologo' => [
        'dashboard'       => 'Inicio',
        'denuncias'       => 'Expedientes',
        'mis-compromisos' => 'Mis compromisos',
        'perfil'          => 'Mi perfil',
    ],
    'trabajador_social' => [
        'dashboard'       => 'Inicio',
        'denuncias'       => 'Expedientes',
        'mis-compromisos' => 'Mis compromisos',
        'perfil'          => 'Mi perfil',
    ],
    'abogado' => [
        'dashboard'       => 'Inicio',
        'denuncias'       => 'Expedientes',
        'mis-compromisos' => 'Mis compromisos',
        'perfil'          => 'Mi perfil',
    ],
    'instructor' => [
        'dashboard'       => 'Inicio',
        'denuncias'       => 'Expedientes',
        'mis-compromisos' => 'Mis compromisos',
        'perfil'          => 'Mi perfil',
    ],
    'decisor' => [
        'dashboard'       => 'Inicio',
        'denuncias'       => 'Expedientes',
        'mis-compromisos' => 'Mis compromisos',
        'perfil'          => 'Mi perfil',
    ],
    'unidad_academica' => [
        'ordenes-medida' => 'Órdenes de medida',
        'perfil'         => 'Mi perfil',
    ],
    'talento_humano' => [
        'ordenes-medida' => 'Órdenes de medida',
        'perfil'         => 'Mi perfil',
    ],
    'reportes' => [
        'reportes' => 'Reportes',
        'perfil'   => 'Mi perfil',
    ],
    'comision_seguimiento' => [
        'reportes' => 'Indicadores',
        'perfil'   => 'Mi perfil',
    ],
];

/**
 * Ítems de menú para un rol
 *
 * Falla cerrada: un rol desconocido solo ve su perfil.
 *
 * @return array [ruta => etiqueta]
 */
function menu_para_rol($rol) {
    return MENU_POR_ROL[$rol] ?? ['perfil' => 'Mi perfil'];
}
