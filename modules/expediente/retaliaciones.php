<?php
/**
 * PESTAÑA · RETALIACIONES
 *
 * v3.1 §7.1 · Ac. 003 art. 18: prohibición de retaliaciones; la verificación
 * se hace con especial rigor.
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit('Acceso denegado'); }

// ── POST: reportar ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'reportar') {
    $descripcion = trim((string)input('descripcion'));

    if ($descripcion === '') {
        $flash_local = ['error', 'La descripción es obligatoria.'];
    } else {
        $id = db_insert_simple('retaliaciones', [
            'denuncia_id'   => $denuncia_id,
            'descripcion'   => $descripcion,
            'reportada_por' => in_array(input('reportada_por'), ['victima', 'testigo', 'equipo'], true)
                             ? input('reportada_por') : 'equipo',
            'estado'        => 'reportada',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        if ($id) {
            audit_log($usuario_actual['id'], 'retaliacion_reportada', 'retaliaciones', [
                'motivo' => input('reportada_por'),
            ], (int)$id);
        }
        $flash_local = $id ? ['ok', 'Retaliación reportada. La verificación procede con especial rigor (art. 18).']
                           : ['error', 'No se pudo registrar.'];
    }
}

// ── POST: verificar ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'verificar') {
    $estado = input('nuevo_estado');
    $obs = trim((string)input('observaciones_verificacion'));

    if (!in_array($estado, ['en_verificacion', 'acreditada', 'no_acreditada'], true)) {
        $flash_local = ['error', 'Estado no válido.'];
    } elseif (in_array($estado, ['acreditada', 'no_acreditada'], true) && $obs === '') {
        $flash_local = ['error', 'Acreditar o descartar exige las observaciones de la verificación.'];
    } else {
        $ok = db_update_simple('retaliaciones', [
            'estado'         => $estado,
            'verificada_por' => $usuario_actual['id'],
            'verificada_at'  => date('Y-m-d H:i:s'),
            'observaciones_verificacion' => $obs ?: null,
        ], ['id' => input('retaliacion_id', 0, 'int')]);

        if ($ok !== false) {
            audit_log($usuario_actual['id'], 'retaliacion_verificada', 'retaliaciones', [
                'motivo' => $estado,
            ], input('retaliacion_id', 0, 'int'));
        }
        $flash_local = $ok !== false ? ['ok', 'Verificación registrada.'] : ['error', 'No se pudo actualizar.'];
    }
}

$retaliaciones = db_select(
    'SELECT * FROM retaliaciones WHERE denuncia_id = ? ORDER BY created_at DESC', [$denuncia_id]) ?: [];
?>

<?php if ($flash_local): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
        <?= e($flash_local[1]) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Reportar retaliación (Ac. 003 art. 18)</h3>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="reportar">
            <label class="block text-sm text-gray-600" for="reportada_por">Reportada por</label>
            <select name="reportada_por" id="reportada_por" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="victima">La persona afectada</option>
                <option value="testigo">Persona testigo</option>
                <option value="equipo">El equipo EDHEG</option>
            </select>
            <label class="block text-sm text-gray-600" for="descripcion_ret">Descripción de los hechos de retaliación</label>
            <textarea name="descripcion" id="descripcion_ret" rows="4" required
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Reportar</button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Retaliaciones del caso</h3>
        <ul class="text-sm space-y-3">
            <?php if (empty($retaliaciones)): ?>
                <li class="text-gray-400">Sin retaliaciones reportadas.</li>
            <?php endif; ?>
            <?php foreach ($retaliaciones as $ret): ?>
                <li class="border border-gray-200 rounded-lg p-3">
                    <div class="flex justify-between">
                        <p class="text-xs text-gray-500"><?= e(format_datetime($ret['created_at'])) ?> · reportada por <?= e($ret['reportada_por']) ?></p>
                        <span class="px-2 py-0.5 rounded text-xs
                            <?= $ret['estado'] === 'acreditada' ? 'bg-red-100 text-red-800'
                              : ($ret['estado'] === 'no_acreditada' ? 'bg-gray-100 text-gray-800' : 'bg-yellow-100 text-yellow-800') ?>">
                            <?= e(str_replace('_', ' ', $ret['estado'])) ?></span>
                    </div>
                    <p class="mt-1"><?= e(mb_substr($ret['descripcion'], 0, 200)) ?></p>
                    <?php if (in_array($ret['estado'], ['reportada', 'en_verificacion'], true)): ?>
                        <form method="POST" class="mt-2 flex flex-wrap gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="verificar">
                            <input type="hidden" name="retaliacion_id" value="<?= (int)$ret['id'] ?>">
                            <select name="nuevo_estado" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                                <?php if ($ret['estado'] === 'reportada'): ?><option value="en_verificacion">En verificación</option><?php endif; ?>
                                <option value="acreditada">Acreditada</option>
                                <option value="no_acreditada">No acreditada</option>
                            </select>
                            <input type="text" name="observaciones_verificacion" placeholder="Observaciones de la verificación"
                                   class="flex-1 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Aplicar</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
