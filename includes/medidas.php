<?php
/**
 * MEDIDAS · MÁQUINA DE ESTADOS
 *
 * Fase 5 · CLAUDE.md §10.3 · ADR-03
 * Implementa RN-06 (consentimiento previo) y da soporte a RN-05.
 *
 * Los cinco pasos del art. 25 del Acuerdo 002 son, literalmente, una máquina
 * de estados:
 *
 *   solicitada ─(consentimiento)─► notificada ─► en_ejecucion ─► implementada
 *        │                                            │              │
 *        └─► rechazada                                └─► incumplida │
 *                                                                     ▼
 *                              revisión trimestral (art. 30) ──► levantada
 *
 * Toda transición queda auditada, con usuario, marca temporal y motivación
 * cuando la regla lo exige. El art. 25 lit. d) obliga a que «cada medida
 * deberá registrarse»: este archivo es esa obligación hecha código.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Transiciones admitidas
 *
 * Lo que no está aquí, no se puede hacer. Una medida no puede pasar de
 * `solicitada` a `implementada` saltándose la notificación a la unidad
 * ejecutora: ese salto es exactamente el que deja a la persona sin la medida
 * mientras el expediente dice que la tiene.
 */
const TRANSICIONES_MEDIDA = [
    'solicitada'   => ['notificada', 'rechazada'],
    'notificada'   => ['en_ejecucion', 'rechazada'],
    'en_ejecucion' => ['implementada', 'incumplida'],
    'implementada' => ['levantada', 'incumplida'],
    'incumplida'   => ['en_ejecucion', 'levantada'],
    'levantada'    => [],
    'rechazada'    => [],
];

/** Transiciones que exigen motivación escrita */
const TRANSICIONES_CON_MOTIVACION = ['levantada', 'rechazada', 'incumplida'];

// ============================================================================
// CONSENTIMIENTO · RN-06
// ============================================================================

/**
 * Registrar un consentimiento
 *
 * `alcance_texto` guarda lo que efectivamente se explicó a la persona. No es
 * una casilla marcada: es la constancia de qué se le dijo que iba a pasar.
 *
 * @return int|false
 */
function registrar_consentimiento($denuncia_id, $tipo, $alcance_texto, $otorgado,
                                  $medio, $registrado_por, $referencia_id = null,
                                  $version_texto = 'v1') {

    $alcance_texto = trim((string)$alcance_texto);

    if ($alcance_texto === '') {
        log_error('Consentimiento sin alcance explicado', 'error');
        return false;
    }

    $datos = [
        'denuncia_id'    => (int)$denuncia_id,
        'tipo'           => $tipo,
        'referencia_id'  => $referencia_id !== null ? (int)$referencia_id : null,
        'alcance_texto'  => $alcance_texto,
        'version_texto'  => $version_texto,
        'otorgado'       => $otorgado ? 1 : 0,
        'medio'          => $medio,
        'registrado_por' => (int)$registrado_por,
        'created_at'     => date('Y-m-d H:i:s'),
    ];

    // Hash de integridad: permite detectar una alteración posterior del texto
    $datos['hash_registro'] = hash('sha256', json_encode([
        $datos['denuncia_id'], $datos['tipo'], $datos['alcance_texto'],
        $datos['otorgado'], $datos['created_at'],
    ]));

    $id = db_insert_simple('consentimientos', $datos);

    if (!$id) {
        return false;
    }

    audit_log($registrado_por, 'consentimiento_registrado', 'consentimientos', [
        'tipo'     => $tipo,
        'otorgado' => $otorgado ? 1 : 0,
        'medio'    => $medio,
    ], (int)$denuncia_id);

    return (int)$id;
}

/**
 * Revocar un consentimiento
 *
 * Una persona puede aceptar una medida hoy y retirarla en tres semanas. El
 * sistema debe permitirlo sin fricción: el consentimiento no es un contrato.
 *
 * @return bool
 */
function revocar_consentimiento($consentimiento_id, $motivo, $usuario_id) {
    $consentimiento = db_select_one('SELECT denuncia_id FROM consentimientos WHERE id = ? LIMIT 1',
        [(int)$consentimiento_id]);

    if (!$consentimiento) {
        return false;
    }

    $resultado = db_update_simple('consentimientos', [
        'revocado_at'       => date('Y-m-d H:i:s'),
        'motivo_revocacion' => $motivo,
    ], ['id' => (int)$consentimiento_id]);

    if ($resultado === false) {
        return false;
    }

    audit_log($usuario_id, 'consentimiento_revocado', 'consentimientos', null,
        (int)$consentimiento['denuncia_id']);

    return true;
}

/**
 * ¿Hay un consentimiento válido y no revocado?
 *
 * @return bool
 */
function consentimiento_vigente($consentimiento_id) {
    if (empty($consentimiento_id)) {
        return false;
    }

    $fila = db_select_one(
        'SELECT COUNT(*) AS total FROM consentimientos
          WHERE id = ? AND otorgado = 1 AND revocado_at IS NULL',
        [(int)$consentimiento_id]
    );

    if ($fila === false) {
        return false; // falla cerrada
    }

    return (int)$fila['total'] > 0;
}

// ============================================================================
// CICLO DE VIDA DE LA MEDIDA
// ============================================================================

/**
 * Solicitar una medida (paso 1 del art. 25)
 *
 * @param array $datos denuncia_id, catalogo_id, solicitada_por,
 *                     motivacion_oficio, unidad_ejecutora_id, responsable_id
 * @param int|null $usuario_id
 * @return array ['exito' => bool, 'medida_id' => int|null, 'motivo' => string]
 */
function solicitar_medida(array $datos, $usuario_id = null) {
    $denuncia_id = (int)($datos['denuncia_id'] ?? 0);
    $catalogo_id = (int)($datos['catalogo_id'] ?? 0);

    $catalogo = db_select_one('SELECT * FROM catalogo_medidas WHERE id = ? AND activo = 1 LIMIT 1',
        [$catalogo_id]);

    if (!$catalogo) {
        return ['exito' => false, 'medida_id' => null, 'motivo' => 'La medida no existe en el catálogo.'];
    }

    $solicitada_por = $datos['solicitada_por'] ?? 'equipo';

    // Ac. 002 art. 22: las medidas se adoptan según la voluntad de la víctima.
    // Cuando se adoptan de oficio, la motivación es obligatoria.
    if ($solicitada_por === 'oficio' && trim((string)($datos['motivacion_oficio'] ?? '')) === '') {
        return [
            'exito' => false, 'medida_id' => null,
            'motivo' => 'Una medida de oficio exige motivación escrita (Ac. 002 art. 22).',
        ];
    }

    $fecha_limite = sumar_dias_habiles(date('Y-m-d'), (int)$catalogo['dias_habiles_ejecucion']);

    $medida_id = db_insert_simple('medidas', [
        'denuncia_id'         => $denuncia_id,
        'catalogo_id'         => $catalogo_id,
        'solicitada_por'      => $solicitada_por,
        'motivacion_oficio'   => $datos['motivacion_oficio'] ?? null,
        'consentimiento_id'   => $datos['consentimiento_id'] ?? null,
        'unidad_ejecutora_id' => $datos['unidad_ejecutora_id'] ?? null,
        'responsable_id'      => $datos['responsable_id'] ?? null,
        // 0029 · datos operativos que el oficio necesita (asignatura, grupo,
        // horario, señas del espacio). Van en POST, nunca en la URL (RN-09).
        'detalle_ejecucion'   => trim((string)($datos['detalle_ejecucion'] ?? '')) ?: null,
        'estado'              => 'solicitada',
        'fecha_solicitud'     => date('Y-m-d H:i:s'),
        'fecha_limite'        => $fecha_limite,
        'created_at'          => date('Y-m-d H:i:s'),
    ]);

    if (!$medida_id) {
        return ['exito' => false, 'medida_id' => null, 'motivo' => 'No se pudo registrar la medida.'];
    }

    registrar_transicion($medida_id, null, 'solicitada', $usuario_id, null);

    audit_log($usuario_id, 'medida_solicitada', 'medidas', [
        'catalogo'       => $catalogo['codigo'],
        'solicitada_por' => $solicitada_por,
        'plazo'          => $fecha_limite,
    ], $denuncia_id);

    // v3.1 §7.1 · toda medida crea su compromiso para la unidad ejecutora.
    // function_exists: la Fase A introduce compromisos.php; una instalación a
    // medio migrar no debe romper la solicitud de la medida.
    if (function_exists('crear_compromiso') && !empty($datos['unidad_ejecutora_id'])) {
        crear_compromiso([
            'denuncia_id'           => $denuncia_id,
            'origen'                => 'medida',
            'medida_id'             => (int)$medida_id,
            'descripcion'           => 'Ejecutar la medida «' . $catalogo['nombre']
                                     . '» y registrar la constancia de cumplimiento.',
            'responsable_tipo'      => 'unidad',
            'responsable_unidad_id' => (int)$datos['unidad_ejecutora_id'],
            'fecha_limite'          => $fecha_limite,
        ], $usuario_id);
    }

    return ['exito' => true, 'medida_id' => (int)$medida_id, 'motivo' => ''];
}

/**
 * Cambiar el estado de una medida
 *
 * ⚠ RN-06 · Ac. 002 arts. 22, 25 lit. a y 26
 *   Ninguna medida que afecte a la persona afectada pasa a `en_ejecucion` sin
 *   un consentimiento válido y no revocado. La única excepción son las medidas
 *   de oficio, que exigen motivación.
 *
 * @return array ['exito' => bool, 'motivo' => string]
 */
function cambiar_estado_medida($medida_id, $nuevo_estado, $usuario_id, $motivacion = null) {
    $medida = db_select_one(
        'SELECT m.*, c.codigo AS catalogo_codigo, c.requiere_consentimiento, c.dirigida_a
           FROM medidas m JOIN catalogo_medidas c ON c.id = m.catalogo_id
          WHERE m.id = ? LIMIT 1',
        [(int)$medida_id]
    );

    if (!$medida) {
        return ['exito' => false, 'motivo' => 'La medida no existe.'];
    }

    $actual = $medida['estado'];

    // ── Transición admitida ──────────────────────────────────────────────
    if (!in_array($nuevo_estado, TRANSICIONES_MEDIDA[$actual] ?? [], true)) {
        return [
            'exito' => false,
            'motivo' => "No se puede pasar de «{$actual}» a «{$nuevo_estado}».",
        ];
    }

    // ── Motivación obligatoria ───────────────────────────────────────────
    if (in_array($nuevo_estado, TRANSICIONES_CON_MOTIVACION, true)
        && trim((string)$motivacion) === '') {
        return [
            'exito' => false,
            'motivo' => "Pasar a «{$nuevo_estado}» exige motivación escrita "
                      . '(Ac. 002 art. 30: el levantamiento debe ser motivado).',
        ];
    }

    // ── RN-06 · consentimiento previo ────────────────────────────────────
    if ($nuevo_estado === 'en_ejecucion'
        && (int)$medida['requiere_consentimiento'] === 1
        && $medida['solicitada_por'] !== 'oficio') {

        if (!consentimiento_vigente($medida['consentimiento_id'])) {
            audit_log($usuario_id, 'medida_bloqueada_rn06', 'medidas', [
                'catalogo' => $medida['catalogo_codigo'],
            ], (int)$medida['denuncia_id']);

            return [
                'exito' => false,
                'motivo' => 'RN-06: esta medida no puede ejecutarse sin un consentimiento '
                          . 'vigente de la persona afectada (Ac. 002 arts. 22 y 25 lit. a).',
            ];
        }
    }

    // ── Aplicar ──────────────────────────────────────────────────────────
    $cambios = ['estado' => $nuevo_estado, 'updated_at' => date('Y-m-d H:i:s')];

    if ($nuevo_estado === 'implementada') {
        $cambios['fecha_implementacion'] = date('Y-m-d H:i:s');
        $cambios['constancia_texto'] = $motivacion;
        // Ac. 002 art. 30: revisión trimestral desde la implementación
        $cambios['proxima_revision'] = date('Y-m-d', strtotime('+3 months'));
    }

    if ($nuevo_estado === 'levantada') {
        $cambios['motivo_levantamiento'] = $motivacion;
        $cambios['proxima_revision'] = null;
    }

    if ($nuevo_estado === 'rechazada') {
        $cambios['motivo_rechazo'] = $motivacion;
    }

    if (db_update_simple('medidas', $cambios, ['id' => (int)$medida_id]) === false) {
        return ['exito' => false, 'motivo' => 'No se pudo actualizar la medida.'];
    }

    registrar_transicion($medida_id, $actual, $nuevo_estado, $usuario_id, $motivacion);

    audit_log($usuario_id, 'medida_' . $nuevo_estado, 'medidas', [
        'catalogo' => $medida['catalogo_codigo'],
        'desde'    => $actual,
    ], (int)$medida['denuncia_id']);

    // Notificación sin carga a la unidad ejecutora (RN-09)
    if ($nuevo_estado === 'notificada') {
        notificar_medida_a_unidad($medida_id);
    }

    return ['exito' => true, 'motivo' => ''];
}

/**
 * Registrar una transición en el historial
 */
function registrar_transicion($medida_id, $desde, $hasta, $usuario_id, $motivacion) {
    return db_insert_simple('medida_transiciones', [
        'medida_id'    => (int)$medida_id,
        'estado_desde' => $desde,
        'estado_hasta' => $hasta,
        'usuario_id'   => $usuario_id !== null ? (int)$usuario_id : null,
        'motivacion'   => $motivacion,
        'created_at'   => date('Y-m-d H:i:s'),
    ]) !== false;
}

/**
 * Notificar a la unidad ejecutora
 *
 * ADR-05: la unidad recibe una ORDEN DE TRABAJO, no el expediente. El correo
 * solo lleva radicado y plazo (RN-09).
 *
 * @return bool
 */
function notificar_medida_a_unidad($medida_id) {
    $medida = db_select_one(
        'SELECT m.*, d.codigo FROM medidas m JOIN denuncias d ON d.id = m.denuncia_id
          WHERE m.id = ? LIMIT 1',
        [(int)$medida_id]
    );

    if (!$medida) {
        return false;
    }

    $destinatarios = destinatarios_de_caso((int)$medida['denuncia_id']);

    foreach ($destinatarios as $correo) {
        encolar_notificacion('correo', $correo, 'medida_por_ejecutar', [
            'radicado' => $medida['codigo'],
            'plazo'    => date('d/m/Y', strtotime($medida['fecha_limite'])),
            'enlace'   => url('?page=denuncias'),
        ], (int)$medida['denuncia_id']);
    }

    return true;
}

// ============================================================================
// RN-05 · PROHIBICIÓN DE CONTACTO
// ============================================================================

/**
 * ¿Hay una prohibición de contacto activa en este expediente?
 *
 * Completa el punto de verificación que la Fase 4 dejó preparado en
 * `verificar_prohibicion_contacto()`.
 *
 * @return bool
 */
function hay_prohibicion_contacto_activa($denuncia_id) {
    $fila = db_select_one(
        "SELECT COUNT(*) AS total FROM medidas m
           JOIN catalogo_medidas c ON c.id = m.catalogo_id
          WHERE m.denuncia_id = ?
            AND c.codigo = 'A26P_PROHIBICION_CONTACTO'
            AND m.estado IN ('notificada','en_ejecucion','implementada')",
        [(int)$denuncia_id]
    );

    if ($fila === false) {
        return true; // falla cerrada: ante la duda, se protege
    }

    return (int)$fila['total'] > 0;
}

// ============================================================================
// CONSULTA
// ============================================================================

/**
 * Medidas de un expediente
 *
 * @return array
 */
function medidas_del_caso($denuncia_id, $estado = null) {
    $sql = 'SELECT m.*, c.codigo AS catalogo_codigo, c.nombre, c.categoria, c.fundamento
              FROM medidas m JOIN catalogo_medidas c ON c.id = m.catalogo_id
             WHERE m.denuncia_id = ?';
    $params = [(int)$denuncia_id];

    if ($estado !== null) {
        $sql .= ' AND m.estado = ?';
        $params[] = $estado;
    }

    return db_select($sql . ' ORDER BY m.created_at DESC', $params) ?: [];
}

/**
 * Medidas del catálogo aplicables a un tipo de vinculación
 *
 * @return array
 */
function catalogo_aplicable($vinculacion) {
    $mapa = [
        'estudiante_pregrado' => 'estudiante', 'estudiante_posgrado' => 'estudiante',
        'docente_carrera' => 'docente', 'docente_vinculacion_especial' => 'docente',
        'administrativo' => 'administrativo', 'trabajador_oficial' => 'administrativo',
        'contratista' => 'contratista', 'tercerizado' => 'contratista',
        'externo' => 'externo', 'desconocido' => 'externo',
    ];

    $grupo = $mapa[$vinculacion] ?? 'externo';

    return db_select(
        "SELECT * FROM catalogo_medidas
          WHERE activo = 1 AND FIND_IN_SET(?, aplica_a) > 0
          ORDER BY categoria, nombre",
        [$grupo]
    ) ?: [];
}

/**
 * Medidas que requieren revisión trimestral vencida (art. 30)
 *
 * @return array
 */
function medidas_por_revisar() {
    return db_select(
        "SELECT m.*, d.codigo, c.nombre
           FROM medidas m
           JOIN denuncias d ON d.id = m.denuncia_id
           JOIN catalogo_medidas c ON c.id = m.catalogo_id
          WHERE m.proxima_revision IS NOT NULL
            AND m.proxima_revision <= CURDATE()
            AND m.estado IN ('implementada','en_ejecucion')
          ORDER BY m.proxima_revision"
    ) ?: [];
}

/**
 * Medidas con plazo vencido y sin implementar
 *
 * @return array
 */
function medidas_vencidas() {
    return db_select(
        "SELECT m.*, d.codigo, c.nombre
           FROM medidas m
           JOIN denuncias d ON d.id = m.denuncia_id
           JOIN catalogo_medidas c ON c.id = m.catalogo_id
          WHERE m.fecha_limite < CURDATE()
            AND m.estado IN ('solicitada','notificada','en_ejecucion')
          ORDER BY m.fecha_limite"
    ) ?: [];
}
