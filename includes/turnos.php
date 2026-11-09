<?php
/**
 * TURNOS DE ATENCIÓN Y ENCUENTRO INICIAL AUTOMÁTICO
 *
 * v3.1 · Fase D · RN-18 (Ac. 002 art. 47.5 · RN-04 · RN-09)
 *
 * El encuentro inicial se crea automáticamente al radicar una denuncia formal
 * con canal_preferido ≠ 'ninguno'. Participantes: solo la persona afectada (o
 * denunciante si es la misma) y el profesional de turno. Nunca la persona
 * denunciada. Si no hay turno o falla la sincronización, el encuentro queda
 * en la bandeja «Por agendar» de la unidad de atención: un encuentro que no
 * se agenda es una persona que no fue escuchada.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Profesional de turno en una sede para una fecha dada
 *
 * @param int|null $unidad_id Sede de atención (null = cualquier turno activo)
 * @param string $fecha 'Y-m-d'
 * @return array|null ['usuario_id', 'hora_inicio', 'hora_fin'] o null
 */
function turno_disponible($unidad_id, $fecha) {
    $dia_semana = (int)date('N', strtotime($fecha)); // 1=lunes … 7=domingo

    $sql = 'SELECT t.usuario_id, t.hora_inicio, t.hora_fin
              FROM turnos_atencion t
              JOIN usuarios u ON u.id = t.usuario_id AND u.activo = 1
             WHERE t.activo = 1 AND t.dia_semana = ?';
    $params = [$dia_semana];

    if ($unidad_id) {
        $sql .= ' AND t.unidad_id = ?';
        $params[] = (int)$unidad_id;
    }

    $fila = db_select_one($sql . ' ORDER BY t.hora_inicio LIMIT 1', $params);

    return $fila ?: null;
}

/**
 * Agendar el encuentro inicial de una denuncia recién radicada (RN-18)
 *
 * Se llama DESPUÉS del COMMIT de la denuncia, nunca dentro (ADR-08). La
 * reunión de Teams no se crea aquí de forma síncrona: crear_encuentro() la
 * delega según GRAPH_ACTIVO y el cron despacha las notificaciones.
 *
 * @param int $denuncia_id
 * @return array ['exito' => bool, 'encuentro_id' => int|null, 'pendiente' => bool]
 */
function agendar_encuentro_inicial($denuncia_id) {
    $denuncia_id = (int)$denuncia_id;

    if (!config_valor('ENCUENTRO_INICIAL_AUTOMATICO', true)) {
        return ['exito' => false, 'encuentro_id' => null, 'pendiente' => false];
    }

    $denuncia = db_select_one(
        'SELECT id, codigo, canal_preferido, unidad_atencion_id FROM denuncias WHERE id = ? LIMIT 1',
        [$denuncia_id]
    );

    if (!$denuncia || $denuncia['canal_preferido'] === 'ninguno') {
        return ['exito' => false, 'encuentro_id' => null, 'pendiente' => false];
    }

    // Participante: la persona afectada; si no está registrada aún, quien
    // denuncia siendo la misma persona. NUNCA la persona denunciada (RN-04).
    $persona = db_select_one(
        "SELECT id, papel FROM personas_caso
          WHERE denuncia_id = ? AND papel IN ('afectada', 'denunciante')
          ORDER BY papel = 'afectada' DESC LIMIT 1",
        [$denuncia_id]
    );

    // Fecha objetivo: siguiente día hábil
    $fecha = sumar_dias_habiles(date('Y-m-d'), 1);
    $turno = turno_disponible($denuncia['unidad_atencion_id'], $fecha);

    if (!$turno) {
        // Sin turno configurado: a la bandeja «Por agendar» (RN-18)
        registrar_encuentro_por_agendar($denuncia_id);
        return ['exito' => false, 'encuentro_id' => null, 'pendiente' => true];
    }

    $duracion = (int)config_valor('ENCUENTRO_INICIAL_DURACION_MIN', 60);
    $inicia = $fecha . ' ' . $turno['hora_inicio'];
    $termina = date('Y-m-d H:i:s', strtotime($inicia) + $duracion * 60);

    $participantes = [
        ['papel' => 'profesional', 'usuario_id' => (int)$turno['usuario_id']],
    ];

    if ($persona) {
        $participantes[] = [
            'papel'      => $persona['papel'] === 'afectada' ? 'afectada' : 'apoyo',
            'persona_id' => (int)$persona['id'],
        ];
    }

    $resultado = crear_encuentro([
        'denuncia_id'    => $denuncia_id,
        'etapa'          => 'encuentro_inicial',
        'modalidad'      => 'virtual',
        'inicia_at'      => $inicia,
        'termina_at'     => $termina,
        'profesional_id' => (int)$turno['usuario_id'],
    ], $participantes);

    if (!$resultado['exito']) {
        registrar_encuentro_por_agendar($denuncia_id);
        return ['exito' => false, 'encuentro_id' => null, 'pendiente' => true];
    }

    return ['exito' => true, 'encuentro_id' => $resultado['encuentro_id'], 'pendiente' => false];
}

/**
 * Dejar constancia de que el encuentro inicial quedó por agendar
 *
 * La bandeja de la unidad de atención lee los seguimientos con este marcador
 * y los encuentros con estado_sync = 'pendiente'.
 */
function registrar_encuentro_por_agendar($denuncia_id) {
    db_insert_simple('seguimientos', [
        'denuncia_id' => (int)$denuncia_id,
        'usuario_id'  => null,
        'tipo'        => 'actualizacion',
        'contenido'   => '[POR AGENDAR] El encuentro inicial no pudo agendarse automáticamente '
                       . '(sin turno disponible o validación de dominio). Requiere agenda manual.',
        'privado'     => 1,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    audit_log(null, 'encuentro_inicial_por_agendar', 'denuncias', [], (int)$denuncia_id);
}

/**
 * Bandeja «Por agendar» de la unidad de atención (RN-18)
 *
 * Casos sin encuentro inicial creado, o con encuentro en error de
 * sincronización, radicados con canal activo.
 *
 * @return array
 */
function encuentros_por_agendar($unidad_atencion_id = null) {
    $sql = "SELECT d.id, d.codigo, d.created_at, d.unidad_atencion_id
              FROM denuncias d
             WHERE d.canal_preferido <> 'ninguno'
               AND d.estado IN ('nueva', 'en_proceso')
               AND NOT EXISTS (
                   SELECT 1 FROM encuentros e
                    WHERE e.denuncia_id = d.id AND e.etapa = 'encuentro_inicial'
                      AND e.estado_sync <> 'error')";
    $params = [];

    if ($unidad_atencion_id) {
        $sql .= ' AND (d.unidad_atencion_id = ? OR d.unidad_atencion_id IS NULL)';
        $params[] = (int)$unidad_atencion_id;
    }

    return db_select($sql . ' ORDER BY d.created_at', $params) ?: [];
}

/**
 * Turnos configurados (consola admin)
 *
 * @return array
 */
function turnos_configurados($solo_activos = true) {
    $sql = 'SELECT t.*, u.nombre AS sede, us.nombre AS profesional
              FROM turnos_atencion t
              JOIN unidades u  ON u.id = t.unidad_id
              JOIN usuarios us ON us.id = t.usuario_id';

    if ($solo_activos) {
        $sql .= ' WHERE t.activo = 1';
    }

    return db_select($sql . ' ORDER BY u.nombre, t.dia_semana, t.hora_inicio') ?: [];
}
