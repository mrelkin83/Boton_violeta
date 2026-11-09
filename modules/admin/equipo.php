<?php
/**
 * ADMIN · IDONEIDAD DEL EQUIPO
 *
 * v3.1 §7.5 · Certificaciones (RN-11, vigencia 2 años, alerta a 60 días),
 * acuerdos de confidencialidad (Ac. 002 art. 25 par.) e impedimentos
 * declarados (solo lectura: el bloqueo RN-12 es irreversible).
 */

require BASE_PATH . '/modules/admin/_guard.php';

// ── POST: registrar certificación ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'certificar') {
    $usuario_id = input('usuario_id', 0, 'int');
    $emitida = input('emitida_at');

    if ($usuario_id <= 0 || !$emitida || trim((string)input('nombre_curso')) === '') {
        $flash_local = ['error', 'Usuario, curso y fecha de emisión son obligatorios.'];
    } else {
        $id = db_insert_simple('certificaciones_equipo', [
            'usuario_id'   => $usuario_id,
            'nombre_curso' => trim(input('nombre_curso')),
            'emitida_at'   => $emitida,
            'vence_at'     => date('Y-m-d', strtotime($emitida . ' +2 years')), // Ac. 002 art. 20
        ]);
        if ($id) {
            audit_log($usuario_actual['id'], 'certificacion_registrada', 'certificaciones_equipo', [], (int)$id);
        }
        $flash_local = $id ? ['ok', 'Certificación registrada (vigencia 2 años).'] : ['error', 'No se pudo registrar.'];
    }
}

$equipo = db_select(
    "SELECT u.id, u.nombre, u.rol,
            (SELECT MAX(vence_at) FROM certificaciones_equipo c WHERE c.usuario_id = u.id) AS certificacion_vence,
            (SELECT MAX(aceptado_at) FROM acuerdos_confidencialidad a WHERE a.usuario_id = u.id) AS acuerdo_aceptado
       FROM usuarios u
      WHERE u.activo = 1
        AND u.rol IN ('coordinacion_edheg','coordinador','psicologo','trabajador_social','abogado','instructor','decisor')
      ORDER BY u.nombre") ?: [];

$impedimentos = db_select(
    'SELECT i.*, u.nombre FROM impedimentos i JOIN usuarios u ON u.id = i.usuario_id
      WHERE i.declarado = 1 ORDER BY i.created_at DESC LIMIT 30') ?: [];

$title = 'Idoneidad del equipo';

ob_start();
?>
<div class="max-w-5xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <h2 class="font-semibold text-gray-800 mb-3">Registrar certificación (RN-11)</h2>
        <form method="POST" class="grid grid-cols-2 lg:grid-cols-4 gap-2 items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="certificar">
            <select name="usuario_id" required class="border border-gray-300 rounded-lg px-2 py-2 text-sm">
                <option value="">Persona…</option>
                <?php foreach ($equipo as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="nombre_curso" placeholder="Curso / capacitación" required
                   class="border border-gray-300 rounded-lg px-2 py-2 text-sm">
            <input type="date" name="emitida_at" required class="border border-gray-300 rounded-lg px-2 py-2 text-sm">
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Registrar</button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow p-4 mb-4 overflow-x-auto">
        <h2 class="font-semibold text-gray-800 mb-3">Estado del equipo</h2>
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left">
                <th class="p-2">Persona</th><th class="p-2">Rol</th>
                <th class="p-2">Certificación vence</th><th class="p-2">Acuerdo de confidencialidad</th>
            </tr></thead>
            <tbody>
            <?php foreach ($equipo as $u): ?>
                <?php
                $vence = $u['certificacion_vence'];
                $dias = $vence ? dias_restantes($vence) : null;
                ?>
                <tr class="border-b border-gray-100">
                    <td class="p-2"><?= e($u['nombre']) ?></td>
                    <td class="p-2 text-gray-500"><?= e($u['rol']) ?></td>
                    <td class="p-2">
                        <?php if (!$vence): ?>
                            <span class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-800">Sin certificación — bloquea asignaciones</span>
                        <?php elseif ($dias < 0): ?>
                            <span class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-800">Vencida (<?= e(format_date($vence)) ?>)</span>
                        <?php elseif ($dias <= 60): ?>
                            <span class="px-2 py-0.5 rounded text-xs bg-yellow-100 text-yellow-800">Vence en <?= (int)$dias ?> días (<?= e(format_date($vence)) ?>)</span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-800">Vigente (<?= e(format_date($vence)) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-2">
                        <?= $u['acuerdo_aceptado']
                            ? '<span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-800">Aceptado ' . e(format_date($u['acuerdo_aceptado'])) . '</span>'
                            : '<span class="px-2 py-0.5 rounded text-xs bg-yellow-100 text-yellow-800">Pendiente</span>' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-800 mb-1">Impedimentos declarados (RN-12)</h2>
        <p class="text-xs text-gray-500 mb-3">Solo lectura: un impedimento declarado bloquea el acceso a
            ese expediente de forma irreversible. No se muestra el expediente, solo el hecho.</p>
        <ul class="text-sm space-y-1">
            <?php foreach ($impedimentos as $i): ?>
                <li class="flex justify-between border-b border-gray-100 pb-1">
                    <span><?= e($i['nombre']) ?><?= $i['causal'] ? ' · ' . e($i['causal']) : '' ?></span>
                    <span class="text-gray-500 text-xs"><?= e(format_datetime($i['created_at'])) ?></span>
                </li>
            <?php endforeach; ?>
            <?php if (empty($impedimentos)): ?><li class="text-gray-400">Sin impedimentos declarados.</li><?php endif; ?>
        </ul>
    </div>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
