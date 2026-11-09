<?php
/**
 * SANCIONES
 *
 * v3.1 · Fase B · Ac. 003 art. 41 (registro de sanciones en firme)
 *
 * Solo `decisor` o `coordinacion_edheg` registran. Alimenta los reportes del
 * art. 31 (trimestral), art. 40 (anual) y el indicador 3.4.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Registrar una sanción
 *
 * @param array $datos denuncia_id, autoridad, tipo, acto_numero, acto_fecha,
 *                     documento_ruta, documento_hash
 * @return array ['exito' => bool, 'sancion_id' => int|null, 'error' => string|null]
 */
function registrar_sancion(array $datos, $usuario_id, $rol) {
    if (!in_array($rol, ['decisor', 'coordinacion_edheg', 'coordinador'], true)) {
        return ['exito' => false, 'sancion_id' => null,
                'error' => 'Solo el rol decisor o la coordinación registran sanciones.'];
    }

    $denuncia_id = (int)($datos['denuncia_id'] ?? 0);

    if ($denuncia_id <= 0
        || trim((string)($datos['autoridad'] ?? '')) === ''
        || trim((string)($datos['tipo'] ?? '')) === ''
        || empty($datos['acto_fecha'])) {
        return ['exito' => false, 'sancion_id' => null, 'error' => 'Datos de la sanción incompletos.'];
    }

    $id = db_insert_simple('sanciones', [
        'denuncia_id'    => $denuncia_id,
        'autoridad'      => trim($datos['autoridad']),
        'tipo'           => trim($datos['tipo']),
        'acto_numero'    => trim((string)($datos['acto_numero'] ?? '')) ?: null,
        'acto_fecha'     => $datos['acto_fecha'],
        'en_firme'       => 0,
        'registrada_por' => (int)$usuario_id,
        'documento_ruta' => $datos['documento_ruta'] ?? null,
        'documento_hash' => $datos['documento_hash'] ?? null,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);

    if (!$id) {
        return ['exito' => false, 'sancion_id' => null, 'error' => 'No se pudo registrar la sanción.'];
    }

    audit_log($usuario_id, 'sancion_registrada', 'sanciones', [
        'autoridad' => trim($datos['autoridad']),
        'tipo'      => trim($datos['tipo']),
    ], (int)$id);

    return ['exito' => true, 'sancion_id' => (int)$id, 'error' => null];
}

/**
 * Marcar una sanción como en firme (ejecutoriada)
 *
 * @return array ['exito' => bool, 'error' => string|null]
 */
function marcar_sancion_firme($sancion_id, $fecha_firmeza, $usuario_id, $rol) {
    if (!in_array($rol, ['decisor', 'coordinacion_edheg', 'coordinador'], true)) {
        return ['exito' => false, 'error' => 'Solo el rol decisor o la coordinación registran la firmeza.'];
    }

    $sancion = db_select_one('SELECT id, en_firme FROM sanciones WHERE id = ? LIMIT 1', [(int)$sancion_id]);

    if (!$sancion) {
        return ['exito' => false, 'error' => 'Sanción no encontrada.'];
    }

    if ((int)$sancion['en_firme'] === 1) {
        return ['exito' => false, 'error' => 'La sanción ya está en firme.'];
    }

    $ok = db_update_simple('sanciones', [
        'en_firme'    => 1,
        'en_firme_at' => $fecha_firmeza ?: date('Y-m-d'),
    ], ['id' => (int)$sancion_id]);

    if ($ok === false) {
        return ['exito' => false, 'error' => 'No se pudo registrar la firmeza.'];
    }

    audit_log($usuario_id, 'sancion_en_firme', 'sanciones', [
        'en_firme_at' => $fecha_firmeza ?: date('Y-m-d'),
    ], (int)$sancion_id);

    return ['exito' => true, 'error' => null];
}

/**
 * ¿El expediente tiene alguna sanción o decisión externa en firme? (RN-16)
 *
 * @return int|null id de la sanción en firme, o null
 */
function sancion_en_firme($denuncia_id) {
    $fila = db_select_one(
        'SELECT id FROM sanciones WHERE denuncia_id = ? AND en_firme = 1 LIMIT 1',
        [(int)$denuncia_id]
    );

    return $fila ? (int)$fila['id'] : null;
}

/**
 * Sanciones de un caso
 *
 * @return array
 */
function sanciones_del_caso($denuncia_id) {
    return db_select(
        'SELECT * FROM sanciones WHERE denuncia_id = ? ORDER BY acto_fecha DESC',
        [(int)$denuncia_id]
    ) ?: [];
}
