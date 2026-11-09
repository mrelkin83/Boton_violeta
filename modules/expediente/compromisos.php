<?php
/**
 * PESTAÑA · COMPROMISOS
 *
 * v3.1 §7.1 · «Aquí salen los compromisos»: lista completa del caso con
 * semáforo por plazo, cambio de estado con motivación y constancia.
 * RN-16: la creación de compromisos restaurativos exige sanción en firme,
 * consentimiento y concepto favorable — el dominio la rechaza si falta algo.
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit('Acceso denegado'); }

$es_coordinacion = in_array($usuario_actual['rol'], ['coordinacion_edheg', 'coordinador'], true);

$unidades_activas = db_select(
    "SELECT id, nombre FROM unidades WHERE activa = 1 AND tipo <> 'espacio' ORDER BY nombre") ?: [];
$usuarios_activos = db_select(
    'SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre') ?: [];

// ── POST: crear compromiso ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'crear_compromiso' && $es_coordinacion) {
    $r = crear_compromiso([
        'denuncia_id'            => $denuncia_id,
        'origen'                 => input('origen', 'seguimiento'),
        'descripcion'            => input('descripcion'),
        'fecha_limite'           => input('fecha_limite'),
        'responsable_tipo'       => input('responsable_tipo'),
        'responsable_usuario_id' => input('responsable_usuario_id', 0, 'int') ?: null,
        'responsable_unidad_id'  => input('responsable_unidad_id', 0, 'int') ?: null,
        'responsable_externo'    => input('responsable_externo'),
        'sancion_id'             => input('sancion_id', 0, 'int') ?: null,
        'consentimiento_id'      => input('consentimiento_id', 0, 'int') ?: null,
        'concepto_favorable_por' => input('origen') === 'restaurativo' ? $usuario_actual['id'] : null,
    ], $usuario_actual['id']);

    $flash_local = $r['exito'] ? ['ok', 'Compromiso creado.'] : ['error', $r['error']];
}

// ── POST: transición ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'transicion_compromiso') {
    $r = cambiar_estado_compromiso(
        input('compromiso_id', 0, 'int'), input('nuevo_estado'), $usuario_actual['id'], [
            'constancia_texto'   => input('constancia_texto'),
            'motivacion'         => input('motivacion'),
            'nueva_fecha_limite' => input('nueva_fecha_limite') ?: null,
        ]
    );
    $flash_local = $r['exito'] ? ['ok', 'Compromiso actualizado.'] : ['error', $r['error']];
}

$compromisos = compromisos_del_caso($denuncia_id);
?>

<?php if ($flash_local): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
        <?= e($flash_local[1]) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    <?php if ($es_coordinacion): ?>
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Nuevo compromiso</h3>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="crear_compromiso">

            <label class="block text-sm text-gray-600" for="origen">Origen</label>
            <select name="origen" id="origen" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="seguimiento">Seguimiento</option>
                <option value="medida">Medida</option>
                <option value="restaurativo">Restaurativo (RN-16: exige sanción en firme)</option>
            </select>

            <label class="block text-sm text-gray-600" for="descripcion">Descripción</label>
            <textarea name="descripcion" id="descripcion" rows="2" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>

            <label class="block text-sm text-gray-600" for="fecha_limite">Fecha límite</label>
            <input type="date" name="fecha_limite" id="fecha_limite" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">

            <label class="block text-sm text-gray-600" for="responsable_tipo">Responsable</label>
            <select name="responsable_tipo" id="responsable_tipo" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="usuario">Usuario del sistema</option>
                <option value="unidad">Unidad</option>
                <option value="externo">Externo</option>
            </select>

            <select name="responsable_usuario_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">— usuario —</option>
                <?php foreach ($usuarios_activos as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="responsable_unidad_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">— unidad —</option>
                <?php foreach ($unidades_activas as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="responsable_externo" placeholder="Responsable externo (si aplica)"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">

            <label class="block text-sm text-gray-600" for="sancion_id">Sanción en firme (solo restaurativo)</label>
            <input type="number" name="sancion_id" id="sancion_id" min="0" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <label class="block text-sm text-gray-600" for="consentimiento_id">Consentimiento (solo restaurativo)</label>
            <input type="number" name="consentimiento_id" id="consentimiento_id" min="0" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">

            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Crear compromiso</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="<?= $es_coordinacion ? 'lg:col-span-2' : 'lg:col-span-3' ?> bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Compromisos del caso</h3>
        <ul class="space-y-3 text-sm">
            <?php if (empty($compromisos)): ?>
                <li class="text-gray-400">Sin compromisos registrados.</li>
            <?php endif; ?>
            <?php foreach ($compromisos as $c): ?>
                <?php $sem = in_array($c['estado'], ['cumplido', 'cancelado'], true) ? null : semaforo_compromiso($c['fecha_limite']); ?>
                <li class="border border-gray-200 rounded-lg p-3">
                    <div class="flex justify-between items-start gap-2">
                        <div>
                            <p class="font-medium"><?= e($c['descripcion']) ?></p>
                            <p class="text-xs text-gray-500">
                                Origen: <?= e($c['origen']) ?>
                                · Responsable: <?= e($c['usuario_nombre'] ?? $c['unidad_nombre'] ?? $c['responsable_externo'] ?? '—') ?>
                                · Vence: <?= e(format_date($c['fecha_limite'])) ?>
                                <?php if ($c['respondido_at']): ?>· Respondido: <?= e(format_date($c['respondido_at'])) ?><?php endif; ?>
                            </p>
                            <?php if (!empty($c['constancia_texto'])): ?>
                                <p class="text-xs text-gray-600 mt-1">Constancia: <?= e($c['constancia_texto']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center space-x-2">
                            <?php if ($sem): ?>
                                <span class="w-3 h-3 rounded-full inline-block <?= $sem === 'verde' ? 'bg-green-500' : ($sem === 'ambar' ? 'bg-yellow-400' : 'bg-red-500') ?>"
                                      title="Plazo: <?= e($sem) ?>" aria-label="Plazo: <?= e($sem) ?>"></span>
                            <?php endif; ?>
                            <span class="px-2 py-0.5 rounded text-xs font-semibold
                                <?= $c['estado'] === 'cumplido' ? 'bg-green-100 text-green-800'
                                  : ($c['estado'] === 'incumplido' ? 'bg-red-100 text-red-800'
                                  : ($c['estado'] === 'cancelado' ? 'bg-gray-100 text-gray-800' : 'bg-yellow-100 text-yellow-800')) ?>">
                                <?= e($c['estado']) ?></span>
                        </div>
                    </div>
                    <?php $siguientes = TRANSICIONES_COMPROMISO[$c['estado']] ?? []; ?>
                    <?php if (!empty($siguientes)): ?>
                        <form method="POST" class="mt-2 flex flex-wrap items-end gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="transicion_compromiso">
                            <input type="hidden" name="compromiso_id" value="<?= (int)$c['id'] ?>">
                            <select name="nuevo_estado" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                                <?php foreach ($siguientes as $s): ?>
                                    <option value="<?= e($s) ?>"><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="constancia_texto" placeholder="Constancia (cumplido)"
                                   class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <input type="text" name="motivacion" placeholder="Motivación"
                                   class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <input type="date" name="nueva_fecha_limite" title="Nueva fecha (prórroga)"
                                   class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Aplicar</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
