<?php
/**
 * COMPROMISOS
 *
 * v3.1 · Fase A · Ac. 002 arts. 25, 30, 37b, 44
 *
 * Un compromiso es una obligación concreta, con responsable y plazo, derivada
 * de una medida, de un proceso restaurativo, de una remisión o del
 * seguimiento trimestral (§2: nunca «tarea» ni «pendiente» en la interfaz).
 *
 * RN-16: un compromiso restaurativo exige sanción en firme, consentimiento
 * vigente de la persona afectada y concepto favorable del equipo. El sistema
 * RECHAZA la creación; no la marca con advertencia.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/** Transiciones permitidas de la máquina de estados */
const TRANSICIONES_COMPROMISO = [
    'pendiente'  => ['en_curso', 'cumplido', 'incumplido', 'prorrogado', 'cancelado'],
    'en_curso'   => ['cumplido', 'incumplido', 'prorrogado', 'cancelado'],
    'prorrogado' => ['en_curso', 'cumplido', 'incumplido', 'cancelado'],
    'incumplido' => ['en_curso', 'cumplido'],
    'cumplido'   => [],
    'cancelado'  => [],
];

/**
 * Crear un compromiso
 *
 * @param array $datos denuncia_id, origen, descripcion, fecha_limite,
 *                     responsable_tipo, responsable_usuario_id|responsable_unidad_id|
 *                     responsable_persona_id|responsable_externo,
 *                     medida_id, remision_id,
 *                     (restaurativo) consentimiento_id, sancion_id,
 *                     concepto_favorable_por
 * @param int $usuario_id Quien lo crea
 * @return array ['exito' => bool, 'compromiso_id' => int|null, 'error' => string|null]
 */
function crear_compromiso(array $datos, $usuario_id) {
    $denuncia_id = (int)($datos['denuncia_id'] ?? 0);
    $origen      = $datos['origen'] ?? '';

    if ($denuncia_id <= 0 || !in_array($origen, ['medida', 'restaurativo', 'remision', 'seguimiento'], true)) {
        return ['exito' => false, 'compromiso_id' => null, 'error' => 'Datos del compromiso incompletos.'];
    }

    if (trim((string)($datos['descripcion'] ?? '')) === '') {
        return ['exito' => false, 'compromiso_id' => null, 'error' => 'La descripción es obligatoria.'];
    }

    if (empty($datos['fecha_limite'])) {
        return ['exito' => false, 'compromiso_id' => null, 'error' => 'La fecha límite es obligatoria.'];
    }

    $tipo = $datos['responsable_tipo'] ?? '';
    $tiene_responsable =
        ($tipo === 'usuario'      && !empty($datos['responsable_usuario_id'])) ||
        ($tipo === 'unidad'       && !empty($datos['responsable_unidad_id'])) ||
        ($tipo === 'persona_caso' && !empty($datos['responsable_persona_id'])) ||
        ($tipo === 'externo'      && trim((string)($datos['responsable_externo'] ?? '')) !== '');

    if (!$tiene_responsable) {
        return ['exito' => false, 'compromiso_id' => null, 'error' => 'Todo compromiso exige un responsable.'];
    }

    // ── RN-16 · restaurativo solo con decisión en firme ──────────────────────
    if ($origen === 'restaurativo') {
        $sancion_id = (int)($datos['sancion_id'] ?? 0);
        $sancion = $sancion_id > 0
            ? db_select_one('SELECT en_firme FROM sanciones WHERE id = ? AND denuncia_id = ? LIMIT 1',
                            [$sancion_id, $denuncia_id])
            : null;

        if (!$sancion || (int)$sancion['en_firme'] !== 1) {
            audit_log($usuario_id, 'compromiso_restaurativo_rechazado_rn16', 'compromisos', [
                'motivo' => 'sin_sancion_en_firme',
            ], $denuncia_id);
            return ['exito' => false, 'compromiso_id' => null,
                    'error' => 'RN-16: un compromiso restaurativo exige una sanción o decisión externa en firme (Ac. 002 art. 44).'];
        }

        $consentimiento_id = (int)($datos['consentimiento_id'] ?? 0);
        if ($consentimiento_id <= 0 || !consentimiento_vigente($consentimiento_id)) {
            audit_log($usuario_id, 'compromiso_restaurativo_rechazado_rn16', 'compromisos', [
                'motivo' => 'sin_consentimiento_vigente',
            ], $denuncia_id);
            return ['exito' => false, 'compromiso_id' => null,
                    'error' => 'RN-16: falta el consentimiento vigente de la persona afectada (revocable).'];
        }

        if (empty($datos['concepto_favorable_por'])) {
            audit_log($usuario_id, 'compromiso_restaurativo_rechazado_rn16', 'compromisos', [
                'motivo' => 'sin_concepto_favorable',
            ], $denuncia_id);
            return ['exito' => false, 'compromiso_id' => null,
                    'error' => 'RN-16: falta el concepto favorable del equipo especializado.'];
        }
    }

    $id = db_insert_simple('compromisos', [
        'denuncia_id'            => $denuncia_id,
        'origen'                 => $origen,
        'medida_id'              => !empty($datos['medida_id'])   ? (int)$datos['medida_id']   : null,
        'remision_id'            => !empty($datos['remision_id']) ? (int)$datos['remision_id'] : null,
        'descripcion'            => trim($datos['descripcion']),
        'responsable_tipo'       => $tipo,
        'responsable_usuario_id' => !empty($datos['responsable_usuario_id']) ? (int)$datos['responsable_usuario_id'] : null,
        'responsable_unidad_id'  => !empty($datos['responsable_unidad_id'])  ? (int)$datos['responsable_unidad_id']  : null,
        'responsable_persona_id' => !empty($datos['responsable_persona_id']) ? (int)$datos['responsable_persona_id'] : null,
        'responsable_externo'    => $tipo === 'externo' ? trim($datos['responsable_externo']) : null,
        'fecha_limite'           => $datos['fecha_limite'],
        'estado'                 => 'pendiente',
        'consentimiento_id'      => !empty($datos['consentimiento_id'])      ? (int)$datos['consentimiento_id']      : null,
        'sancion_id'             => !empty($datos['sancion_id'])             ? (int)$datos['sancion_id']             : null,
        'concepto_favorable_por' => !empty($datos['concepto_favorable_por']) ? (int)$datos['concepto_favorable_por'] : null,
        'concepto_favorable_at'  => !empty($datos['concepto_favorable_por']) ? date('Y-m-d H:i:s') : null,
        'created_at'             => date('Y-m-d H:i:s'),
    ]);

    if (!$id) {
        log_error('No se pudo crear el compromiso', 'error');
        return ['exito' => false, 'compromiso_id' => null, 'error' => 'No se pudo crear el compromiso.'];
    }

    audit_log($usuario_id, 'compromiso_creado', 'compromisos', [
        'origen'           => $origen,
        'responsable_tipo' => $tipo,
        'fecha_limite'     => $datos['fecha_limite'],
    ], (int)$id);

    // RN-15 · aviso sin carga al responsable, a sus dos direcciones. Va por
    // el Outbox (ADR-08); el payload solo lleva radicado, plazo y enlace.
    if ($tipo === 'usuario' && function_exists('destinatarios_de_usuario')) {
        $caso = db_select_one('SELECT codigo FROM denuncias WHERE id = ? LIMIT 1', [$denuncia_id]);
        $plantilla = $origen === 'seguimiento' ? 'solicitud_estado' : 'compromiso_asignado';

        encolar_notificacion_grupo(
            destinatarios_de_usuario((int)$datos['responsable_usuario_id']),
            $plantilla,
            [
                'radicado' => $caso['codigo'] ?? 'sin radicado',
                'plazo'    => $datos['fecha_limite'],
                'enlace'   => url('?page=mis-compromisos'),
            ],
            $denuncia_id
        );
    }

    return ['exito' => true, 'compromiso_id' => (int)$id, 'error' => null];
}

/**
 * Cambiar el estado de un compromiso
 *
 * `cumplido` exige constancia (texto o archivo). Toda transición queda en
 * `compromiso_transiciones` y en la auditoría.
 *
 * @param array $extra constancia_texto, constancia_ruta, constancia_hash,
 *                     motivacion, nueva_fecha_limite (prorrogado)
 * @return array ['exito' => bool, 'error' => string|null]
 */
function cambiar_estado_compromiso($compromiso_id, $nuevo_estado, $usuario_id, array $extra = []) {
    $compromiso_id = (int)$compromiso_id;

    $compromiso = db_select_one('SELECT * FROM compromisos WHERE id = ? LIMIT 1', [$compromiso_id]);

    if (!$compromiso) {
        return ['exito' => false, 'error' => 'Compromiso no encontrado.'];
    }

    $permitidas = TRANSICIONES_COMPROMISO[$compromiso['estado']] ?? [];

    if (!in_array($nuevo_estado, $permitidas, true)) {
        return ['exito' => false,
                'error' => 'Transición no permitida: ' . $compromiso['estado'] . ' → ' . $nuevo_estado . '.'];
    }

    $constancia_texto = trim((string)($extra['constancia_texto'] ?? ''));

    if ($nuevo_estado === 'cumplido' && $constancia_texto === '' && empty($extra['constancia_ruta'])) {
        return ['exito' => false,
                'error' => 'Marcar un compromiso como cumplido exige registrar la constancia.'];
    }

    if (in_array($nuevo_estado, ['cancelado', 'prorrogado', 'incumplido'], true)
        && trim((string)($extra['motivacion'] ?? '')) === '') {
        return ['exito' => false, 'error' => 'Esta transición exige motivación.'];
    }

    $cambios = ['estado' => $nuevo_estado];

    if ($constancia_texto !== '') {
        $cambios['constancia_texto'] = $constancia_texto;
    }
    if (!empty($extra['constancia_ruta'])) {
        $cambios['constancia_ruta'] = $extra['constancia_ruta'];
        $cambios['constancia_hash'] = $extra['constancia_hash'] ?? null;
    }
    if ($nuevo_estado === 'cumplido') {
        $cambios['verificado_por'] = (int)$usuario_id;
        $cambios['verificado_at']  = date('Y-m-d H:i:s');
    }
    if ($nuevo_estado === 'prorrogado' && !empty($extra['nueva_fecha_limite'])) {
        $cambios['fecha_limite'] = $extra['nueva_fecha_limite'];
    }

    try {
        db_begin_transaction();

        if (db_update_simple('compromisos', $cambios, ['id' => $compromiso_id]) === false) {
            throw new RuntimeException('No se pudo actualizar el compromiso');
        }

        db_insert_simple('compromiso_transiciones', [
            'compromiso_id' => $compromiso_id,
            'desde'         => $compromiso['estado'],
            'hasta'         => $nuevo_estado,
            'usuario_id'    => (int)$usuario_id,
            'motivacion'    => trim((string)($extra['motivacion'] ?? '')) ?: null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        db_commit();
    } catch (Exception $e) {
        db_rollback();
        log_error('Error en transición de compromiso: ' . $e->getMessage(), 'error');
        return ['exito' => false, 'error' => 'No se pudo cambiar el estado.'];
    }

    audit_log($usuario_id, 'compromiso_transicion', 'compromisos', [
        'desde' => $compromiso['estado'],
        'hasta' => $nuevo_estado,
    ], $compromiso_id);

    return ['exito' => true, 'error' => null];
}

/**
 * Responder una solicitud de estado (§16 E5)
 *
 * La dependencia responde desde su bandeja, sin acceder al expediente.
 *
 * @return array ['exito' => bool, 'error' => string|null]
 */
function responder_compromiso($compromiso_id, $respuesta, $usuario_id) {
    $compromiso_id = (int)$compromiso_id;
    $respuesta = trim((string)$respuesta);

    if ($respuesta === '') {
        return ['exito' => false, 'error' => 'La respuesta no puede estar vacía.'];
    }

    $compromiso = db_select_one(
        "SELECT id, origen, estado FROM compromisos WHERE id = ? AND origen = 'seguimiento' LIMIT 1",
        [$compromiso_id]
    );

    if (!$compromiso) {
        return ['exito' => false, 'error' => 'Solicitud de estado no encontrada.'];
    }

    $ok = db_update_simple('compromisos', [
        'respuesta_texto' => $respuesta,
        'respondido_at'   => date('Y-m-d H:i:s'),
    ], ['id' => $compromiso_id]);

    if ($ok === false) {
        return ['exito' => false, 'error' => 'No se pudo registrar la respuesta.'];
    }

    audit_log($usuario_id, 'solicitud_estado_respondida', 'compromisos', [], $compromiso_id);

    // Con la respuesta registrada, el compromiso queda cumplido
    return cambiar_estado_compromiso($compromiso_id, 'cumplido', $usuario_id, [
        'constancia_texto' => 'Respuesta de la dependencia registrada.',
    ]);
}

/**
 * Compromisos de un caso
 *
 * @return array
 */
function compromisos_del_caso($denuncia_id, $estado = null) {
    $sql = 'SELECT c.*, u.nombre AS unidad_nombre, us.nombre AS usuario_nombre
              FROM compromisos c
              LEFT JOIN unidades u  ON c.responsable_unidad_id  = u.id
              LEFT JOIN usuarios us ON c.responsable_usuario_id = us.id
             WHERE c.denuncia_id = ?';
    $params = [(int)$denuncia_id];

    if ($estado !== null) {
        $sql .= ' AND c.estado = ?';
        $params[] = $estado;
    }

    return db_select($sql . ' ORDER BY c.fecha_limite, c.id', $params) ?: [];
}

/**
 * Bandeja personal: compromisos donde el usuario (o su unidad) es responsable
 *
 * @return array
 */
function compromisos_de_usuario($usuario_id, $unidad_id = null) {
    $sql = 'SELECT c.*, d.codigo AS radicado
              FROM compromisos c
              JOIN denuncias d ON d.id = c.denuncia_id
             WHERE (c.responsable_usuario_id = ?';
    $params = [(int)$usuario_id];

    if ($unidad_id) {
        $sql .= ' OR c.responsable_unidad_id = ?';
        $params[] = (int)$unidad_id;
    }

    $sql .= ") AND c.estado IN ('pendiente','en_curso','prorrogado','incumplido')
             ORDER BY c.fecha_limite, c.id";

    return db_select($sql, $params) ?: [];
}

/**
 * Semáforo de plazo (§9 v3.1): verde > 3 días hábiles · ámbar ≤ 3 · rojo vencido
 *
 * @return string 'verde'|'ambar'|'rojo'
 */
function semaforo_compromiso($fecha_limite) {
    if (esta_vencido($fecha_limite)) {
        return 'rojo';
    }
    return dias_restantes($fecha_limite) <= 3 ? 'ambar' : 'verde';
}
