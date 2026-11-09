<?php
/**
 * BANDEJA DE REPORTES ANÓNIMOS
 *
 * v3.1 · Fase F · §7.6 · RN-21: un reporte con mérito no puede quedar sin
 * decisión más de DIAS_DECISION_REPORTE_ANONIMO días hábiles.
 * Solo coordinación.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_auth();
require_any_role(['coordinacion_edheg', 'coordinador']);

$usuario_actual = auth_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
}

$flash_local = null;

// ── POST: decisión RN-21 ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'decidir') {
    $r = decidir_reporte_anonimo(
        input('reporte_id', 0, 'int'), input('decision'),
        input('motivacion'), $usuario_actual['id'], $usuario_actual['rol']
    );

    if ($r['exito']) {
        $flash_local = ['ok', $r['denuncia_id']
            ? 'Actuación de oficio abierta: expediente #' . $r['denuncia_id'] . ' (bajo reserva RN-02).'
            : 'Reporte archivado con motivación.'];
    } else {
        $flash_local = ['error', $r['error']];
    }
}

// ── POST: cambiar estado operativo ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'estado') {
    $estado = input('nuevo_estado');
    if (in_array($estado, ['en_analisis', 'medidas_activadas', 'archivado'], true)) {
        db_update_simple('reportes_anonimos', ['estado' => $estado], ['id' => input('reporte_id', 0, 'int')]);
        audit_log($usuario_actual['id'], 'reporte_anonimo_estado', 'reportes_anonimos', [
            'motivo' => $estado,
        ], input('reporte_id', 0, 'int'));
        $flash_local = ['ok', 'Estado actualizado.'];
    }
}

$reportes = db_select('SELECT * FROM reportes_anonimos ORDER BY created_at DESC LIMIT 100') ?: [];
$plazo_decision = (int)config_valor('DIAS_DECISION_REPORTE_ANONIMO', 10);

$title = 'Reportes anónimos';

ob_start();
?>
<div class="max-w-4xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-800 mb-1">Reportes anónimos y escraches (Ac. 002 art. 33)</h2>
        <p class="text-xs text-gray-500 mb-4">RN-21: un reporte con pruebas o autor identificable exige
            decisión motivada en máximo <?= $plazo_decision ?> días hábiles: convertir en actuación de
            oficio o archivar sin mérito.</p>

        <ul class="space-y-3 text-sm">
            <?php if (empty($reportes)): ?>
                <li class="text-gray-400">Sin reportes recibidos.</li>
            <?php endif; ?>
            <?php foreach ($reportes as $r): ?>
                <?php
                $vencido = (int)$r['tiene_pruebas'] === 1 && $r['decision'] === null
                        && dias_habiles_entre(date('Y-m-d', strtotime($r['created_at'])), date('Y-m-d')) > $plazo_decision;
                ?>
                <li class="border <?= $vencido ? 'border-red-300 bg-red-50' : 'border-gray-200' ?> rounded-lg p-3">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs text-gray-500">
                                <?= e(format_datetime($r['created_at'])) ?> · <?= e($r['tipo']) ?>
                                <?= (int)$r['tiene_pruebas'] === 1 ? '· CON mérito (RN-21)' : '' ?>
                                <?= $r['espacio_senalado'] ? '· espacio: ' . e($r['espacio_senalado']) : '' ?>
                                <?= $vencido ? '· ⚠ DECISIÓN VENCIDA' : '' ?>
                            </p>
                            <p class="mt-1"><?= e(mb_substr($r['descripcion'], 0, 240)) ?></p>
                        </div>
                        <span class="px-2 py-0.5 rounded text-xs h-fit bg-purple-100 text-purple-800"><?= e($r['estado']) ?></span>
                    </div>

                    <?php if ($r['decision'] !== null): ?>
                        <p class="text-xs mt-2 <?= $r['decision'] === 'convertir_en_expediente_oficio' ? 'text-purple-700' : 'text-gray-500' ?>">
                            Decisión: <?= e(str_replace('_', ' ', $r['decision'])) ?>
                            <?= $r['denuncia_id'] ? '· expediente de oficio creado' : '' ?>
                            · <?= e(format_datetime($r['decidida_at'])) ?></p>
                    <?php else: ?>
                        <form method="POST" class="mt-2 flex flex-wrap gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="decidir">
                            <input type="hidden" name="reporte_id" value="<?= (int)$r['id'] ?>">
                            <select name="decision" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                                <option value="convertir_en_expediente_oficio">Convertir en actuación de oficio</option>
                                <option value="archivar_sin_merito">Archivar sin mérito</option>
                            </select>
                            <input type="text" name="motivacion" required placeholder="Motivación (obligatoria)"
                                   class="flex-1 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Decidir</button>
                        </form>
                        <form method="POST" class="mt-2 flex gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="estado">
                            <input type="hidden" name="reporte_id" value="<?= (int)$r['id'] ?>">
                            <select name="nuevo_estado" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                                <option value="en_analisis">En análisis</option>
                                <option value="medidas_activadas">Medidas preventivas activadas</option>
                            </select>
                            <button type="submit" class="px-3 py-1 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm">Cambiar estado</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
