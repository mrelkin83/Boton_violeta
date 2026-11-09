<?php
/**
 * EXPEDIENTE · ETAPA PROCESAL Y ETAPA DE RUTA
 *
 * v3.1 · Fase A (H-23) y §16 (RN-19)
 *
 * Dos ejes distintos que antes no se hablaban:
 *   · `etapa_procesal` — la lleva la autoridad disciplinaria (Ac. 003).
 *     `estado` deja de editarse a mano: se DERIVA de la etapa (H-23).
 *   · `etapa_ruta` — la lleva Bienestar (Res. 027/2023 art. 9 · Res. 142/2026).
 *     Cada avance exige la evidencia de la etapa (RN-19).
 *
 * RN-14: en vías disciplinarias de servidores públicos, la transición a
 * `investigacion` se bloquea mientras no exista la decisión registrada sobre
 * la Procuraduría.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/** Orden procesal permitido (ENUM real de la migración 0011) */
const ETAPAS_PROCESALES = ['recepcion', 'indagacion_previa', 'investigacion',
                           'cargos_formulados', 'juzgamiento', 'archivo_definitivo', 'cerrada'];

/** Orden de la ruta de atención (Res. 027/2023 art. 9 · migración 0024) */
const ETAPAS_RUTA = ['conocimiento', 'encuentro_inicial', 'atencion_integral',
                     'investigacion', 'seguimiento'];

/**
 * Derivar `estado` desde `etapa_procesal` (H-23)
 *
 * Adaptada al ENUM real de la migración 0011, como la propia v3.1 §6 ordena
 * («adaptar la tabla al ENUM, no el ENUM a la tabla»):
 *   cargos_formulados → en_proceso · cerrada → resuelta ·
 *   archivo_definitivo → archivada.
 *
 * @param string $etapa_procesal
 * @return string nueva|en_proceso|resuelta|archivada
 */
function estado_desde_etapa($etapa_procesal) {
    switch ($etapa_procesal) {
        case 'recepcion':
            return 'nueva';
        case 'indagacion_previa':
        case 'investigacion':
        case 'cargos_formulados':
        case 'juzgamiento':
            return 'en_proceso';
        case 'cerrada':
            return 'resuelta';
        case 'archivo_definitivo':
            return 'archivada';
        default:
            // Falla conservadora: un valor desconocido no archiva ni resuelve
            return 'en_proceso';
    }
}

/**
 * ¿Está pendiente la decisión RN-14 sobre la Procuraduría?
 *
 * Solo aplica a vías disciplinarias de servidores públicos. La estudiantil
 * queda fuera (los estudiantes no son sujetos disciplinables por la
 * Procuraduría).
 *
 * @return bool
 */
function rn14_decision_pendiente($denuncia_id) {
    $competencia = obtener_competencia($denuncia_id);

    if (!$competencia) {
        // Sin competencia fijada, la decisión no es exigible todavía
        return false;
    }

    $vias_servidores = ['disciplinaria_docente', 'disciplinaria_administrativa'];

    if (!in_array($competencia['via'] ?? '', $vias_servidores, true)) {
        return false;
    }

    return !isset($competencia['informar_procuraduria'])
        || $competencia['informar_procuraduria'] === null;
}

/**
 * Cambiar la etapa procesal de un expediente
 *
 * Punto único de mutación (H-23): deriva `estado`, aplica el bloqueo RN-14,
 * registra el seguimiento y audita. El bloqueo RN-14 puede rebajarse a
 * sugerencia con RN14_BLOQUEA_ETAPA = 0 (decisión §12.2, auditada, G-15).
 *
 * @return array ['exito' => bool, 'error' => string|null]
 */
function cambiar_etapa_procesal($denuncia_id, $nueva_etapa, $usuario_id, $observacion = null) {
    $denuncia_id = (int)$denuncia_id;

    if (!in_array($nueva_etapa, ETAPAS_PROCESALES, true)) {
        return ['exito' => false, 'error' => 'Etapa procesal no válida.'];
    }

    $denuncia = db_select_one('SELECT etapa_procesal, estado FROM denuncias WHERE id = ? LIMIT 1', [$denuncia_id]);

    if (!$denuncia) {
        return ['exito' => false, 'error' => 'Expediente no encontrado.'];
    }

    if ($denuncia['etapa_procesal'] === $nueva_etapa) {
        return ['exito' => false, 'error' => 'El expediente ya está en esa etapa.'];
    }

    // RN-14 · sin decisión sobre la Procuraduría no se abre investigación
    if ($nueva_etapa === 'investigacion'
        && config_valor('RN14_BLOQUEA_ETAPA', true)
        && rn14_decision_pendiente($denuncia_id)) {
        audit_log($usuario_id, 'etapa_bloqueada_rn14', 'denuncias', [
            'etapa_solicitada' => $nueva_etapa,
        ], $denuncia_id);
        return ['exito' => false,
                'error' => 'RN-14: antes de abrir la investigación debe registrarse la decisión '
                         . 'sobre informar a la Procuraduría (pestaña Competencia).'];
    }

    $nuevo_estado = estado_desde_etapa($nueva_etapa);

    try {
        db_begin_transaction();

        $ok = db_update_simple('denuncias', [
            'etapa_procesal' => $nueva_etapa,
            'estado'         => $nuevo_estado,
            'updated_at'     => date('Y-m-d H:i:s'),
        ], ['id' => $denuncia_id]);

        if ($ok === false) {
            throw new RuntimeException('No se pudo actualizar la etapa');
        }

        // H-04: la observación del cambio queda en el historial de seguimiento
        db_insert_simple('seguimientos', [
            'denuncia_id' => $denuncia_id,
            'usuario_id'  => $usuario_id,
            'tipo'        => 'cambio_estado',
            'contenido'   => 'Etapa procesal: ' . $denuncia['etapa_procesal'] . ' → ' . $nueva_etapa
                           . ($observacion !== null && trim($observacion) !== '' ? '. ' . trim($observacion) : ''),
            'privado'     => 1,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        db_commit();
    } catch (Exception $e) {
        db_rollback();
        log_error('Error al cambiar etapa procesal: ' . $e->getMessage(), 'error');
        return ['exito' => false, 'error' => 'No se pudo cambiar la etapa. Intente de nuevo.'];
    }

    audit_log($usuario_id, 'etapa_procesal_cambiada', 'denuncias', [
        'desde'           => $denuncia['etapa_procesal'],
        'hasta'           => $nueva_etapa,
        'estado_derivado' => $nuevo_estado,
    ], $denuncia_id);

    return ['exito' => true, 'error' => null];
}

/**
 * Evidencia exigida para avanzar en la ruta de atención (RN-19)
 *
 * @return array ['cumple' => bool, 'falta' => string]
 */
function evidencia_etapa_ruta($denuncia_id, $etapa_destino) {
    $denuncia_id = (int)$denuncia_id;

    switch ($etapa_destino) {
        case 'encuentro_inicial':
            $hay = db_exists('encuentros', ['denuncia_id' => $denuncia_id, 'etapa' => 'encuentro_inicial']);
            return ['cumple' => (bool)$hay, 'falta' => 'un encuentro de etapa encuentro_inicial creado'];

        case 'atencion_integral':
            $acta = db_select_one(
                'SELECT id FROM actas_encuentro WHERE denuncia_id = ? AND cerrada = 1 LIMIT 1',
                [$denuncia_id]
            );
            return ['cumple' => (bool)$acta, 'falta' => 'el acta del encuentro inicial cerrada'];

        case 'investigacion':
            $remision = db_select_one(
                "SELECT r.id FROM remisiones_externas r
                  WHERE r.denuncia_id = ? LIMIT 1",
                [$denuncia_id]
            );
            // La remisión interna a la OCID se registra como compromiso de unidad;
            // cualquiera de las dos evidencias habilita la etapa
            $interna = db_select_one(
                "SELECT id FROM compromisos
                  WHERE denuncia_id = ? AND origen = 'remision' LIMIT 1",
                [$denuncia_id]
            );
            return ['cumple' => (bool)($remision || $interna), 'falta' => 'la remisión a la OCID (o externa) registrada'];

        case 'seguimiento':
            $plan = db_select_one(
                'SELECT id FROM plan_acompanamiento WHERE denuncia_id = ? LIMIT 1',
                [$denuncia_id]
            );
            return ['cumple' => (bool)$plan, 'falta' => 'al menos un plan de acompañamiento'];

        default:
            return ['cumple' => false, 'falta' => 'etapa de ruta desconocida'];
    }
}

/**
 * Avanzar la etapa de la ruta de atención (RN-19)
 *
 * Sin la evidencia de la etapa, el avance solo procede de oficio con
 * motivación, y queda auditado como tal.
 *
 * @return array ['exito' => bool, 'error' => string|null]
 */
function avanzar_etapa_ruta($denuncia_id, $etapa_destino, $usuario_id, $motivacion_oficio = null) {
    $denuncia_id = (int)$denuncia_id;

    if (!in_array($etapa_destino, ETAPAS_RUTA, true)) {
        return ['exito' => false, 'error' => 'Etapa de ruta no válida.'];
    }

    $denuncia = db_select_one('SELECT etapa_ruta FROM denuncias WHERE id = ? LIMIT 1', [$denuncia_id]);

    if (!$denuncia) {
        return ['exito' => false, 'error' => 'Expediente no encontrado.'];
    }

    $actual  = array_search($denuncia['etapa_ruta'], ETAPAS_RUTA, true);
    $destino = array_search($etapa_destino, ETAPAS_RUTA, true);

    if ($destino <= $actual) {
        return ['exito' => false, 'error' => 'La ruta de atención no retrocede.'];
    }

    $de_oficio = false;
    $evidencia = evidencia_etapa_ruta($denuncia_id, $etapa_destino);

    if (!$evidencia['cumple']) {
        if ($motivacion_oficio === null || trim($motivacion_oficio) === '') {
            return ['exito' => false,
                    'error' => 'RN-19: falta ' . $evidencia['falta']
                             . '. El avance de oficio exige motivación.'];
        }
        $de_oficio = true;
    }

    $ok = db_update_simple('denuncias', [
        'etapa_ruta' => $etapa_destino,
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => $denuncia_id]);

    if ($ok === false) {
        return ['exito' => false, 'error' => 'No se pudo avanzar la etapa de ruta.'];
    }

    audit_log($usuario_id, $de_oficio ? 'etapa_ruta_avanzada_oficio' : 'etapa_ruta_avanzada', 'denuncias', [
        'desde'      => $denuncia['etapa_ruta'],
        'hasta'      => $etapa_destino,
        'motivacion' => $de_oficio ? mb_substr(trim($motivacion_oficio), 0, 300) : null,
    ], $denuncia_id);

    return ['exito' => true, 'error' => null];
}

/**
 * Completar el relato (RN-03 · §16 E2b)
 *
 * El relato del formulario puede completarse UNA sola vez, por EDHEG, durante
 * el encuentro inicial. Sigue viviendo en el único registro de `denuncias`.
 *
 * @return array ['exito' => bool, 'error' => string|null]
 */
function completar_relato($denuncia_id, $texto_adicional, $usuario_id) {
    $denuncia_id = (int)$denuncia_id;
    $texto_adicional = trim((string)$texto_adicional);

    if ($texto_adicional === '') {
        return ['exito' => false, 'error' => 'No hay texto que añadir.'];
    }

    $denuncia = db_select_one(
        'SELECT relato_completado_at FROM denuncias WHERE id = ? LIMIT 1',
        [$denuncia_id]
    );

    if (!$denuncia) {
        return ['exito' => false, 'error' => 'Expediente no encontrado.'];
    }

    if ($denuncia['relato_completado_at'] !== null) {
        return ['exito' => false,
                'error' => 'RN-03: el relato ya fue completado una vez y no puede volver a modificarse.'];
    }

    $marca = "\n\n--- Ampliación registrada en el encuentro inicial ("
           . date('Y-m-d') . ") ---\n" . $texto_adicional;

    $ok = db_execute(
        'UPDATE denuncias
            SET descripcion = CONCAT(descripcion, ?),
                relato_completado_at = NOW(),
                relato_completado_por = ?
          WHERE id = ? AND relato_completado_at IS NULL',
        [$marca, (int)$usuario_id, $denuncia_id]
    );

    if (!$ok) {
        return ['exito' => false, 'error' => 'No se pudo completar el relato.'];
    }

    // La auditoría registra el hecho, jamás el contenido (prohibición 1)
    audit_log($usuario_id, 'relato_completado', 'denuncias', [
        'longitud_agregada' => mb_strlen($texto_adicional),
    ], $denuncia_id);

    return ['exito' => true, 'error' => null];
}
