<?php
/**
 * PESTAÑA · CONSENTIMIENTOS
 *
 * v3.1 §7.1 · Registro, revocación y desistimiento con decisión de
 * continuación de oficio (Ac. 003 art. 24). Nunca se obliga a la víctima a
 * participar.
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit('Acceso denegado'); }

// ── POST: registrar consentimiento ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'registrar') {
    $tipo = input('tipo');
    $alcance = trim((string)input('alcance_texto'));

    if ($alcance === '') {
        $flash_local = ['error', 'El alcance del consentimiento es obligatorio (RN-06).'];
    } else {
        $id = registrar_consentimiento($denuncia_id, $tipo, $alcance,
                                       input('otorgado') === '1',
                                       input('medio', 'presencial'), $usuario_actual['id']);
        $flash_local = $id ? ['ok', 'Consentimiento registrado.'] : ['error', 'No se pudo registrar.'];
    }
}

// ── POST: revocar ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'revocar') {
    $motivo = trim((string)input('motivo_revocacion'));

    if ($motivo === '') {
        $flash_local = ['error', 'La revocación exige el motivo.'];
    } else {
        $ok = revocar_consentimiento(input('consentimiento_id', 0, 'int'), $motivo, $usuario_actual['id']);
        $flash_local = $ok ? ['ok', 'Consentimiento revocado.'] : ['error', 'No se pudo revocar.'];
    }
}

// ── POST: decidir continuación de oficio tras desistimiento (art. 24) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'continuacion_oficio'
    && in_array($usuario_actual['rol'], ['coordinacion_edheg', 'coordinador'], true)) {

    $motivacion = trim((string)input('motivacion_continuacion'));
    $continua = input('continua') === '1';

    if ($continua && $motivacion === '') {
        $flash_local = ['error', 'Continuar de oficio exige motivación (Ac. 003 art. 24).'];
    } else {
        $ok = db_update_simple('consentimientos', [
            'continuacion_oficio'     => $continua ? 1 : 0,
            'motivacion_continuacion' => $motivacion ?: null,
            'decidida_por'            => $usuario_actual['id'],
        ], ['id' => input('consentimiento_id', 0, 'int')]);

        if ($ok !== false) {
            audit_log($usuario_actual['id'], 'desistimiento_decidido', 'consentimientos', [
                'motivo' => $continua ? 'continua_de_oficio' : 'no_continua',
            ], $denuncia_id);
        }
        $flash_local = $ok !== false ? ['ok', 'Decisión registrada.'] : ['error', 'No se pudo registrar.'];
    }
}

$consentimientos = db_select(
    'SELECT * FROM consentimientos WHERE denuncia_id = ? ORDER BY created_at DESC', [$denuncia_id]) ?: [];
?>

<?php if ($flash_local): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
        <?= e($flash_local[1]) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Registrar consentimiento</h3>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="registrar">
            <label class="block text-sm text-gray-600" for="tipo">Tipo</label>
            <select name="tipo" id="tipo" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="tratamiento_datos">Tratamiento de datos</option>
                <option value="medida_especifica">Medida específica</option>
                <option value="remision_externa">Remisión externa</option>
                <option value="grabacion">Grabación</option>
                <option value="contacto_familiar">Contacto familiar (RN-20)</option>
                <option value="desistimiento">Desistimiento (art. 24)</option>
            </select>
            <label class="block text-sm text-gray-600" for="alcance_texto">Alcance explicado a la persona</label>
            <textarea name="alcance_texto" id="alcance_texto" rows="3" required
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            <div class="flex space-x-4 text-sm">
                <label class="flex items-center space-x-2"><input type="radio" name="otorgado" value="1" checked> <span>Otorgado</span></label>
                <label class="flex items-center space-x-2"><input type="radio" name="otorgado" value="0"> <span>Negado</span></label>
            </div>
            <label class="block text-sm text-gray-600" for="medio">Medio</label>
            <select name="medio" id="medio" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="presencial">Presencial</option>
                <option value="virtual">Virtual</option>
                <option value="telefonico">Telefónico</option>
                <option value="escrito">Escrito</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Registrar</button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Consentimientos del caso</h3>
        <ul class="text-sm space-y-3">
            <?php if (empty($consentimientos)): ?>
                <li class="text-gray-400">Sin consentimientos registrados.</li>
            <?php endif; ?>
            <?php foreach ($consentimientos as $c): ?>
                <li class="border border-gray-200 rounded-lg p-3">
                    <div class="flex justify-between">
                        <p class="font-medium"><?= e(str_replace('_', ' ', $c['tipo'])) ?></p>
                        <span class="px-2 py-0.5 rounded text-xs
                            <?= $c['revocado_at'] ? 'bg-gray-100 text-gray-800'
                              : ((int)$c['otorgado'] === 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800') ?>">
                            <?= $c['revocado_at'] ? 'Revocado' : ((int)$c['otorgado'] === 1 ? 'Otorgado' : 'Negado') ?></span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1"><?= e(format_datetime($c['created_at'])) ?> · <?= e($c['medio']) ?></p>
                    <p class="text-xs text-gray-600 mt-1"><?= e(mb_substr($c['alcance_texto'], 0, 160)) ?></p>

                    <?php if (!$c['revocado_at'] && (int)$c['otorgado'] === 1 && $c['tipo'] !== 'desistimiento'): ?>
                        <form method="POST" class="mt-2 flex gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="revocar">
                            <input type="hidden" name="consentimiento_id" value="<?= (int)$c['id'] ?>">
                            <input type="text" name="motivo_revocacion" placeholder="Motivo de revocación" required
                                   class="flex-1 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <button type="submit" class="px-3 py-1 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm">Revocar</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($c['tipo'] === 'desistimiento' && $c['continuacion_oficio'] === null
                              && in_array($usuario_actual['rol'], ['coordinacion_edheg', 'coordinador'], true)): ?>
                        <form method="POST" class="mt-2 space-y-2 border-t border-gray-100 pt-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="continuacion_oficio">
                            <input type="hidden" name="consentimiento_id" value="<?= (int)$c['id'] ?>">
                            <p class="text-xs text-gray-600">Ante el desistimiento, ¿la autoridad continúa de oficio? (art. 24)</p>
                            <div class="flex space-x-4 text-sm">
                                <label class="flex items-center space-x-2"><input type="radio" name="continua" value="1"> <span>Sí, de oficio</span></label>
                                <label class="flex items-center space-x-2"><input type="radio" name="continua" value="0" checked> <span>No continúa</span></label>
                            </div>
                            <input type="text" name="motivacion_continuacion" placeholder="Motivación (obligatoria si continúa)"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Registrar decisión</button>
                        </form>
                    <?php elseif ($c['tipo'] === 'desistimiento' && $c['continuacion_oficio'] !== null): ?>
                        <p class="text-xs mt-2 <?= (int)$c['continuacion_oficio'] === 1 ? 'text-purple-700' : 'text-gray-500' ?>">
                            <?= (int)$c['continuacion_oficio'] === 1 ? 'La autoridad continúa de oficio.' : 'No se continúa de oficio.' ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
