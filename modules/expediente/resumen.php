<?php
/**
 * PESTAÑA · RESUMEN
 *
 * v3.1 §7.1 · Radicado, etapas (procesal y de ruta), banda de riesgo,
 * unidades, equipo asignado, vencimientos y semáforo de compromisos.
 * Corrige H-22: el cambio de etapa lo hace coordinación (rol actual), y el
 * estado se DERIVA de la etapa (H-23) — ya no se edita a mano.
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit('Acceso denegado'); }

$es_coordinacion = in_array($usuario_actual['rol'], ['coordinacion_edheg', 'coordinador'], true);

// ── POST: cambiar etapa procesal (solo coordinación) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'cambiar_etapa' && $es_coordinacion) {
    $r = cambiar_etapa_procesal($denuncia_id, input('etapa_procesal'), $usuario_actual['id'], input('observacion'));
    $flash_local = $r['exito']
        ? ['ok', 'Etapa procesal actualizada; el estado se derivó automáticamente.']
        : ['error', $r['error']];
    if ($r['exito']) {
        $denuncia = db_select_one('SELECT * FROM denuncias WHERE id = ? LIMIT 1', [$denuncia_id]);
    }
}

// ── POST: avanzar etapa de ruta (RN-19) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'avanzar_ruta' && $es_coordinacion) {
    $r = avanzar_etapa_ruta($denuncia_id, input('etapa_ruta'), $usuario_actual['id'], input('motivacion_oficio'));
    $flash_local = $r['exito'] ? ['ok', 'La ruta de atención avanzó.'] : ['error', $r['error']];
    if ($r['exito']) {
        $denuncia = db_select_one('SELECT * FROM denuncias WHERE id = ? LIMIT 1', [$denuncia_id]);
    }
}

$banda = banda_riesgo_vigente($denuncia_id);

$unidad_hechos = $denuncia['unidad_hechos_id']
    ? db_select_one('SELECT nombre FROM unidades WHERE id = ?', [$denuncia['unidad_hechos_id']]) : null;
$unidad_atencion = $denuncia['unidad_atencion_id']
    ? db_select_one('SELECT nombre FROM unidades WHERE id = ?', [$denuncia['unidad_atencion_id']]) : null;

$equipo = db_select(
    'SELECT a.funcion, u.nombre FROM asignaciones a JOIN usuarios u ON u.id = a.usuario_id
      WHERE a.denuncia_id = ? AND a.activa = 1 ORDER BY a.funcion', [$denuncia_id]) ?: [];

$compromisos = compromisos_del_caso($denuncia_id);
$semaforo = ['verde' => 0, 'ambar' => 0, 'rojo' => 0];
foreach ($compromisos as $c) {
    if (in_array($c['estado'], ['pendiente', 'en_curso', 'prorrogado', 'incumplido'], true)) {
        $semaforo[semaforo_compromiso($c['fecha_limite'])]++;
    }
}

$proximos = db_select(
    "SELECT 'Medida' AS tipo, fecha_limite AS vence FROM medidas
      WHERE denuncia_id = ? AND estado IN ('solicitada','notificada','en_ejecucion')
      UNION ALL
     SELECT 'Compromiso', fecha_limite FROM compromisos
      WHERE denuncia_id = ? AND estado IN ('pendiente','en_curso','prorrogado')
      ORDER BY vence LIMIT 5", [$denuncia_id, $denuncia_id]) ?: [];

$proximo_encuentro = db_select_one(
    "SELECT etapa, modalidad, inicia_at FROM encuentros
      WHERE denuncia_id = ? AND inicia_at >= NOW() AND estado_sync <> 'cancelado'
      ORDER BY inicia_at LIMIT 1", [$denuncia_id]);
?>

<?php if ($flash_local): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
        <?= e($flash_local[1]) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Estado del caso</h3>
        <dl class="space-y-2 text-sm">
            <div><dt class="text-gray-500">Radicado</dt><dd class="font-medium"><?= e($denuncia['codigo']) ?></dd></div>
            <div><dt class="text-gray-500">Banda de riesgo vigente</dt>
                <dd><?php if ($banda): ?>
                    <span class="px-2 py-0.5 rounded text-xs font-semibold <?= $banda === 'alto' ? 'bg-red-100 text-red-800' : ($banda === 'medio' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800') ?>">
                        <?= e(strtoupper($banda)) ?></span>
                <?php else: ?><span class="text-gray-400">Por valorar</span><?php endif; ?></dd></div>
            <div><dt class="text-gray-500">Sede/espacio de los hechos</dt>
                <dd><?= e($unidad_hechos['nombre'] ?? 'Sin registrar') ?></dd></div>
            <div><dt class="text-gray-500">Unidad de atención (RN-17)</dt>
                <dd><?= e($unidad_atencion['nombre'] ?? 'Sin asignar') ?></dd></div>
            <div><dt class="text-gray-500">Radicación</dt>
                <dd><?= e(format_datetime($denuncia['created_at'])) ?></dd></div>
        </dl>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Equipo y compromisos</h3>
        <ul class="text-sm space-y-1 mb-4">
            <?php if (empty($equipo)): ?>
                <li class="text-gray-400">Sin asignaciones activas.</li>
            <?php endif; ?>
            <?php foreach ($equipo as $miembro): ?>
                <li><span class="text-gray-500"><?= e(str_replace('_', ' ', $miembro['funcion'])) ?>:</span>
                    <?= e($miembro['nombre']) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="text-sm text-gray-500 mb-2">Semáforo de compromisos</p>
        <div class="flex space-x-3 text-sm">
            <span class="px-2 py-1 rounded bg-green-100 text-green-800">Al día: <?= (int)$semaforo['verde'] ?></span>
            <span class="px-2 py-1 rounded bg-yellow-100 text-yellow-800">Próximos: <?= (int)$semaforo['ambar'] ?></span>
            <span class="px-2 py-1 rounded bg-red-100 text-red-800">Vencidos: <?= (int)$semaforo['rojo'] ?></span>
        </div>
        <?php if ($proximo_encuentro): ?>
            <p class="text-sm text-gray-500 mt-4">Próximo encuentro</p>
            <p class="text-sm"><?= e(format_datetime($proximo_encuentro['inicia_at'])) ?>
                · <?= e($proximo_encuentro['modalidad']) ?></p>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Próximos vencimientos</h3>
        <ul class="text-sm space-y-1">
            <?php if (empty($proximos)): ?>
                <li class="text-gray-400">Sin plazos activos.</li>
            <?php endif; ?>
            <?php foreach ($proximos as $p): ?>
                <li class="flex justify-between">
                    <span><?= e($p['tipo']) ?></span>
                    <span class="<?= esta_vencido($p['vence']) ? 'text-red-600 font-semibold' : 'text-gray-600' ?>">
                        <?= e(format_date($p['vence'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<?php if ($es_coordinacion): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">

    <!-- Etapa procesal (H-22/H-23) -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Etapa procesal</h3>
        <p class="text-xs text-gray-500 mb-3">El estado del caso se deriva de la etapa (H-23); ya no se edita por separado.
            RN-14: sin decisión registrada sobre la Procuraduría no se abre la investigación en vías de servidores públicos.</p>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="cambiar_etapa">
            <label class="block text-sm text-gray-600" for="etapa_procesal">Nueva etapa</label>
            <select name="etapa_procesal" id="etapa_procesal" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <?php foreach (ETAPAS_PROCESALES as $etapa): ?>
                    <option value="<?= e($etapa) ?>" <?= $denuncia['etapa_procesal'] === $etapa ? 'selected' : '' ?>>
                        <?= e(str_replace('_', ' ', $etapa)) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="block text-sm text-gray-600" for="observacion">Observación (queda en el historial)</label>
            <textarea name="observacion" id="observacion" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Cambiar etapa</button>
        </form>
    </div>

    <!-- Ruta de atención (RN-19) -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Ruta de atención (Res. 142/2026 art. 9)</h3>
        <p class="text-xs text-gray-500 mb-3">RN-19: cada avance exige la evidencia de la etapa; el avance de oficio exige motivación y queda auditado.</p>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="avanzar_ruta">
            <label class="block text-sm text-gray-600" for="etapa_ruta">Avanzar a</label>
            <select name="etapa_ruta" id="etapa_ruta" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <?php foreach (ETAPAS_RUTA as $etapa): ?>
                    <option value="<?= e($etapa) ?>" <?= ($denuncia['etapa_ruta'] ?? '') === $etapa ? 'selected' : '' ?>>
                        <?= e(str_replace('_', ' ', $etapa)) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="block text-sm text-gray-600" for="motivacion_oficio">Motivación (solo para avance de oficio)</label>
            <textarea name="motivacion_oficio" id="motivacion_oficio" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Avanzar ruta</button>
        </form>
    </div>
</div>
<?php endif; ?>
