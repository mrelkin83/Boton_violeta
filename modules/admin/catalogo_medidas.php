<?php
/**
 * ADMIN · CATÁLOGO DE MEDIDAS
 *
 * v3.1 §7.5 · Activar/desactivar y editar plazo y destinatarios. NO se crean
 * medidas nuevas desde aquí: añadir una medida sin fundamento normativo toca
 * la compuerta G-02 y exige aprobación de Elkin.
 */

require BASE_PATH . '/modules/admin/_guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'editar') {
    $medida_id = input('medida_id', 0, 'int');
    $dias = input('dias_habiles_ejecucion', 0, 'int');
    $aplica = array_intersect(
        (array)($_POST['aplica_a'] ?? []),
        ['estudiante', 'docente', 'administrativo', 'contratista', 'externo']
    );

    if ($medida_id <= 0 || $dias < 1 || empty($aplica)) {
        $flash_local = ['error', 'Días hábiles (≥1) y al menos un destinatario son obligatorios.'];
    } else {
        $ok = db_update_simple('catalogo_medidas', [
            'dias_habiles_ejecucion' => $dias,
            'aplica_a'               => implode(',', $aplica),
            'activo'                 => input('activo') === '1' ? 1 : 0,
        ], ['id' => $medida_id]);

        if ($ok !== false) {
            audit_log($usuario_actual['id'], 'catalogo_medida_editada', 'catalogo_medidas', [
                'plazo' => $dias,
            ], $medida_id);
        }
        $flash_local = $ok !== false ? ['ok', 'Medida actualizada.'] : ['error', 'No se pudo actualizar.'];
    }
}

$medidas = db_select('SELECT * FROM catalogo_medidas ORDER BY categoria, codigo') ?: [];
$destinatarios = ['estudiante', 'docente', 'administrativo', 'contratista', 'externo'];

$title = 'Catálogo de medidas';

ob_start();
?>
<div class="max-w-5xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-800 mb-1">Catálogo de medidas (arts. 24, 26–29, 34)</h2>
        <p class="text-xs text-gray-500 mb-4">Cada medida cita su fundamento. Crear una medida nueva exige
            aprobación previa (compuerta G-02): este panel solo edita plazo, destinatarios y vigencia.</p>

        <div class="space-y-3">
            <?php foreach ($medidas as $m): ?>
                <form method="POST" class="border border-gray-200 rounded-lg p-3 text-sm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="editar">
                    <input type="hidden" name="medida_id" value="<?= (int)$m['id'] ?>">
                    <div class="flex flex-wrap justify-between items-start gap-2">
                        <div class="max-w-xl">
                            <p class="font-medium"><?= e($m['nombre']) ?>
                                <span class="text-xs text-gray-400 font-mono">(<?= e($m['codigo']) ?>)</span></p>
                            <p class="text-xs text-gray-500"><?= e($m['fundamento']) ?> · categoría: <?= e($m['categoria']) ?>
                                <?= (int)$m['requiere_consentimiento'] === 1 ? '· exige consentimiento (RN-06)' : '' ?></p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="text-xs text-gray-600">Días hábiles
                                <input type="number" name="dias_habiles_ejecucion" min="1" value="<?= (int)$m['dias_habiles_ejecucion'] ?>"
                                       class="w-16 border border-gray-300 rounded-lg px-1 py-1 text-sm ml-1"></label>
                            <label class="flex items-center space-x-1 text-xs text-gray-600">
                                <input type="checkbox" name="activo" value="1" <?= (int)$m['activo'] === 1 ? 'checked' : '' ?>>
                                <span>Activa</span></label>
                            <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-xs">Guardar</button>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-3 mt-2">
                        <?php $aplica_actual = explode(',', (string)$m['aplica_a']); ?>
                        <?php foreach ($destinatarios as $d): ?>
                            <label class="flex items-center space-x-1 text-xs text-gray-600">
                                <input type="checkbox" name="aplica_a[]" value="<?= e($d) ?>"
                                       <?= in_array($d, $aplica_actual, true) ? 'checked' : '' ?>>
                                <span><?= e($d) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
