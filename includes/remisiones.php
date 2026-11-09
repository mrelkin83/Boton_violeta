<?php
/**
 * REMISIONES EXTERNAS Y DECISIÓN SOBRE LA PROCURADURÍA
 *
 * v3.1 · Fase B · Ac. 003 arts. 3 n.13, 4, 10, 79 · RN-14 · §7.2
 *
 * Dos regímenes (§7.2):
 *   · Remisión interna a la OCID —antes OAAD— (Protocolo E4): SÍ lleva acta y evidencia
 *     elegida, cada anexo sellado con hash y auditado.
 *   · Remisión externa: por defecto SIN relato (RN-03). El relato viaja solo
 *     por decisión motivada y auditada (`relato_incluido = 1`).
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Registrar la decisión RN-14 sobre informar a la Procuraduría
 *
 * Obligatoria (sí o no, siempre con motivación) en vías disciplinarias de
 * servidores públicos. Si es «sí», crea la remisión externa en el acto.
 *
 * @return array ['exito' => bool, 'remision_id' => int|null, 'error' => string|null]
 */
function decidir_procuraduria($denuncia_id, $informar, $motivacion, $usuario_id) {
    $denuncia_id = (int)$denuncia_id;
    $motivacion = trim((string)$motivacion);

    if ($motivacion === '') {
        return ['exito' => false, 'remision_id' => null,
                'error' => 'RN-14: la motivación es obligatoria tanto para «sí» como para «no».'];
    }

    $competencia = obtener_competencia($denuncia_id);

    if (!$competencia) {
        return ['exito' => false, 'remision_id' => null,
                'error' => 'Primero debe fijarse la competencia del expediente.'];
    }

    $vias_servidores = ['disciplinaria_docente', 'disciplinaria_administrativa'];

    if (!in_array($competencia['via'], $vias_servidores, true)) {
        return ['exito' => false, 'remision_id' => null,
                'error' => 'RN-14 aplica solo a vías disciplinarias de servidores públicos. '
                         . 'Para otras vías use «Añadir remisión».'];
    }

    $ok = db_execute(
        'UPDATE competencias
            SET informar_procuraduria = ?, motivacion_procuraduria = ?,
                decidida_por = ?, decidida_at = NOW()
          WHERE denuncia_id = ?',
        [$informar ? 1 : 0, $motivacion, (int)$usuario_id, $denuncia_id]
    );

    if (!$ok) {
        return ['exito' => false, 'remision_id' => null, 'error' => 'No se pudo registrar la decisión.'];
    }

    audit_log($usuario_id, 'decision_procuraduria_registrada', 'competencias', [
        'informar' => $informar ? 1 : 0,
    ], $denuncia_id);

    $remision_id = null;

    if ($informar) {
        $destino = db_select_one("SELECT id FROM destinos_remision WHERE codigo = 'procuraduria' LIMIT 1");

        if ($destino) {
            $resultado = crear_remision([
                'denuncia_id' => $denuncia_id,
                'destino_id'  => (int)$destino['id'],
                'tipo'        => 'informacion',
                'motivacion'  => $motivacion,
            ], $usuario_id);

            $remision_id = $resultado['remision_id'];
        }
    }

    return ['exito' => true, 'remision_id' => $remision_id, 'error' => null];
}

/**
 * Plazo del catálogo normativo, con valor de respaldo
 *
 * @return int Cantidad (en la unidad del catálogo; aquí se usan días hábiles)
 */
function plazo_catalogo($codigo, $defecto) {
    $fila = db_select_one(
        'SELECT cantidad FROM catalogo_plazos WHERE codigo = ? AND activo = 1 LIMIT 1',
        [$codigo]
    );

    return $fila ? (int)$fila['cantidad'] : (int)$defecto;
}

/**
 * Crear una remisión (externa o interna)
 *
 * Genera automáticamente el compromiso de envío (origen `remision`) con el
 * plazo DIAS_ENVIO_REMISION para quien decide (§7.2).
 *
 * Régimen INTERNO (Protocolo E4 · destinos con `interno = 1`): además del
 * compromiso de envío, se crea el compromiso del RECEPTOR en la bandeja de
 * su dependencia (§16 E5), sin darle acceso al expediente (RN-08):
 *   · OCID (la antigua OAAD, renombrada por el manual de funciones posterior
 *     al Protocolo) → «direccionar el caso» en el plazo del catálogo
 *     DIRECCIONAMIENTO_OAAD (5 días hábiles, Protocolo E4).
 *   · Decanatura → exige señalar la facultad (`unidad_interna_id`); acusar
 *     recibo y tramitar según su competencia (Protocolo E4 paso 4).
 *   · Comité de Convivencia → acusar recibo e informar el trámite.
 *
 * @param array $datos denuncia_id, destino_id, tipo, motivacion,
 *                     relato_incluido, motivacion_relato, anexos (array),
 *                     informado_facultad_fiscalia, unidad_interna_id
 * @return array ['exito' => bool, 'remision_id' => int|null, 'error' => string|null]
 */
function crear_remision(array $datos, $usuario_id) {
    $denuncia_id = (int)($datos['denuncia_id'] ?? 0);
    $destino_id  = (int)($datos['destino_id'] ?? 0);
    $tipo        = $datos['tipo'] ?? '';
    $motivacion  = trim((string)($datos['motivacion'] ?? ''));

    if ($denuncia_id <= 0 || $destino_id <= 0
        || !in_array($tipo, ['traslado', 'informacion', 'coordinacion'], true)
        || $motivacion === '') {
        return ['exito' => false, 'remision_id' => null, 'error' => 'Datos de la remisión incompletos.'];
    }

    $destino = db_select_one('SELECT * FROM destinos_remision WHERE id = ? LIMIT 1', [$destino_id]);

    if (!$destino) {
        return ['exito' => false, 'remision_id' => null, 'error' => 'Destino de remisión no encontrado.'];
    }

    $es_interno = (int)($destino['interno'] ?? 0) === 1;

    // ── Régimen interno: unidad receptora ────────────────────────────────
    $unidad_interna_id = null;

    if ($es_interno) {
        if ($destino['codigo'] === 'decanatura') {
            // Hay una decanatura por facultad: la remisión exige señalarla
            $unidad_interna_id = (int)($datos['unidad_interna_id'] ?? 0);

            $facultad = $unidad_interna_id > 0
                ? db_select_one(
                    "SELECT id FROM unidades WHERE id = ? AND tipo = 'facultad' AND activa = 1 LIMIT 1",
                    [$unidad_interna_id])
                : null;

            if (!$facultad) {
                return ['exito' => false, 'remision_id' => null,
                        'error' => 'Una remisión a decanatura exige señalar la facultad (activa) que la recibe.'];
            }
        } else {
            // OAAD / OCID: dependencia del catálogo institucional (0026)
            $dependencia = db_select_one(
                'SELECT id FROM unidades WHERE codigo = ? AND activa = 1 LIMIT 1',
                [strtoupper($destino['codigo'])]
            );

            if (!$dependencia) {
                return ['exito' => false, 'remision_id' => null,
                        'error' => 'La dependencia ' . strtoupper($destino['codigo'])
                                 . ' no está en el catálogo de unidades (migración 0026).'];
            }
            $unidad_interna_id = (int)$dependencia['id'];
        }
    }

    // Destino inactivo: solo `icbf` se habilita caso a caso si hay una persona
    // afectada menor de edad (RN-20). Los demás exigen aprobación de Jurídica.
    if ((int)$destino['activo'] !== 1) {
        $habilitado = false;

        if ($destino['codigo'] === 'icbf') {
            $menor = db_select_one(
                "SELECT id FROM personas_caso
                  WHERE denuncia_id = ? AND papel = 'afectada' AND es_menor_de_edad = 1 LIMIT 1",
                [$denuncia_id]
            );
            $habilitado = (bool)$menor;
        }

        if (!$habilitado) {
            return ['exito' => false, 'remision_id' => null,
                    'error' => 'El destino «' . $destino['nombre'] . '» está inactivo: '
                             . 'carece de fundamento normativo aprobado (v3.1 §12.4).'];
        }
    }

    // RN-03 · el relato viaja solo con decisión motivada
    $relato_incluido = !empty($datos['relato_incluido']) ? 1 : 0;
    $motivacion_relato = trim((string)($datos['motivacion_relato'] ?? ''));

    if ($relato_incluido === 1 && $motivacion_relato === '') {
        return ['exito' => false, 'remision_id' => null,
                'error' => 'Incluir el relato en una remisión exige motivación expresa (RN-03).'];
    }

    // Anexos elegidos explícitamente, cada uno con su hash (§7.2)
    $anexos_json = null;
    if (!empty($datos['anexos']) && is_array($datos['anexos'])) {
        $anexos = [];
        foreach ($datos['anexos'] as $anexo) {
            $anexos[] = [
                'archivo_id' => (int)($anexo['archivo_id'] ?? 0),
                'hash'       => $anexo['hash'] ?? null,
                'descripcion'=> mb_substr((string)($anexo['descripcion'] ?? ''), 0, 200),
            ];
        }
        $anexos_json = json_encode($anexos, JSON_UNESCAPED_UNICODE);
    }

    try {
        db_begin_transaction();

        $remision_id = db_insert_simple('remisiones_externas', [
            'denuncia_id'       => $denuncia_id,
            'destino_id'        => $destino_id,
            'unidad_interna_id' => $unidad_interna_id,
            'tipo'              => $tipo,
            'motivacion'        => $motivacion,
            'decidida_por'      => (int)$usuario_id,
            'fecha_decision'    => date('Y-m-d H:i:s'),
            'estado'            => 'decidida',
            'relato_incluido'   => $relato_incluido,
            'motivacion_relato' => $relato_incluido ? $motivacion_relato : null,
            'anexos_json'       => $anexos_json,
            'informado_facultad_fiscalia' => $destino['codigo'] === 'fiscalia'
                                           ? (!empty($datos['informado_facultad_fiscalia']) ? 1 : 0)
                                           : null,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        if (!$remision_id) {
            throw new RuntimeException('No se pudo crear la remisión');
        }

        db_commit();
    } catch (Exception $e) {
        db_rollback();
        log_error('Error al crear remisión: ' . $e->getMessage(), 'error');
        return ['exito' => false, 'remision_id' => null, 'error' => 'No se pudo crear la remisión.'];
    }

    audit_log($usuario_id, 'remision_creada', 'remisiones_externas', [
        'destino'         => $destino['codigo'],
        'tipo'            => $tipo,
        'relato_incluido' => $relato_incluido,
    ], (int)$remision_id);

    // Compromiso de envío para quien decide (§7.2)
    $plazo = (int)config_valor('DIAS_ENVIO_REMISION', 5);

    crear_compromiso([
        'denuncia_id'            => $denuncia_id,
        'origen'                 => 'remision',
        'remision_id'            => (int)$remision_id,
        'descripcion'            => 'Enviar la remisión a ' . $destino['nombre']
                                  . ' y registrar radicado de destino y acuse.',
        'responsable_tipo'       => 'usuario',
        'responsable_usuario_id' => (int)$usuario_id,
        'fecha_limite'           => sumar_dias_habiles(date('Y-m-d'), $plazo),
    ], $usuario_id);

    // ── Régimen interno: compromiso del RECEPTOR en su bandeja (§16 E5) ──
    if ($es_interno && $unidad_interna_id) {
        switch ($destino['codigo']) {
            case 'ocid':
                // La OCID es la antigua OAAD (renombrada por el manual de
                // funciones, posterior al Protocolo): asume el
                // direccionamiento de la etapa E4 en 5 días hábiles.
                $descripcion_receptor = 'Direccionar el caso remitido por Bienestar '
                                      . '(Protocolo VBG etapa E4).';
                $plazo_receptor = plazo_catalogo('DIRECCIONAMIENTO_OAAD', 5);
                break;
            case 'comite_convivencia':
                $descripcion_receptor = 'Acusar recibo de la remisión e informar el trámite '
                                      . 'adelantado (casos administrativos y contratistas).';
                $plazo_receptor = (int)config_valor('DIAS_RESPUESTA_SOLICITUD_ESTADO', 10);
                break;
            default: // decanatura
                $descripcion_receptor = 'Acusar recibo de la comunicación remitida por Bienestar '
                                      . 'y tramitar según su competencia (Protocolo E4).';
                $plazo_receptor = (int)config_valor('DIAS_RESPUESTA_SOLICITUD_ESTADO', 10);
        }

        crear_compromiso([
            'denuncia_id'           => $denuncia_id,
            'origen'                => 'remision',
            'remision_id'           => (int)$remision_id,
            'descripcion'           => $descripcion_receptor,
            'responsable_tipo'      => 'unidad',
            'responsable_unidad_id' => $unidad_interna_id,
            'fecha_limite'          => sumar_dias_habiles(date('Y-m-d'), $plazo_receptor),
        ], $usuario_id);
    }

    return ['exito' => true, 'remision_id' => (int)$remision_id, 'error' => null];
}

/** Transiciones permitidas del estado de una remisión */
const TRANSICIONES_REMISION = [
    'decidida'            => ['enviada', 'cerrada'],
    'enviada'             => ['acusada', 'devuelta', 'asumida_por_destino', 'cerrada'],
    'acusada'             => ['asumida_por_destino', 'devuelta', 'cerrada'],
    'asumida_por_destino' => ['cerrada'],
    'devuelta'            => ['enviada', 'cerrada'],
    'cerrada'             => [],
];

/**
 * Cambiar el estado de una remisión
 *
 * Al registrar el envío con radicado de destino, el compromiso de envío
 * asociado pasa a cumplido (§7.2).
 *
 * @param array $extra medio_envio, radicado_destino, acuse_ruta, acuse_hash, observaciones
 * @return array ['exito' => bool, 'error' => string|null]
 */
function cambiar_estado_remision($remision_id, $nuevo_estado, $usuario_id, array $extra = []) {
    $remision_id = (int)$remision_id;

    $remision = db_select_one('SELECT * FROM remisiones_externas WHERE id = ? LIMIT 1', [$remision_id]);

    if (!$remision) {
        return ['exito' => false, 'error' => 'Remisión no encontrada.'];
    }

    $permitidas = TRANSICIONES_REMISION[$remision['estado']] ?? [];

    if (!in_array($nuevo_estado, $permitidas, true)) {
        return ['exito' => false,
                'error' => 'Transición no permitida: ' . $remision['estado'] . ' → ' . $nuevo_estado . '.'];
    }

    $cambios = ['estado' => $nuevo_estado];

    if ($nuevo_estado === 'enviada') {
        if (empty($extra['medio_envio'])) {
            return ['exito' => false, 'error' => 'Registrar el envío exige indicar el medio.'];
        }
        $cambios['fecha_envio'] = date('Y-m-d H:i:s');
        $cambios['medio_envio'] = $extra['medio_envio'];
    }

    foreach (['radicado_destino', 'acuse_ruta', 'acuse_hash', 'observaciones'] as $campo) {
        if (!empty($extra[$campo])) {
            $cambios[$campo] = $extra[$campo];
        }
    }

    if (db_update_simple('remisiones_externas', $cambios, ['id' => $remision_id]) === false) {
        return ['exito' => false, 'error' => 'No se pudo actualizar la remisión.'];
    }

    audit_log($usuario_id, 'remision_transicion', 'remisiones_externas', [
        'desde' => $remision['estado'],
        'hasta' => $nuevo_estado,
    ], $remision_id);

    // Con radicado de destino registrado, el compromiso de ENVÍO se cumple.
    // El del receptor (régimen interno) sigue su propio curso en su bandeja.
    if (!empty($extra['radicado_destino'])) {
        $compromiso = db_select_one(
            "SELECT id, estado FROM compromisos
              WHERE remision_id = ? AND origen = 'remision'
                AND responsable_usuario_id IS NOT NULL
                AND estado IN ('pendiente','en_curso','prorrogado','incumplido') LIMIT 1",
            [$remision_id]
        );

        if ($compromiso) {
            cambiar_estado_compromiso((int)$compromiso['id'], 'cumplido', $usuario_id, [
                'constancia_texto' => 'Remisión enviada. Radicado de destino: '
                                    . mb_substr($extra['radicado_destino'], 0, 120),
            ]);
        }
    }

    return ['exito' => true, 'error' => null];
}

/**
 * Remisiones de un caso
 *
 * @return array
 */
function remisiones_del_caso($denuncia_id) {
    return db_select(
        'SELECT r.*, dr.nombre AS destino_nombre, dr.codigo AS destino_codigo,
                dr.ambito, dr.interno, u.nombre AS unidad_interna_nombre
           FROM remisiones_externas r
           JOIN destinos_remision dr ON dr.id = r.destino_id
           LEFT JOIN unidades u ON u.id = r.unidad_interna_id
          WHERE r.denuncia_id = ?
          ORDER BY r.created_at DESC',
        [(int)$denuncia_id]
    ) ?: [];
}

/**
 * Catálogo de destinos (activos primero)
 *
 * @return array
 */
function destinos_remision_disponibles() {
    return db_select('SELECT * FROM destinos_remision ORDER BY activo DESC, nombre') ?: [];
}
