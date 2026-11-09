<?php
/**
 * PESTAÑA · RIESGO
 *
 * v3.1 §7.1 · Instrumento del art. 23: ítems desde BD, banda sugerida vs
 * final con motivación obligatoria, histórico. El instrumento no reemplaza el
 * concepto profesional.
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit('Acceso denegado'); }

$instrumento = instrumento_riesgo_vigente();
$items = $instrumento ? items_instrumento($instrumento['id']) : [];

// ── POST: registrar valoración ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'valorar' && $instrumento) {
    $respuestas = [];
    foreach ($items as $item) {
        $valor = input('item_' . $item['id']);
        if ($valor !== null && $valor !== '') {
            $respuestas[$item['codigo']] = (int)$valor;
        }
    }

    $banda_final = input('banda_final') ?: null;

    $r = registrar_valoracion_riesgo(
        $denuncia_id, $respuestas, $usuario_actual['id'],
        $banda_final ?: null, input('motivacion_ajuste'),
        input('es_revision') === '1'
    );

    $flash_local = $r['exito'] ? ['ok', 'Valoración registrada.'] : ['error', $r['motivo']];
}

$historico = historico_riesgo($denuncia_id);
?>

<?php if ($flash_local): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
        <?= e($flash_local[1]) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Aplicar instrumento (Ac. 002 art. 23)</h3>
        <?php if (!$instrumento): ?>
            <p class="text-sm text-gray-500">No hay instrumento vigente. Los factores, pesos y umbrales los
                define Bienestar con el Comité de Equidad (§13.1); el administrador los carga en
                la consola (compuerta G-09).</p>
        <?php else: ?>
            <p class="text-xs text-gray-500 mb-3">Versión <?= e($instrumento['version']) ?>.
                Un ítem sin responder se omite y el puntaje se renormaliza, no se penaliza.</p>
            <form method="POST" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="valorar">
                <?php foreach ($items as $item): ?>
                    <div>
                        <label class="block text-sm text-gray-700" for="item_<?= (int)$item['id'] ?>"><?= e($item['enunciado']) ?></label>
                        <select name="item_<?= (int)$item['id'] ?>" id="item_<?= (int)$item['id'] ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option value="">Sin responder</option>
                            <option value="1">Sí</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                <?php endforeach; ?>
                <div>
                    <label class="block text-sm text-gray-600" for="banda_final">Banda final (vacío = aceptar la sugerida)</label>
                    <select name="banda_final" id="banda_final" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">Aceptar banda sugerida</option>
                        <option value="alto">Alto</option>
                        <option value="medio">Medio</option>
                        <option value="moderado">Moderado</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-600" for="motivacion_ajuste">Motivación del ajuste (obligatoria si difiere)</label>
                    <textarea name="motivacion_ajuste" id="motivacion_ajuste" rows="2"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
                </div>
                <label class="flex items-center space-x-2 text-sm text-gray-600">
                    <input type="checkbox" name="es_revision" value="1"> <span>Es una revisión (art. 30)</span>
                </label>
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Registrar valoración</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Histórico de valoraciones</h3>
        <ul class="text-sm space-y-2">
            <?php if (empty($historico)): ?>
                <li class="text-gray-400">Sin valoraciones registradas.</li>
            <?php endif; ?>
            <?php foreach ($historico as $v): ?>
                <li class="border-b border-gray-100 pb-2">
                    <div class="flex justify-between items-center">
                        <span class="px-2 py-0.5 rounded text-xs font-semibold
                            <?= $v['banda_final'] === 'alto' ? 'bg-red-100 text-red-800'
                              : ($v['banda_final'] === 'medio' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800') ?>">
                            <?= e(strtoupper($v['banda_final'])) ?></span>
                        <span class="text-xs text-gray-500"><?= e(format_datetime($v['created_at'])) ?></span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Sugerida: <?= e($v['banda_sugerida']) ?> · Puntaje: <?= e($v['puntaje']) ?>
                        <?= (int)$v['es_revision'] === 1 ? '· Revisión' : '' ?>
                        <?= $v['banda_final'] !== $v['banda_sugerida'] ? '· Ajustada con motivación' : '' ?>
                    </p>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
