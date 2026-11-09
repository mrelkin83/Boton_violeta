<?php
/**
 * ADMIN · INSTRUMENTO DE RIESGO
 *
 * v3.1 §7.5 · Versionado del instrumento del art. 23. Una versión vigente
 * NUNCA se edita: se clona. Publicar una versión toca la compuerta G-09
 * (pesos y umbrales los define Bienestar con el Comité de Equidad, §13.1).
 */

require BASE_PATH . '/modules/admin/_guard.php';

// ── POST: crear versión (vacía o clonada) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'crear_version') {
    $version = trim((string)input('version'));

    if ($version === '' || db_exists('instrumentos_riesgo', ['version' => $version])) {
        $flash_local = ['error', 'La versión es obligatoria y no puede repetirse.'];
    } else {
        $id = db_insert_simple('instrumentos_riesgo', [
            'version'      => $version,
            'vigente'      => 0,
            'umbral_alto'  => (float)input('umbral_alto', 0.66),
            'umbral_medio' => (float)input('umbral_medio', 0.33),
            'aprobado_por' => trim((string)input('aprobado_por')) ?: $usuario_actual['nombre'],
            'aprobado_at'  => date('Y-m-d H:i:s'),
        ]);

        if ($id && input('clonar_de', 0, 'int')) {
            $items = db_select('SELECT * FROM instrumento_items WHERE instrumento_id = ? ORDER BY orden',
                               [input('clonar_de', 0, 'int')]) ?: [];
            foreach ($items as $item) {
                db_insert_simple('instrumento_items', [
                    'instrumento_id' => (int)$id,
                    'codigo'    => $item['codigo'],
                    'enunciado' => $item['enunciado'],
                    'peso'      => $item['peso'],
                    'orden'     => $item['orden'],
                ]);
            }
        }

        if ($id) {
            audit_log($usuario_actual['id'], 'instrumento_version_creada', 'instrumentos_riesgo', [
                'motivo' => $version,
            ], (int)$id);
        }
        $flash_local = $id ? ['ok', 'Versión creada (borrador, no vigente).'] : ['error', 'No se pudo crear.'];
    }
}

// ── POST: añadir ítem a un borrador ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'agregar_item') {
    $instrumento = db_select_one('SELECT id, vigente FROM instrumentos_riesgo WHERE id = ? LIMIT 1',
                                 [input('instrumento_id', 0, 'int')]);

    if (!$instrumento) {
        $flash_local = ['error', 'Versión no encontrada.'];
    } elseif ((int)$instrumento['vigente'] === 1) {
        $flash_local = ['error', 'Una versión vigente no se edita: clónela y modifique el borrador.'];
    } else {
        $id = db_insert_simple('instrumento_items', [
            'instrumento_id' => (int)$instrumento['id'],
            'codigo'    => strtoupper(trim((string)input('codigo_item'))),
            'enunciado' => trim((string)input('enunciado')),
            'peso'      => (float)input('peso', 1),
            'orden'     => input('orden', 0, 'int'),
        ]);
        $flash_local = $id ? ['ok', 'Ítem añadido al borrador.'] : ['error', 'No se pudo añadir.'];
    }
}

// ── POST: publicar versión (G-09) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'publicar') {
    $instrumento_id = input('instrumento_id', 0, 'int');
    $items = db_count('instrumento_items', ['instrumento_id' => $instrumento_id]);

    if ($items < 1) {
        $flash_local = ['error', 'Una versión sin ítems no puede publicarse.'];
    } else {
        db_execute('UPDATE instrumentos_riesgo SET vigente = 0 WHERE vigente = 1', []);
        db_update_simple('instrumentos_riesgo', ['vigente' => 1], ['id' => $instrumento_id]);

        audit_log($usuario_actual['id'], 'instrumento_publicado_g09', 'instrumentos_riesgo', [
            'motivo' => 'publicacion_version',
        ], $instrumento_id);

        $flash_local = ['ok', 'Versión publicada (compuerta G-09: quedó auditado quién y cuándo).'];
    }
}

$versiones = db_select(
    'SELECT i.*, (SELECT COUNT(*) FROM instrumento_items it WHERE it.instrumento_id = i.id) AS items
       FROM instrumentos_riesgo i ORDER BY i.id DESC') ?: [];

$title = 'Instrumento de riesgo';

ob_start();
?>
<div class="max-w-4xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="mb-4 p-3 rounded-lg bg-yellow-50 border border-yellow-300 text-yellow-800 text-xs" role="note">
        Los factores, pesos y umbrales del instrumento son decisión de Bienestar y el Comité de Equidad
        (§13.1 punto 1). Publicar una versión toca la compuerta <strong>G-09</strong> y queda auditado.
        El instrumento no reemplaza el concepto profesional (art. 23).
    </div>

    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <h2 class="font-semibold text-gray-800 mb-3">Nueva versión</h2>
        <form method="POST" class="grid grid-cols-2 lg:grid-cols-5 gap-2 items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="crear_version">
            <div><label class="block text-sm text-gray-600" for="version">Versión</label>
                <input type="text" name="version" id="version" required placeholder="p. ej. 1.1"
                       class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm"></div>
            <div><label class="block text-sm text-gray-600" for="umbral_alto">Umbral alto</label>
                <input type="number" step="0.01" name="umbral_alto" id="umbral_alto" value="0.66"
                       class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm"></div>
            <div><label class="block text-sm text-gray-600" for="umbral_medio">Umbral medio</label>
                <input type="number" step="0.01" name="umbral_medio" id="umbral_medio" value="0.33"
                       class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm"></div>
            <div><label class="block text-sm text-gray-600" for="clonar_de">Clonar ítems de</label>
                <select name="clonar_de" id="clonar_de" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                    <option value="">— vacía —</option>
                    <?php foreach ($versiones as $v): ?>
                        <option value="<?= (int)$v['id'] ?>">v<?= e($v['version']) ?> (<?= (int)$v['items'] ?> ítems)</option>
                    <?php endforeach; ?>
                </select></div>
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Crear borrador</button>
        </form>
    </div>

    <?php foreach ($versiones as $v): ?>
        <div class="bg-white rounded-lg shadow p-4 mb-4">
            <div class="flex justify-between items-center mb-2">
                <h3 class="font-semibold text-gray-800">Versión <?= e($v['version']) ?>
                    <span class="text-xs text-gray-500">· <?= (int)$v['items'] ?> ítems
                        · umbrales <?= e($v['umbral_medio']) ?> / <?= e($v['umbral_alto']) ?></span></h3>
                <?php if ((int)$v['vigente'] === 1): ?>
                    <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-800">VIGENTE</span>
                <?php else: ?>
                    <form method="POST" onsubmit="return confirm('Publicar esta versión (compuerta G-09)?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="publicar">
                        <input type="hidden" name="instrumento_id" value="<?= (int)$v['id'] ?>">
                        <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-xs">
                            Publicar (G-09)</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php $items = db_select('SELECT * FROM instrumento_items WHERE instrumento_id = ? ORDER BY orden', [(int)$v['id']]) ?: []; ?>
            <ul class="text-sm space-y-1 mb-3">
                <?php foreach ($items as $item): ?>
                    <li class="flex justify-between border-b border-gray-100 pb-1">
                        <span><span class="font-mono text-xs text-gray-400"><?= e($item['codigo']) ?></span>
                            <?= e($item['enunciado']) ?></span>
                        <span class="text-xs text-gray-500">peso <?= e($item['peso']) ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($items)): ?><li class="text-gray-400">Sin ítems.</li><?php endif; ?>
            </ul>

            <?php if ((int)$v['vigente'] !== 1): ?>
                <form method="POST" class="flex flex-wrap gap-2 items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="agregar_item">
                    <input type="hidden" name="instrumento_id" value="<?= (int)$v['id'] ?>">
                    <input type="text" name="codigo_item" placeholder="Código" required class="w-24 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                    <input type="text" name="enunciado" placeholder="Enunciado del ítem" required class="flex-1 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                    <input type="number" step="0.001" name="peso" value="1" class="w-20 border border-gray-300 rounded-lg px-2 py-1 text-sm" title="Peso">
                    <input type="number" name="orden" value="<?= count($items) + 1 ?>" class="w-16 border border-gray-300 rounded-lg px-2 py-1 text-sm" title="Orden">
                    <button type="submit" class="px-3 py-1 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm">Añadir ítem</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
