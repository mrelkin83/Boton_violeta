<?php
/**
 * PATRONES DE RIESGO COLECTIVO
 *
 * v3.1 · Fase F · §7.6 · Ac. 002 art. 33.4: los patrones señalan ESPACIOS y
 * UNIDADES, nunca personas. Solo coordinación.
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

// ── POST: revisar un patrón ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'revisar') {
    $estado = input('nuevo_estado');

    if (in_array($estado, ['en_revision', 'medidas_activadas', 'descartado'], true)) {
        $ok = db_update_simple('patrones_detectados', [
            'estado'        => $estado,
            'nota_revision' => trim((string)input('nota_revision')) ?: null,
            'revisado_por'  => $usuario_actual['id'],
        ], ['id' => input('patron_id', 0, 'int')]);

        if ($ok !== false) {
            audit_log($usuario_actual['id'], 'patron_revisado', 'patrones_detectados', [
                'motivo' => $estado,
            ], input('patron_id', 0, 'int'));
        }
        $flash_local = $ok !== false ? ['ok', 'Patrón actualizado.'] : ['error', 'No se pudo actualizar.'];
    }
}

// ── POST: reanalizar ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'analizar') {
    $nuevos = analizar_patrones();
    $flash_local = ['ok', "Análisis ejecutado: {$nuevos} patrones nuevos."];
}

$patrones = db_select(
    'SELECT p.*, u.nombre AS unidad_nombre FROM patrones_detectados p
      LEFT JOIN unidades u ON u.id = p.unidad_id
     ORDER BY p.created_at DESC LIMIT 100') ?: [];

$title = 'Patrones de riesgo';

ob_start();
?>
<div class="max-w-4xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="font-semibold text-gray-800">Patrones de riesgo colectivo (art. 33.4)</h2>
                <p class="text-xs text-gray-500">Un patrón señala un espacio o una unidad que concentra
                    señales; nunca acusa a una persona.</p>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="analizar">
                <button type="submit" class="px-3 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">
                    Ejecutar análisis</button>
            </form>
        </div>

        <ul class="space-y-3 text-sm">
            <?php if (empty($patrones)): ?>
                <li class="text-gray-400">Sin patrones detectados.</li>
            <?php endif; ?>
            <?php foreach ($patrones as $p): ?>
                <li class="border border-gray-200 rounded-lg p-3">
                    <div class="flex justify-between">
                        <div>
                            <p class="font-medium"><?= e(str_replace('_', ' ', $p['tipo_patron'])) ?>:
                                <?= e($p['unidad_nombre'] ?? $p['clave']) ?></p>
                            <p class="text-xs text-gray-500"><?= (int)$p['ocurrencias'] ?> señales
                                · ventana <?= e(format_date($p['ventana_desde'])) ?> — <?= e(format_date($p['ventana_hasta'])) ?></p>
                            <?php if ($p['nota_revision']): ?>
                                <p class="text-xs text-gray-600 mt-1">Nota: <?= e($p['nota_revision']) ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="px-2 py-0.5 rounded text-xs h-fit
                            <?= $p['estado'] === 'medidas_activadas' ? 'bg-green-100 text-green-800'
                              : ($p['estado'] === 'descartado' ? 'bg-gray-100 text-gray-800' : 'bg-yellow-100 text-yellow-800') ?>">
                            <?= e(str_replace('_', ' ', $p['estado'])) ?></span>
                    </div>
                    <?php if (in_array($p['estado'], ['detectado', 'en_revision'], true)): ?>
                        <form method="POST" class="mt-2 flex flex-wrap gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="revisar">
                            <input type="hidden" name="patron_id" value="<?= (int)$p['id'] ?>">
                            <select name="nuevo_estado" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                                <option value="en_revision">En revisión</option>
                                <option value="medidas_activadas">Medidas preventivas activadas (art. 33.1)</option>
                                <option value="descartado">Descartado</option>
                            </select>
                            <input type="text" name="nota_revision" placeholder="Nota de revisión"
                                   class="flex-1 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Aplicar</button>
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
