<?php
/**
 * ADMIN · TURNOS DE ATENCIÓN
 *
 * v3.1 §7.5 · Turnos por sede y profesional (RN-18). Los horarios reales los
 * define Bienestar (§12.4 punto 3).
 */

require BASE_PATH . '/modules/admin/_guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'crear') {
    $dia = input('dia_semana', 0, 'int');
    $unidad_id = input('unidad_id', 0, 'int');
    $usuario_id = input('usuario_id', 0, 'int');

    if ($dia < 1 || $dia > 7 || $unidad_id <= 0 || $usuario_id <= 0
        || !input('hora_inicio') || !input('hora_fin')) {
        $flash_local = ['error', 'Todos los campos son obligatorios.'];
    } else {
        $id = db_insert_simple('turnos_atencion', [
            'unidad_id'   => $unidad_id,
            'usuario_id'  => $usuario_id,
            'dia_semana'  => $dia,
            'hora_inicio' => input('hora_inicio'),
            'hora_fin'    => input('hora_fin'),
            'activo'      => 1,
        ]);
        if ($id) {
            audit_log($usuario_actual['id'], 'turno_creado', 'turnos_atencion', [], (int)$id);
        }
        $flash_local = $id ? ['ok', 'Turno creado.'] : ['error', 'No se pudo crear.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'alternar') {
    $turno = db_select_one('SELECT id, activo FROM turnos_atencion WHERE id = ? LIMIT 1',
                           [input('turno_id', 0, 'int')]);
    if ($turno) {
        db_update_simple('turnos_atencion', ['activo' => (int)$turno['activo'] === 1 ? 0 : 1],
                         ['id' => $turno['id']]);
        audit_log($usuario_actual['id'], 'turno_alternado', 'turnos_atencion', [], (int)$turno['id']);
        $flash_local = ['ok', 'Turno actualizado.'];
    }
}

$sedes = db_select("SELECT id, nombre FROM unidades WHERE activa = 1 AND tipo = 'sede' ORDER BY nombre") ?: [];
$profesionales = db_select(
    "SELECT id, nombre FROM usuarios WHERE activo = 1
      AND rol IN ('psicologo','trabajador_social','abogado','coordinacion_edheg','coordinador')
      ORDER BY nombre") ?: [];
$turnos = turnos_configurados(false);
$dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

$title = 'Turnos de atención';

ob_start();
?>
<div class="max-w-4xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <h2 class="font-semibold text-gray-800 mb-1">Nuevo turno</h2>
        <p class="text-xs text-gray-500 mb-3">Sin turnos configurados, el encuentro inicial automático (RN-18)
            cae a la bandeja «Por agendar».</p>
        <?php if (empty($sedes)): ?>
            <p class="text-sm text-yellow-700">Primero registre al menos una sede en «Unidades».</p>
        <?php else: ?>
        <form method="POST" class="grid grid-cols-2 lg:grid-cols-6 gap-2 items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="crear">
            <div class="col-span-2">
                <label class="block text-sm text-gray-600" for="unidad_id">Sede</label>
                <select name="unidad_id" id="unidad_id" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                    <?php foreach ($sedes as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= e($s['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-span-2">
                <label class="block text-sm text-gray-600" for="usuario_id">Profesional</label>
                <select name="usuario_id" id="usuario_id" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                    <?php foreach ($profesionales as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-600" for="dia_semana">Día</label>
                <select name="dia_semana" id="dia_semana" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                    <?php foreach ($dias as $n => $nombre): ?>
                        <option value="<?= $n ?>"><?= e($nombre) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex space-x-1">
                <div>
                    <label class="block text-sm text-gray-600" for="hora_inicio">Inicio</label>
                    <input type="time" name="hora_inicio" id="hora_inicio" required class="w-full border border-gray-300 rounded-lg px-1 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-gray-600" for="hora_fin">Fin</label>
                    <input type="time" name="hora_fin" id="hora_fin" required class="w-full border border-gray-300 rounded-lg px-1 py-2 text-sm">
                </div>
            </div>
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Crear</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-lg shadow p-4 overflow-x-auto">
        <h2 class="font-semibold text-gray-800 mb-3">Turnos configurados</h2>
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left">
                <th class="p-2">Sede</th><th class="p-2">Profesional</th><th class="p-2">Día</th>
                <th class="p-2">Horario</th><th class="p-2">Estado</th><th class="p-2"></th>
            </tr></thead>
            <tbody>
            <?php if (empty($turnos)): ?>
                <tr><td colspan="6" class="p-2 text-gray-400">Sin turnos.</td></tr>
            <?php endif; ?>
            <?php foreach ($turnos as $t): ?>
                <tr class="border-b border-gray-100">
                    <td class="p-2"><?= e($t['sede']) ?></td>
                    <td class="p-2"><?= e($t['profesional']) ?></td>
                    <td class="p-2"><?= e($dias[(int)$t['dia_semana']] ?? '?') ?></td>
                    <td class="p-2"><?= e(substr($t['hora_inicio'], 0, 5)) ?>–<?= e(substr($t['hora_fin'], 0, 5)) ?></td>
                    <td class="p-2">
                        <span class="px-2 py-0.5 rounded text-xs <?= (int)$t['activo'] === 1 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                            <?= (int)$t['activo'] === 1 ? 'Activo' : 'Inactivo' ?></span>
                    </td>
                    <td class="p-2">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="alternar">
                            <input type="hidden" name="turno_id" value="<?= (int)$t['id'] ?>">
                            <button type="submit" class="text-purple-700 hover:underline text-xs">
                                <?= (int)$t['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
