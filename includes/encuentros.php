<?php
/**
 * AGENDA DE ENCUENTROS
 *
 * Fase 4 · CLAUDE.md §12.2 · Protocolo etapas E2 y E3
 * Implementa RN-04 (no confrontación) y RN-05 (prohibición de contacto).
 *
 * ⚠ RN-04 · Ac. 002 art. 47.5
 *   El sistema RECHAZA la creación de cualquier encuentro que reúna
 *   simultáneamente a la persona afectada y a la persona denunciada.
 *   Validación dura, sin opción de forzar, sin parámetro de excepción.
 *
 *   No es una advertencia que alguien pueda desestimar con prisa: obligar a
 *   una víctima a estar en la misma sala que quien denunció es exactamente lo
 *   que el Acuerdo prohíbe, y una interfaz que lo permita «con confirmación»
 *   acabará permitiéndolo.
 *
 * Modalidades presencial y telefónica son de primera clase: una persona puede
 * no tener conectividad, no tener cuenta institucional activa, o no querer que
 * el encuentro aparezca en un calendario compartido. El sistema agenda igual,
 * solo que sin evento en Graph.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/** Áreas admitidas en el asunto de la reunión (plantilla fija, RN-09) */
const AREAS_ENCUENTRO = [
    'encuentro_inicial'     => 'Acogida',
    'atencion_psicologica'  => 'Psicología',
    'atencion_juridica'     => 'Jurídica',
    'trabajo_social'        => 'Trabajo social',
    'salud'                 => 'Salud',
    'revision_trimestral'   => 'Seguimiento',
    'audiencia'             => 'Audiencia',
];

/**
 * Verificar RN-04 sobre un conjunto de participantes
 *
 * @param array $participantes [['papel' => 'afectada'|'denunciada'|…], …]
 * @return array ['permitido' => bool, 'motivo' => string]
 */
function verificar_no_confrontacion(array $participantes) {
    $papeles = array_column($participantes, 'papel');

    $hay_afectada  = in_array('afectada', $papeles, true);
    $hay_denunciada = in_array('denunciada', $papeles, true);

    if ($hay_afectada && $hay_denunciada) {
        return [
            'permitido' => false,
            'motivo'    => 'RN-04: no se puede citar a la persona afectada y a la persona '
                         . 'denunciada al mismo encuentro (Acuerdo 002 de 2026, art. 47.5, '
                         . 'no confrontación obligatoria).',
        ];
    }

    return ['permitido' => true, 'motivo' => ''];
}

/**
 * Verificar RN-05 · prohibición de contacto
 *
 * Cuando existe una medida activa de prohibición de contacto (Ac. 002 art. 26
 * par. lit. h), el sistema no habilita ningún espacio compartido entre las
 * partes y registra cualquier intento.
 *
 * Completada en la Fase 5, cuando pasó a existir la tabla `medidas`.
 *
 * Falla cerrada: si la consulta de medidas falla,
 * `hay_prohibicion_contacto_activa()` devuelve true y el encuentro se rechaza.
 * Ante la duda, se protege.
 *
 * @return array ['permitido' => bool, 'motivo' => string]
 */
function verificar_prohibicion_contacto($denuncia_id, array $participantes) {
    // Fase 5 · la verificación ya es efectiva: existe la tabla `medidas`
    if (function_exists("hay_prohibicion_contacto_activa")
        && hay_prohibicion_contacto_activa($denuncia_id)) {

        $papeles = array_column($participantes, "papel");
        $partes = array_intersect(["afectada", "denunciada"], $papeles);

        // Con prohibición activa no se habilita ningún espacio que reúna a
        // las partes, ni siquiera de forma indirecta
        if (count($partes) >= 2) {
            return [
                "permitido" => false,
                "motivo"    => "RN-05: existe una prohibición de contacto activa en este "
                             . "expediente (Ac. 002 art. 26 par. lit. h).",
            ];
        }
    }

    return ["permitido" => true, "motivo" => ""];
}

/**
 * Crear un encuentro
 *
 * @param array $datos denuncia_id, etapa, modalidad, inicia_at, termina_at,
 *                     profesional_id, organizador_upn, permitir_grabacion
 * @param array $participantes [['papel' => …, 'persona_id' => …, 'usuario_id' => …], …]
 * @return array ['exito' => bool, 'encuentro_id' => int|null, 'motivo' => string]
 */
function crear_encuentro(array $datos, array $participantes) {
    $denuncia_id = (int)($datos['denuncia_id'] ?? 0);

    if ($denuncia_id <= 0) {
        return ['exito' => false, 'encuentro_id' => null, 'motivo' => 'Expediente no válido.'];
    }

    // ── RN-04 · antes que cualquier otra cosa ────────────────────────────
    $rn04 = verificar_no_confrontacion($participantes);

    if (!$rn04['permitido']) {
        audit_log($datos['profesional_id'] ?? null, 'encuentro_rechazado_rn04', 'encuentros', [
            'etapa' => $datos['etapa'] ?? null,
        ], $denuncia_id);

        return ['exito' => false, 'encuentro_id' => null, 'motivo' => $rn04['motivo']];
    }

    // ── RN-05 · prohibición de contacto ──────────────────────────────────
    $rn05 = verificar_prohibicion_contacto($denuncia_id, $participantes);

    if (!$rn05['permitido']) {
        audit_log($datos['profesional_id'] ?? null, 'encuentro_rechazado_rn05', 'encuentros', [
            'etapa' => $datos['etapa'] ?? null,
        ], $denuncia_id);

        return ['exito' => false, 'encuentro_id' => null, 'motivo' => $rn05['motivo']];
    }

    // ── Consentimiento para grabar ───────────────────────────────────────
    $permitir_grabacion = !empty($datos['permitir_grabacion']);

    if ($permitir_grabacion && empty($datos['consentimiento_grabacion_id'])) {
        return [
            'exito' => false, 'encuentro_id' => null,
            'motivo' => 'Grabar exige un consentimiento expreso registrado.',
        ];
    }

    $denuncia = db_select_one('SELECT codigo FROM denuncias WHERE id = ? LIMIT 1', [$denuncia_id]);

    if (!$denuncia) {
        return ['exito' => false, 'encuentro_id' => null, 'motivo' => 'Expediente no encontrado.'];
    }

    $etapa = $datos['etapa'] ?? 'encuentro_inicial';
    $modalidad = $datos['modalidad'] ?? 'presencial';

    try {
        db_begin_transaction();

        $encuentro_id = db_insert_simple('encuentros', [
            'denuncia_id'    => $denuncia_id,
            'etapa'          => $etapa,
            'modalidad'      => $modalidad,
            'inicia_at'      => $datos['inicia_at'],
            'termina_at'     => $datos['termina_at'],
            'profesional_id' => (int)$datos['profesional_id'],
            'grabacion_permitida' => $permitir_grabacion ? 1 : 0,
            'consentimiento_grabacion_id' => $datos['consentimiento_grabacion_id'] ?? null,
            'estado_sync'    => $modalidad === 'virtual' ? 'pendiente' : 'sincronizado',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        if (!$encuentro_id) {
            throw new RuntimeException('No se pudo registrar el encuentro');
        }

        foreach ($participantes as $p) {
            db_insert_simple('encuentro_participantes', [
                'encuentro_id' => $encuentro_id,
                'persona_id'   => $p['persona_id'] ?? null,
                'usuario_id'   => $p['usuario_id'] ?? null,
                'papel'        => $p['papel'],
            ]);
        }

        db_commit();

    } catch (Throwable $e) {
        if (db_in_transaction()) {
            db_rollback();
        }
        log_error('Fallo al crear encuentro: ' . $e->getMessage(), 'error');
        return ['exito' => false, 'encuentro_id' => null, 'motivo' => 'No se pudo agendar el encuentro.'];
    }

    // ── Reunión de Teams, solo si la modalidad es virtual ────────────────
    if ($modalidad === 'virtual' && graph_activo()) {
        $area = AREAS_ENCUENTRO[$etapa] ?? 'Acompañamiento';

        $reunion = graph_crear_reunion(
            $datos['organizador_upn'] ?? GRAPH_REMITENTE,
            $denuncia['codigo'],
            $area,
            $datos['inicia_at'],
            $datos['termina_at'],
            $permitir_grabacion
        );

        if ($reunion !== false) {
            db_update_simple('encuentros', [
                'evento_graph_id'  => $reunion['id'],
                // El enlace de acceso es dato sensible: cifrado y nunca en logs
                'join_url_cifrado' => cifrar_contacto($reunion['join_url']),
                'estado_sync'      => 'sincronizado',
            ], ['id' => $encuentro_id]);
        } else {
            db_update_simple('encuentros', ['estado_sync' => 'error'], ['id' => $encuentro_id]);
            log_error('No se pudo crear la reunión de Teams del encuentro ' . $encuentro_id, 'warning');
        }
    }

    audit_log($datos['profesional_id'] ?? null, 'encuentro_creado', 'encuentros', [
        'etapa'     => $etapa,
        'modalidad' => $modalidad,
    ], $denuncia_id);

    // ── Notificación sin carga a quienes participan del equipo ───────────
    notificar_encuentro($encuentro_id, 'encuentro_agendado');

    return ['exito' => true, 'encuentro_id' => (int)$encuentro_id, 'motivo' => ''];
}

/**
 * Encolar la notificación de un encuentro
 *
 * Solo se notifica al personal del equipo por esta vía. A la persona afectada
 * se le informa por el canal que ella eligió, y nunca con datos del caso.
 *
 * @return int Notificaciones encoladas
 */
function notificar_encuentro($encuentro_id, $plantilla = 'encuentro_agendado') {
    $encuentro = db_select_one(
        'SELECT e.*, d.codigo FROM encuentros e
           JOIN denuncias d ON d.id = e.denuncia_id
          WHERE e.id = ? LIMIT 1',
        [(int)$encuentro_id]
    );

    if (!$encuentro) {
        return 0;
    }

    $profesional = db_select_one('SELECT email FROM usuarios WHERE id = ? LIMIT 1',
        [(int)$encuentro['profesional_id']]);

    if (!$profesional || empty($profesional['email'])) {
        return 0;
    }

    $ok = encolar_notificacion('correo', $profesional['email'], $plantilla, [
        'radicado'  => $encuentro['codigo'],
        'fecha'     => date('d/m/Y', strtotime($encuentro['inicia_at'])),
        'hora'      => date('H:i', strtotime($encuentro['inicia_at'])),
        'modalidad' => $encuentro['modalidad'],
        'enlace'    => url('?page=denuncia-ver&id=' . $encuentro['denuncia_id']),
    ], (int)$encuentro['denuncia_id']);

    return $ok ? 1 : 0;
}

/**
 * Obtener el enlace de acceso de un encuentro
 *
 * Se entrega descifrado solo a quien está autorizado sobre el expediente, y
 * la entrega queda auditada: el enlace da acceso al espacio de atención.
 *
 * @return string|null
 */
function enlace_encuentro($encuentro_id, $usuario_id, $rol) {
    $encuentro = db_select_one(
        'SELECT denuncia_id, join_url_cifrado FROM encuentros WHERE id = ? LIMIT 1',
        [(int)$encuentro_id]
    );

    if (!$encuentro || empty($encuentro['join_url_cifrado'])) {
        return null;
    }

    if (!puede_ver_expediente($usuario_id, $rol, $encuentro['denuncia_id'])) {
        return null;
    }

    audit_log($usuario_id, 'enlace_encuentro_consultado', 'encuentros', [
        'rol' => $rol,
    ], (int)$encuentro['denuncia_id']);

    return descifrar_contacto($encuentro['join_url_cifrado']);
}
