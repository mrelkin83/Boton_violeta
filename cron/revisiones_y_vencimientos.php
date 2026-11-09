<?php
/**
 * CRON · REVISIÓN TRIMESTRAL Y VENCIMIENTO DE PLAZOS
 *
 * Fase 5 · Ac. 002 art. 30 · CLAUDE.md §10.5
 *
 * Ejecutar una vez al día, temprano:
 *   0 6 * * * /usr/bin/php /ruta/al/proyecto/cron/revisiones_y_vencimientos.php
 *
 * Dos obligaciones distintas:
 *
 *   1. REVISIÓN TRIMESTRAL (art. 30). Toda medida implementada debe revisarse
 *      cada tres meses. El levantamiento debe ser motivado.
 *   2. VENCIMIENTOS. Aviso a 3 días hábiles, alerta al vencer y escalamiento a
 *      coordinación a los 2 días de vencido.
 *
 * Un plazo vencido sin alerta es, ante la Corte Constitucional, un
 * incumplimiento documentado. Que nadie lo hubiera notado no es una defensa.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/init.php';

$ruta_bloqueo = BASE_PATH . '/cache/revisiones.lock';
$bloqueo = fopen($ruta_bloqueo, 'c');

if ($bloqueo === false || !flock($bloqueo, LOCK_EX | LOCK_NB)) {
    if ($bloqueo !== false) { fclose($bloqueo); }
    exit(0);
}

$avisos = 0;
$revisiones = 0;
$escalamientos = 0;

try {
    // ── 1. Revisión trimestral (art. 30) ─────────────────────────────────
    foreach (medidas_por_revisar() as $medida) {
        foreach (destinatarios_de_caso((int)$medida['denuncia_id']) as $correo) {
            if (encolar_notificacion('correo', $correo, 'revision_trimestral', [
                'radicado' => $medida['codigo'],
                'enlace'   => url('?page=denuncias'),
            ], (int)$medida['denuncia_id'])) {
                $revisiones++;
            }
        }

        // Se reprograma para no notificar lo mismo cada día hasta que alguien
        // la atienda: el ruido diario acaba en una regla de bandeja de entrada
        // que archiva todo, y entonces la alerta deja de existir.
        db_update_simple('medidas', [
            'proxima_revision' => date('Y-m-d', strtotime('+7 days')),
        ], ['id' => $medida['id']]);

        audit_log(null, 'revision_trimestral_notificada', 'medidas', [
            'radicado' => $medida['codigo'],
        ], (int)$medida['denuncia_id']);

        // v3.1 · §16 E5: cada revisión trimestral genera una SOLICITUD DE
        // ESTADO (compromiso origen `seguimiento`) a la unidad ejecutora, que
        // responde desde su bandeja sin acceder al expediente.
        if (!empty($medida['unidad_ejecutora_id'])) {
            $ya_abierta = db_select_one(
                "SELECT id FROM compromisos
                  WHERE denuncia_id = ? AND origen = 'seguimiento'
                    AND responsable_unidad_id = ? AND respondido_at IS NULL
                    AND estado IN ('pendiente','en_curso','prorrogado','incumplido') LIMIT 1",
                [(int)$medida['denuncia_id'], (int)$medida['unidad_ejecutora_id']]
            );

            if (!$ya_abierta) {
                $plazo_respuesta = (int)config_valor('DIAS_RESPUESTA_SOLICITUD_ESTADO', 10);
                crear_compromiso([
                    'denuncia_id'           => (int)$medida['denuncia_id'],
                    'origen'                => 'seguimiento',
                    'descripcion'           => 'Informar el estado de la actuación a su cargo '
                                             . '(seguimiento trimestral, Protocolo E5).',
                    'responsable_tipo'      => 'unidad',
                    'responsable_unidad_id' => (int)$medida['unidad_ejecutora_id'],
                    'fecha_limite'          => sumar_dias_habiles(date('Y-m-d'), $plazo_respuesta),
                ], null);
            }
        }
    }

    // ── 2. Plazos por vencer y vencidos ──────────────────────────────────
    $pendientes = db_select(
        "SELECT m.id, m.denuncia_id, m.fecha_limite, m.estado, d.codigo
           FROM medidas m JOIN denuncias d ON d.id = m.denuncia_id
          WHERE m.estado IN ('solicitada','notificada','en_ejecucion')"
    ) ?: [];

    foreach ($pendientes as $medida) {
        $restantes = dias_restantes($medida['fecha_limite']);

        $plantilla = null;

        if ($restantes === 3) {
            $plantilla = 'plazo_por_vencer';
        } elseif ($restantes <= 0 && $restantes >= -2) {
            $plantilla = 'plazo_vencido';
        }

        if ($plantilla === null) {
            continue;
        }

        foreach (destinatarios_de_caso((int)$medida['denuncia_id']) as $correo) {
            if (encolar_notificacion('correo', $correo, $plantilla, [
                'radicado' => $medida['codigo'],
                'plazo'    => date('d/m/Y', strtotime($medida['fecha_limite'])),
                'enlace'   => url('?page=denuncias'),
            ], (int)$medida['denuncia_id'])) {
                $avisos++;
            }
        }

        // Escalamiento a los 2 días hábiles de vencido: la medida pasa a
        // `incumplida` y queda constancia motivada
        if ($restantes <= -2 && $medida['estado'] !== 'incumplida') {
            cambiar_estado_medida(
                (int)$medida['id'], 'incumplida', null,
                'Escalamiento automático: la medida superó en 2 días hábiles su fecha límite '
                . 'sin registrar constancia de cumplimiento.'
            );
            $escalamientos++;
        }
    }

    // ── 3. v3.1 §9 · Compromisos: aviso, alerta y escalamiento ──────────
    $dias_aviso = (int)config_valor('DIAS_AVISO_VENCIMIENTO', 3);
    $dias_escalamiento = (int)config_valor('DIAS_ESCALAMIENTO', 2);

    $compromisos_activos = db_select(
        "SELECT c.id, c.denuncia_id, c.fecha_limite, c.estado, c.responsable_usuario_id,
                d.codigo
           FROM compromisos c JOIN denuncias d ON d.id = c.denuncia_id
          WHERE c.estado IN ('pendiente','en_curso','prorrogado')"
    ) ?: [];

    foreach ($compromisos_activos as $compromiso) {
        $restantes = dias_restantes($compromiso['fecha_limite']);
        $plantilla = null;

        if ($restantes === $dias_aviso) {
            $plantilla = 'plazo_por_vencer';
        } elseif ($restantes <= 0 && $restantes >= -$dias_escalamiento) {
            $plantilla = 'plazo_vencido';
        }

        if ($plantilla !== null && !empty($compromiso['responsable_usuario_id'])) {
            $avisos += encolar_notificacion_grupo(
                destinatarios_de_usuario((int)$compromiso['responsable_usuario_id']),
                $plantilla,
                [
                    'radicado' => $compromiso['codigo'],
                    'plazo'    => date('d/m/Y', strtotime($compromiso['fecha_limite'])),
                    'enlace'   => url('?page=mis-compromisos'),
                ],
                (int)$compromiso['denuncia_id']
            );
        }

        // Escalamiento a coordinación tras el plazo de gracia
        if ($restantes < -$dias_escalamiento) {
            cambiar_estado_compromiso((int)$compromiso['id'], 'incumplido', 0, [
                'motivacion' => 'Escalamiento automático: el compromiso superó su fecha límite '
                              . "en más de {$dias_escalamiento} días hábiles.",
            ]);

            foreach (destinatarios_de_caso((int)$compromiso['denuncia_id']) as $correo) {
                encolar_notificacion('correo', $correo, 'plazo_vencido', [
                    'radicado' => $compromiso['codigo'],
                    'plazo'    => date('d/m/Y', strtotime($compromiso['fecha_limite'])),
                    'enlace'   => url('?page=expediente&id=' . (int)$compromiso['denuncia_id'] . '&tab=compromisos'),
                ], (int)$compromiso['denuncia_id']);
            }
            $escalamientos++;
        }
    }

    // ── 4. RN-21 · reportes anónimos con mérito sin decisión ────────────
    $plazo_decision = (int)config_valor('DIAS_DECISION_REPORTE_ANONIMO', 10);

    foreach (reportes_anonimos_sin_decision() as $reporte) {
        if ((int)$reporte['tiene_pruebas'] !== 1) { continue; }

        $transcurridos = dias_habiles_entre(
            date('Y-m-d', strtotime($reporte['created_at'])), date('Y-m-d'));

        if ($transcurridos <= $plazo_decision) { continue; }

        // Se avisa a coordinación una sola vez por reporte
        $ya_avisado = db_select_one(
            "SELECT id FROM auditoria
              WHERE accion = 'rn21_decision_vencida' AND tabla = 'reportes_anonimos'
                AND registro_id = ? LIMIT 1",
            [(int)$reporte['id']]
        );

        if ($ya_avisado) { continue; }

        audit_log(null, 'rn21_decision_vencida', 'reportes_anonimos', [
            'plazo' => $plazo_decision . ' dias habiles',
        ], (int)$reporte['id']);

        $responsables = db_select(
            "SELECT correo FROM responsables_notificacion
              WHERE activo = 1 AND rol_destino IN ('coordinacion_edheg','coordinador')"
        ) ?: [];

        foreach ($responsables as $resp) {
            encolar_notificacion('correo', $resp['correo'], 'plazo_vencido', [
                'radicado' => 'REPORTE-ANONIMO',
                'plazo'    => date('d/m/Y'),
                'enlace'   => url('?page=prevencion-reportes'),
            ]);
            $avisos++;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sellado de evidencias pendientes
    //
    // Los archivos cargados antes de la Fase 6 no tienen hash. La función que
    // los sella existía y no la llamaba nadie: quedaban esperando un sellado
    // que nunca llegaba. Se ejecuta aquí porque solo actúa sobre filas con
    // `hash_sha256 IS NULL` — repetirla no tiene efecto una vez vaciada.
    //
    // El sello no es retroactivo: garantiza integridad de aquí en adelante,
    // no que el archivo no fuera alterado antes de sellarlo.
    // ─────────────────────────────────────────────────────────────────────
    $sellado = sellar_evidencias_pendientes();

    if ($sellado['sellados'] > 0 || $sellado['fallidos'] > 0) {
        log_error(sprintf(
            'Sellado de evidencias: %d selladas, %d fallidas',
            $sellado['sellados'], $sellado['fallidos']
        ), $sellado['fallidos'] > 0 ? 'error' : 'info');
    }

    if ($revisiones + $avisos + $escalamientos > 0) {
        log_error(sprintf(
            'Revisiones y vencimientos: %d revisiones trimestrales, %d avisos de plazo, %d escalamientos',
            $revisiones, $avisos, $escalamientos
        ), 'info');
    }

    echo "revisiones=$revisiones avisos=$avisos escalamientos=$escalamientos"
       . " selladas={$sellado['sellados']} fallidas={$sellado['fallidos']}\n";

} catch (Throwable $e) {
    log_error('Error en revisiones y vencimientos: ' . $e->getMessage(), 'error');
    echo "error\n";
} finally {
    flock($bloqueo, LOCK_UN);
    fclose($bloqueo);
}
