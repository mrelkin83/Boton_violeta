<?php
/**
 * ADMIN · UNIDADES
 *
 * v3.1 §7.5 · Árbol sede → facultad → proyecto curricular → dependencia.
 * Alta, edición, desactivación e importación CSV con vista previa.
 * La estructura real la define Bienestar/Registro (§12.4 punto 2).
 */

require BASE_PATH . '/modules/admin/_guard.php';

// ── POST: crear unidad ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'crear') {
    $tipo = input('tipo');
    $codigo = strtoupper(trim((string)input('codigo')));
    $nombre = trim((string)input('nombre'));

    if (!in_array($tipo, ['sede', 'facultad', 'proyecto_curricular', 'dependencia', 'espacio'], true)
        || $codigo === '' || $nombre === '') {
        $flash_local = ['error', 'Tipo, código y nombre son obligatorios.'];
    } elseif (db_exists('unidades', ['codigo' => $codigo])) {
        $flash_local = ['error', 'Ya existe una unidad con ese código.'];
    } else {
        $id = db_insert_simple('unidades', [
            'tipo'     => $tipo,
            'padre_id' => input('padre_id', 0, 'int') ?: null,
            'codigo'   => $codigo,
            'nombre'   => $nombre,
            'activa'   => 1,
        ]);
        if ($id) {
            audit_log($usuario_actual['id'], 'unidad_creada', 'unidades', ['motivo' => $codigo], (int)$id);
        }
        $flash_local = $id ? ['ok', 'Unidad creada.'] : ['error', 'No se pudo crear.'];
    }
}

// ── POST: activar / desactivar ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'alternar') {
    $unidad = db_select_one('SELECT id, activa FROM unidades WHERE id = ? LIMIT 1',
                            [input('unidad_id', 0, 'int')]);
    if ($unidad) {
        db_update_simple('unidades', ['activa' => (int)$unidad['activa'] === 1 ? 0 : 1],
                         ['id' => $unidad['id']]);
        audit_log($usuario_actual['id'], 'unidad_alternada', 'unidades', [], (int)$unidad['id']);
        $flash_local = ['ok', 'Unidad actualizada.'];
    }
}

// ── POST: importación CSV con vista previa ──────────────────────────────────
// Formato: tipo;codigo;nombre;codigo_padre
$previsualizacion = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(input('accion'), ['previsualizar_csv', 'importar_csv'], true)) {
    $lineas = preg_split('/\r?\n/', trim((string)input('csv')));
    $filas = [];
    $errores = [];

    foreach ($lineas as $n => $linea) {
        if (trim($linea) === '') { continue; }
        $campos = str_getcsv($linea, ';');

        if (count($campos) < 3) {
            $errores[] = 'Línea ' . ($n + 1) . ': se esperan al menos tipo;codigo;nombre.';
            continue;
        }

        [$tipo, $codigo, $nombre] = array_map('trim', array_slice($campos, 0, 3));
        $codigo_padre = trim((string)($campos[3] ?? ''));

        if (!in_array($tipo, ['sede', 'facultad', 'proyecto_curricular', 'dependencia'], true)) {
            $errores[] = 'Línea ' . ($n + 1) . ": tipo «{$tipo}» no válido.";
            continue;
        }

        $filas[] = ['tipo' => $tipo, 'codigo' => strtoupper($codigo),
                    'nombre' => $nombre, 'codigo_padre' => strtoupper($codigo_padre)];
    }

    if (input('accion') === 'previsualizar_csv') {
        $previsualizacion = ['filas' => $filas, 'errores' => $errores, 'csv' => input('csv')];
    } elseif (empty($errores)) {
        $importadas = 0;
        foreach ($filas as $fila) {
            if (db_exists('unidades', ['codigo' => $fila['codigo']])) { continue; }

            $padre_id = null;
            if ($fila['codigo_padre'] !== '') {
                $padre = db_select_one('SELECT id FROM unidades WHERE codigo = ? LIMIT 1', [$fila['codigo_padre']]);
                $padre_id = $padre['id'] ?? null;
            }

            if (db_insert_simple('unidades', [
                'tipo' => $fila['tipo'], 'padre_id' => $padre_id,
                'codigo' => $fila['codigo'], 'nombre' => $fila['nombre'], 'activa' => 1,
            ])) {
                $importadas++;
            }
        }
        audit_log($usuario_actual['id'], 'unidades_importadas', 'unidades', ['motivo' => $importadas . '_filas']);
        $flash_local = ['ok', "Importación completada: {$importadas} unidades nuevas."];
    } else {
        $flash_local = ['error', 'El CSV tiene errores; corríjalos antes de importar.'];
        $previsualizacion = ['filas' => $filas, 'errores' => $errores, 'csv' => input('csv')];
    }
}

$unidades = db_select(
    'SELECT u.*, p.nombre AS padre_nombre FROM unidades u
      LEFT JOIN unidades p ON p.id = u.padre_id
     ORDER BY u.tipo, u.nombre') ?: [];

$title = 'Unidades';

ob_start();
?>
<div class="max-w-5xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-3">Nueva unidad</h2>
            <form method="POST" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="crear">
                <label class="block text-sm text-gray-600" for="tipo">Tipo</label>
                <select name="tipo" id="tipo" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="sede">Sede</option>
                    <option value="facultad">Facultad</option>
                    <option value="proyecto_curricular">Proyecto curricular</option>
                    <option value="dependencia">Dependencia</option>
                    <option value="espacio">Espacio (virtual / fuera de sede)</option>
                </select>
                <label class="block text-sm text-gray-600" for="codigo">Código</label>
                <input type="text" name="codigo" id="codigo" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <label class="block text-sm text-gray-600" for="nombre">Nombre</label>
                <input type="text" name="nombre" id="nombre" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <label class="block text-sm text-gray-600" for="padre_id">Unidad padre</label>
                <select name="padre_id" id="padre_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">— ninguna —</option>
                    <?php foreach ($unidades as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= e($u['nombre']) ?> (<?= e($u['tipo']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Crear</button>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-1">Importar CSV</h2>
            <p class="text-xs text-gray-500 mb-3">Formato por línea: <code>tipo;codigo;nombre;codigo_padre</code>.
                La vista previa no escribe nada.</p>
            <form method="POST" class="space-y-2">
                <?= csrf_field() ?>
                <textarea name="csv" rows="6" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono"
                          placeholder="facultad;FING;Facultad de Ingeniería;SEDE01"><?= e($previsualizacion['csv'] ?? '') ?></textarea>
                <div class="flex space-x-2">
                    <button type="submit" name="accion" value="previsualizar_csv"
                            class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm">Vista previa</button>
                    <button type="submit" name="accion" value="importar_csv"
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Importar</button>
                </div>
            </form>
            <?php if ($previsualizacion): ?>
                <div class="mt-3 text-xs">
                    <?php foreach ($previsualizacion['errores'] as $err): ?>
                        <p class="text-red-600"><?= e($err) ?></p>
                    <?php endforeach; ?>
                    <p class="text-gray-600 mt-1"><?= count($previsualizacion['filas']) ?> filas válidas para importar.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-4 mt-4 overflow-x-auto">
        <h2 class="font-semibold text-gray-800 mb-3">Unidades registradas (<?= count($unidades) ?>)</h2>
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left">
                <th class="p-2">Tipo</th><th class="p-2">Código</th><th class="p-2">Nombre</th>
                <th class="p-2">Padre</th><th class="p-2">Estado</th><th class="p-2"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($unidades as $u): ?>
                <tr class="border-b border-gray-100">
                    <td class="p-2"><?= e(str_replace('_', ' ', $u['tipo'])) ?></td>
                    <td class="p-2 font-mono text-xs"><?= e($u['codigo']) ?></td>
                    <td class="p-2"><?= e($u['nombre']) ?></td>
                    <td class="p-2 text-gray-500"><?= e($u['padre_nombre'] ?? '—') ?></td>
                    <td class="p-2">
                        <span class="px-2 py-0.5 rounded text-xs <?= (int)$u['activa'] === 1 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                            <?= (int)$u['activa'] === 1 ? 'Activa' : 'Inactiva' ?></span>
                    </td>
                    <td class="p-2">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="alternar">
                            <input type="hidden" name="unidad_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="text-purple-700 hover:underline text-xs">
                                <?= (int)$u['activa'] === 1 ? 'Desactivar' : 'Activar' ?></button>
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
