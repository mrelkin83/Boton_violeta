<?php
/**
 * PESTAÑA · MEDIDAS
 *
 * v3.1 §7.1 · Núcleo del sistema (ADR-03): catálogo aplicable por vinculación
 * → solicitar → consentimiento (RN-06) → notificar → constancia /
 * incumplimiento → revisión trimestral → levantamiento motivado.
 * Cada medida crea automáticamente su compromiso (en solicitar_medida()).
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit('Acceso denegado'); }

// Vinculación de la persona denunciada: determina el catálogo aplicable
$persona_denunciada = db_select_one(
    "SELECT tipo_vinculacion, unidad_id FROM personas_caso
      WHERE denuncia_id = ? AND papel = 'denunciada' LIMIT 1", [$denuncia_id]);

$vinculacion = $persona_denunciada['tipo_vinculacion'] ?? 'desconocido';
$catalogo = catalogo_aplicable($vinculacion);

$unidades_activas = db_select(
    "SELECT id, nombre FROM unidades WHERE activa = 1 AND tipo <> 'espacio' ORDER BY nombre") ?: [];

// ── POST: solicitar medida ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'solicitar') {
    $catalogo_id = input('catalogo_id', 0, 'int');
    $solicitada_por = input('solicitada_por', 'victima');
    $item = db_select_one('SELECT * FROM catalogo_medidas WHERE id = ? LIMIT 1', [$catalogo_id]);

    if (!$item) {
        $flash_local = ['error', 'Seleccione una medida del catálogo.'];
    } else {
        $consentimiento_id = null;

        // RN-06 · si la medida lo exige y no viene consentimiento, se registra
        // en el mismo paso (§7.1)
        if ((int)$item['requiere_consentimiento'] === 1 && $solicitada_por !== 'oficio') {
            $alcance = trim((string)input('alcance_consentimiento'));

            if ($alcance === '') {
                $flash_local = ['error', 'RN-06: esta medida exige registrar el consentimiento con su alcance.'];
            } else {
                $consentimiento_id = registrar_consentimiento(
                    $denuncia_id, 'medida_especifica', $alcance, true,
                    input('medio_consentimiento', 'presencial'), $usuario_actual['id']
                );
            }
        }

        if (!isset($flash_local)) {
            $r = solicitar_medida([
                'denuncia_id'         => $denuncia_id,
                'catalogo_id'         => $catalogo_id,
                'solicitada_por'      => $solicitada_por,
                'motivacion_oficio'   => input('motivacion_oficio'),
                'consentimiento_id'   => $consentimiento_id,
                'unidad_ejecutora_id' => input('unidad_ejecutora_id', 0, 'int') ?: null,
                'detalle_ejecucion'   => input('detalle_ejecucion'),
            ], $usuario_actual['id']);

            if ($r['exito'] && $consentimiento_id) {
                db_update_simple('consentimientos', ['referencia_id' => $r['medida_id']],
                                 ['id' => $consentimiento_id]);
            }

            $flash_local = $r['exito']
                ? ['ok', 'Medida solicitada; el compromiso para la unidad ejecutora quedó creado.']
                : ['error', $r['motivo']];
        }
    }
}

// ── POST: transición de estado ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'transicion_medida') {
    $r = cambiar_estado_medida(
        input('medida_id', 0, 'int'), input('nuevo_estado'),
        $usuario_actual['id'], input('motivacion')
    );
    $flash_local = $r['exito'] ? ['ok', 'Estado de la medida actualizado.'] : ['error', $r['motivo']];
}

$medidas = db_select(
    'SELECT m.*, cm.nombre AS medida_nombre, cm.fundamento, cm.categoria, u.nombre AS unidad_nombre
       FROM medidas m
       JOIN catalogo_medidas cm ON cm.id = m.catalogo_id
       LEFT JOIN unidades u ON u.id = m.unidad_ejecutora_id
      WHERE m.denuncia_id = ? ORDER BY m.created_at DESC', [$denuncia_id]) ?: [];

$transiciones_medida = [
    'solicitada'   => ['notificada', 'rechazada'],
    'notificada'   => ['en_ejecucion', 'rechazada'],
    'en_ejecucion' => ['implementada', 'incumplida'],
    'implementada' => ['levantada'],
    'incumplida'   => ['en_ejecucion', 'levantada'],
];
?>

<?php if ($flash_local): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
        <?= e($flash_local[1]) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <!-- Solicitar medida -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-1">Solicitar medida</h3>
        <p class="text-xs text-gray-500 mb-3">Catálogo aplicable a la vinculación
            «<?= e(str_replace('_', ' ', $vinculacion)) ?>» de la persona denunciada.
            Las medidas no implican prejuzgamiento (Ac. 002 art. 22).</p>

        <?php if (empty($catalogo)): ?>
            <p class="text-sm text-gray-400">No hay medidas aplicables en el catálogo para esta vinculación.</p>
        <?php else: ?>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="solicitar">

            <label class="block text-sm text-gray-600" for="catalogo_id">Medida</label>
            <select name="catalogo_id" id="catalogo_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Seleccione…</option>
                <?php foreach ($catalogo as $item): ?>
                    <option value="<?= (int)$item['id'] ?>">
                        <?= e($item['nombre']) ?> (<?= e($item['fundamento']) ?><?= (int)$item['requiere_consentimiento'] === 1 ? ' · exige consentimiento' : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="block text-sm text-gray-600" for="solicitada_por">Solicitada por</label>
            <select name="solicitada_por" id="solicitada_por" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="victima">La persona afectada (su voluntad rige, art. 22)</option>
                <option value="equipo">El equipo EDHEG</option>
                <option value="oficio">De oficio (exige motivación)</option>
            </select>

            <label class="block text-sm text-gray-600" for="motivacion_oficio">Motivación de oficio</label>
            <textarea name="motivacion_oficio" id="motivacion_oficio" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>

            <label class="block text-sm text-gray-600" for="detalle_ejecucion">Datos para ejecutar
                (asignatura, grupo y horario · o señas del espacio a proteger)</label>
            <textarea name="detalle_ejecucion" id="detalle_ejecucion" rows="2"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                      placeholder="p. ej. Asignatura X · grupo solicitado 379-209 · jueves 10:00-14:00"></textarea>

            <label class="block text-sm text-gray-600" for="unidad_ejecutora_id">Unidad ejecutora</label>
            <select name="unidad_ejecutora_id" id="unidad_ejecutora_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Seleccione…</option>
                <?php foreach ($unidades_activas as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>

            <fieldset class="border border-gray-200 rounded-lg p-3">
                <legend class="text-sm text-gray-600 px-1">Consentimiento (RN-06, si la medida lo exige)</legend>
                <label class="block text-sm text-gray-600" for="alcance_consentimiento">Alcance explicado a la persona</label>
                <textarea name="alcance_consentimiento" id="alcance_consentimiento" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
                <label class="block text-sm text-gray-600 mt-2" for="medio_consentimiento">Medio</label>
                <select name="medio_consentimiento" id="medio_consentimiento" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="presencial">Presencial</option>
                    <option value="virtual">Virtual</option>
                    <option value="telefonico">Telefónico</option>
                    <option value="escrito">Escrito</option>
                </select>
            </fieldset>

            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Solicitar medida</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Medidas del caso -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Medidas del caso (art. 25: registro y trazabilidad)</h3>
        <ul class="space-y-3 text-sm">
            <?php if (empty($medidas)): ?>
                <li class="text-gray-400">Sin medidas registradas.</li>
            <?php endif; ?>
            <?php foreach ($medidas as $m): ?>
                <li class="border border-gray-200 rounded-lg p-3">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="font-medium"><?= e($m['medida_nombre']) ?></p>
                            <p class="text-xs text-gray-500"><?= e($m['fundamento']) ?>
                                · Ejecuta: <?= e($m['unidad_nombre'] ?? 'sin unidad') ?>
                                · Límite: <?= e(format_date($m['fecha_limite'])) ?>
                                <?php if ($m['proxima_revision']): ?>· Revisión: <?= e(format_date($m['proxima_revision'])) ?><?php endif; ?></p>
                        </div>
                        <span class="px-2 py-0.5 rounded text-xs font-semibold
                            <?= in_array($m['estado'], ['implementada'], true) ? 'bg-green-100 text-green-800'
                              : (in_array($m['estado'], ['incumplida', 'rechazada'], true) ? 'bg-red-100 text-red-800'
                              : ($m['estado'] === 'levantada' ? 'bg-gray-100 text-gray-800' : 'bg-yellow-100 text-yellow-800')) ?>">
                            <?= e(str_replace('_', ' ', $m['estado'])) ?></span>
                    </div>
                    <?php if (!in_array($m['estado'], ['rechazada', 'levantada'], true)): ?>
                        <a href="?page=oficio-medida&id=<?= (int)$m['id'] ?>" target="_blank" rel="noopener"
                           class="inline-block mt-2 px-3 py-1 border border-purple-600 text-purple-700 rounded-lg hover:bg-purple-50 text-xs">
                            📄 Generar oficio para la unidad ejecutora</a>
                    <?php endif; ?>
                    <?php $siguientes = $transiciones_medida[$m['estado']] ?? []; ?>
                    <?php if (!empty($siguientes)): ?>
                        <form method="POST" class="mt-2 flex flex-wrap items-end gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="transicion_medida">
                            <input type="hidden" name="medida_id" value="<?= (int)$m['id'] ?>">
                            <select name="nuevo_estado" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                                <?php foreach ($siguientes as $s): ?>
                                    <option value="<?= e($s) ?>"><?= e(str_replace('_', ' ', $s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="motivacion" placeholder="Motivación / constancia"
                                   class="flex-1 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Aplicar</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
