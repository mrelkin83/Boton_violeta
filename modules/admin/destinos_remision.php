<?php
/**
 * ADMIN · DESTINOS DE REMISIÓN EXTERNA
 *
 * v3.1 §7.5 · Catálogo de autoridades externas. Añadir un destino o cambiar
 * su fundamento toca la compuerta G-13: el panel exige el fundamento y deja
 * la creación auditada; los destinos sin fundamento aprobado quedan inactivos.
 */

require BASE_PATH . '/modules/admin/_guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'crear') {
    $codigo = strtolower(trim((string)input('codigo')));
    $nombre = trim((string)input('nombre'));
    $fundamento = trim((string)input('fundamento'));

    if ($codigo === '' || $nombre === '' || $fundamento === '') {
        $flash_local = ['error', 'Código, nombre y fundamento son obligatorios (G-13).'];
    } elseif (db_exists('destinos_remision', ['codigo' => $codigo])) {
        $flash_local = ['error', 'Ya existe un destino con ese código.'];
    } else {
        $id = db_insert_simple('destinos_remision', [
            'codigo'     => $codigo,
            'nombre'     => $nombre,
            'ambito'     => in_array(input('ambito'), ['disciplinario', 'penal', 'proteccion', 'contractual', 'otro'], true)
                          ? input('ambito') : 'otro',
            'fundamento' => $fundamento,
            'activo'     => 0, // nace inactivo: activarlo es la aprobación G-13
        ]);
        if ($id) {
            audit_log($usuario_actual['id'], 'destino_remision_creado_g13', 'destinos_remision', [
                'motivo' => $codigo,
            ], (int)$id);
        }
        $flash_local = $id ? ['ok', 'Destino creado INACTIVO. Actívelo solo con la aprobación G-13.'] : ['error', 'No se pudo crear.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'alternar') {
    $destino = db_select_one('SELECT id, codigo, activo FROM destinos_remision WHERE id = ? LIMIT 1',
                             [input('destino_id', 0, 'int')]);
    if ($destino) {
        db_update_simple('destinos_remision', ['activo' => (int)$destino['activo'] === 1 ? 0 : 1],
                         ['id' => $destino['id']]);
        audit_log($usuario_actual['id'], 'destino_remision_alternado_g13', 'destinos_remision', [
            'motivo' => $destino['codigo'],
        ], (int)$destino['id']);
        $flash_local = ['ok', 'Destino actualizado (auditado, G-13).'];
    }
}

$destinos = db_select('SELECT * FROM destinos_remision ORDER BY activo DESC, nombre') ?: [];

$title = 'Destinos de remisión';

ob_start();
?>
<div class="max-w-4xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <h2 class="font-semibold text-gray-800 mb-1">Nuevo destino (compuerta G-13)</h2>
        <p class="text-xs text-gray-500 mb-3">Todo destino exige fundamento normativo verificado. Se crea
            inactivo; activarlo materializa la aprobación y queda auditado.</p>
        <form method="POST" class="grid grid-cols-2 lg:grid-cols-5 gap-2 items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="crear">
            <input type="text" name="codigo" placeholder="codigo_snake" required class="border border-gray-300 rounded-lg px-2 py-2 text-sm">
            <input type="text" name="nombre" placeholder="Nombre de la autoridad" required class="border border-gray-300 rounded-lg px-2 py-2 text-sm">
            <select name="ambito" class="border border-gray-300 rounded-lg px-2 py-2 text-sm">
                <option value="disciplinario">Disciplinario</option>
                <option value="penal">Penal</option>
                <option value="proteccion">Protección</option>
                <option value="contractual">Contractual</option>
                <option value="otro">Otro</option>
            </select>
            <input type="text" name="fundamento" placeholder="Fundamento (art., norma)" required class="border border-gray-300 rounded-lg px-2 py-2 text-sm">
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Crear inactivo</button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow p-4 overflow-x-auto">
        <h2 class="font-semibold text-gray-800 mb-3">Catálogo</h2>
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left">
                <th class="p-2">Destino</th><th class="p-2">Ámbito</th><th class="p-2">Fundamento</th>
                <th class="p-2">Estado</th><th class="p-2"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($destinos as $d): ?>
                <tr class="border-b border-gray-100">
                    <td class="p-2"><?= e($d['nombre']) ?> <span class="text-xs text-gray-400 font-mono"><?= e($d['codigo']) ?></span></td>
                    <td class="p-2"><?= e($d['ambito']) ?></td>
                    <td class="p-2 text-xs text-gray-500"><?= e($d['fundamento']) ?></td>
                    <td class="p-2">
                        <span class="px-2 py-0.5 rounded text-xs <?= (int)$d['activo'] === 1 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                            <?= (int)$d['activo'] === 1 ? 'Activo' : 'Inactivo' ?></span>
                    </td>
                    <td class="p-2">
                        <form method="POST" onsubmit="return confirm('Este cambio toca la compuerta G-13. ¿Continuar?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="alternar">
                            <input type="hidden" name="destino_id" value="<?= (int)$d['id'] ?>">
                            <button type="submit" class="text-purple-700 hover:underline text-xs">
                                <?= (int)$d['activo'] === 1 ? 'Desactivar' : 'Activar (G-13)' ?></button>
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
