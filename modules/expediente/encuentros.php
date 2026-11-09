<?php
/**
 * PESTAÑA · ENCUENTROS
 *
 * v3.1 §7.1 · Agenda (presencial/Teams/telefónico) con RN-04 y RN-05
 * validadas en el dominio; acta del encuentro inicial con checklist del
 * Protocolo y hash al cerrar (§16 E2 · art. 53).
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit('Acceso denegado'); }

$personas = db_select(
    "SELECT id, papel FROM personas_caso WHERE denuncia_id = ? ORDER BY papel", [$denuncia_id]) ?: [];
$profesionales = db_select(
    "SELECT id, nombre FROM usuarios WHERE activo = 1
      AND rol IN ('psicologo','trabajador_social','abogado','coordinacion_edheg','coordinador')
      ORDER BY nombre") ?: [];

// ── POST: agendar encuentro ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'agendar') {
    $participantes = [['papel' => 'profesional', 'usuario_id' => input('profesional_id', 0, 'int')]];

    foreach ($personas as $p) {
        if (input('persona_' . $p['id']) === '1') {
            // El papel del participante es el papel de la persona en el caso;
            // RN-04 se verifica en el dominio con esta información
            $papel = in_array($p['papel'], ['afectada', 'denunciada'], true) ? $p['papel'] : 'apoyo';
            $participantes[] = ['papel' => $papel, 'persona_id' => (int)$p['id']];
        }
    }

    $inicia = input('fecha') . ' ' . input('hora_inicio') . ':00';
    $termina = input('fecha') . ' ' . input('hora_fin') . ':00';

    $r = crear_encuentro([
        'denuncia_id'    => $denuncia_id,
        'etapa'          => input('etapa', 'encuentro_inicial'),
        'modalidad'      => input('modalidad', 'virtual'),
        'inicia_at'      => $inicia,
        'termina_at'     => $termina,
        'profesional_id' => input('profesional_id', 0, 'int'),
    ], $participantes);

    $flash_local = $r['exito'] ? ['ok', 'Encuentro agendado.'] : ['error', $r['motivo']];
}

// ── POST: abrir/cerrar acta del encuentro inicial (§16 E2) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'guardar_acta') {
    $encuentro_id = input('encuentro_id', 0, 'int');

    $encuentro = db_select_one(
        "SELECT id FROM encuentros WHERE id = ? AND denuncia_id = ? AND etapa = 'encuentro_inicial' LIMIT 1",
        [$encuentro_id, $denuncia_id]);

    if (!$encuentro) {
        $flash_local = ['error', 'El acta solo aplica al encuentro inicial de este expediente.'];
    } else {
        $acta = db_select_one('SELECT * FROM actas_encuentro WHERE encuentro_id = ? LIMIT 1', [$encuentro_id]);

        if ($acta && (int)$acta['cerrada'] === 1) {
            $flash_local = ['error', 'El acta ya está cerrada y sellada; no admite cambios.'];
        } else {
            $campos = [
                'socializo_ruta'            => input('socializo_ruta') === '1' ? 1 : 0,
                'presento_profesionales'    => input('presento_profesionales') === '1' ? 1 : 0,
                'verifico_contactos'        => input('verifico_contactos') === '1' ? 1 : 0,
                'ratifico_confidencialidad' => input('ratifico_confidencialidad') === '1' ? 1 : 0,
                'recibio_descripcion'       => input('recibio_descripcion') === '1' ? 1 : 0,
                'acordo_seguimientos'       => input('acordo_seguimientos') === '1' ? 1 : 0,
                'sibud_referencia'          => trim((string)input('sibud_referencia')) ?: null,
                'observaciones'             => trim((string)input('observaciones')) ?: null,
            ];

            if (!$acta) {
                $acta_id = db_insert_simple('actas_encuentro', array_merge($campos, [
                    'encuentro_id' => $encuentro_id,
                    'denuncia_id'  => $denuncia_id,
                    'created_at'   => date('Y-m-d H:i:s'),
                ]));
            } else {
                db_update_simple('actas_encuentro', $campos, ['id' => $acta['id']]);
                $acta_id = $acta['id'];
            }

            if (input('cerrar') === '1' && $acta_id) {
                // Sello: hash del contenido del checklist. El acta REFERENCIA
                // el relato; nunca lo copia (RN-03).
                $hash = hash('sha256', json_encode($campos) . '|' . $encuentro_id . '|' . date('c'));
                db_update_simple('actas_encuentro', [
                    'cerrada'    => 1,
                    'cerrada_por'=> $usuario_actual['id'],
                    'cerrada_at' => date('Y-m-d H:i:s'),
                    'hash_acta'  => $hash,
                ], ['id' => $acta_id]);

                audit_log($usuario_actual['id'], 'acta_encuentro_cerrada', 'actas_encuentro', [
                    'motivo' => 'encuentro_inicial',
                ], (int)$acta_id);

                $flash_local = ['ok', 'Acta cerrada y sellada. La ruta puede avanzar a atención integral (RN-19).'];
            } else {
                audit_log($usuario_actual['id'], 'acta_encuentro_guardada', 'actas_encuentro', [], (int)$acta_id);
                $flash_local = ['ok', 'Acta guardada (aún abierta).'];
            }
        }
    }
}

$encuentros = db_select(
    'SELECT * FROM encuentros WHERE denuncia_id = ? ORDER BY inicia_at DESC', [$denuncia_id]) ?: [];
$acta_inicial = db_select_one(
    'SELECT a.* FROM actas_encuentro a WHERE a.denuncia_id = ? ORDER BY a.id DESC LIMIT 1', [$denuncia_id]);
$encuentro_inicial = db_select_one(
    "SELECT id FROM encuentros WHERE denuncia_id = ? AND etapa = 'encuentro_inicial' ORDER BY id LIMIT 1",
    [$denuncia_id]);
?>

<?php if ($flash_local): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
        <?= e($flash_local[1]) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <!-- Agendar -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-1">Agendar encuentro</h3>
        <p class="text-xs text-gray-500 mb-3">RN-04: el sistema rechaza cualquier encuentro que reúna a la
            persona afectada con la persona denunciada. Sin excepciones.</p>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="agendar">

            <label class="block text-sm text-gray-600" for="etapa">Etapa</label>
            <select name="etapa" id="etapa" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="encuentro_inicial">Encuentro inicial</option>
                <option value="atencion_psicologica">Atención psicológica</option>
                <option value="atencion_juridica">Atención jurídica</option>
                <option value="trabajo_social">Trabajo social</option>
                <option value="salud">Salud</option>
                <option value="revision_trimestral">Revisión trimestral</option>
                <option value="audiencia">Audiencia (art. 53)</option>
            </select>

            <label class="block text-sm text-gray-600" for="modalidad">Modalidad</label>
            <select name="modalidad" id="modalidad" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="virtual">Virtual (Teams)</option>
                <option value="presencial">Presencial</option>
                <option value="telefonico">Telefónico</option>
            </select>

            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="block text-sm text-gray-600" for="fecha">Fecha</label>
                    <input type="date" name="fecha" id="fecha" required class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-gray-600" for="hora_inicio">Inicio</label>
                    <input type="time" name="hora_inicio" id="hora_inicio" required class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-gray-600" for="hora_fin">Fin</label>
                    <input type="time" name="hora_fin" id="hora_fin" required class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
            </div>

            <label class="block text-sm text-gray-600" for="profesional_id">Profesional</label>
            <select name="profesional_id" id="profesional_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Seleccione…</option>
                <?php foreach ($profesionales as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= e($p['nombre']) ?></option>
                <?php endforeach; ?>
            </select>

            <fieldset class="border border-gray-200 rounded-lg p-3">
                <legend class="text-sm text-gray-600 px-1">Personas del caso que participan</legend>
                <?php if (empty($personas)): ?>
                    <p class="text-xs text-gray-400">Sin personas registradas en el caso.</p>
                <?php endif; ?>
                <?php foreach ($personas as $p): ?>
                    <label class="flex items-center space-x-2 text-sm text-gray-700">
                        <input type="checkbox" name="persona_<?= (int)$p['id'] ?>" value="1">
                        <span>Persona <?= e($p['papel']) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Agendar</button>
        </form>
    </div>

    <!-- Lista + acta -->
    <div class="space-y-4">
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="font-semibold text-gray-800 mb-3">Encuentros del caso</h3>
            <ul class="text-sm space-y-2">
                <?php if (empty($encuentros)): ?>
                    <li class="text-gray-400">Sin encuentros.</li>
                <?php endif; ?>
                <?php foreach ($encuentros as $enc): ?>
                    <li class="flex justify-between border-b border-gray-100 pb-2">
                        <div>
                            <p class="font-medium"><?= e(str_replace('_', ' ', $enc['etapa'])) ?></p>
                            <p class="text-xs text-gray-500"><?= e(format_datetime($enc['inicia_at'])) ?> · <?= e($enc['modalidad']) ?></p>
                        </div>
                        <span class="px-2 py-0.5 rounded text-xs h-fit
                            <?= $enc['estado_sync'] === 'sincronizado' ? 'bg-green-100 text-green-800'
                              : ($enc['estado_sync'] === 'error' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') ?>">
                            <?= e($enc['estado_sync']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if ($encuentro_inicial): ?>
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="font-semibold text-gray-800 mb-1">Acta del encuentro inicial (§16 E2)</h3>
            <?php if ($acta_inicial && (int)$acta_inicial['cerrada'] === 1): ?>
                <p class="text-sm text-green-700">Acta cerrada el <?= e(format_datetime($acta_inicial['cerrada_at'])) ?>.
                    Sello: <span class="text-xs text-gray-500"><?= e(substr($acta_inicial['hash_acta'], 0, 16)) ?>…</span></p>
            <?php else: ?>
                <p class="text-xs text-gray-500 mb-3">El acta referencia el relato, nunca lo copia (RN-03).
                    El identificador de SIBUD es solo la referencia externa, jamás contenido clínico.</p>
                <form method="POST" class="space-y-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="guardar_acta">
                    <input type="hidden" name="encuentro_id" value="<?= (int)$encuentro_inicial['id'] ?>">
                    <?php
                    $checklist = [
                        'socializo_ruta'            => 'Se socializó la ruta de atención',
                        'presento_profesionales'    => 'Se presentaron los y las profesionales',
                        'verifico_contactos'        => 'Se verificaron los datos de contacto',
                        'ratifico_confidencialidad' => 'Se ratificó la confidencialidad',
                        'recibio_descripcion'       => 'Se recibió la descripción de los hechos',
                        'acordo_seguimientos'       => 'Se acordaron los encuentros de seguimiento',
                    ];
                    foreach ($checklist as $campo => $etiqueta): ?>
                        <label class="flex items-center space-x-2 text-sm text-gray-700">
                            <input type="checkbox" name="<?= e($campo) ?>" value="1"
                                <?= $acta_inicial && (int)$acta_inicial[$campo] === 1 ? 'checked' : '' ?>>
                            <span><?= e($etiqueta) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <label class="block text-sm text-gray-600" for="sibud_referencia">Referencia SIBUD (solo identificador)</label>
                    <input type="text" name="sibud_referencia" id="sibud_referencia"
                           value="<?= e($acta_inicial['sibud_referencia'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <label class="block text-sm text-gray-600" for="observaciones">Observaciones (sin el relato)</label>
                    <textarea name="observaciones" id="observaciones" rows="2"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"><?= e($acta_inicial['observaciones'] ?? '') ?></textarea>
                    <div class="flex space-x-2">
                        <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm">Guardar</button>
                        <button type="submit" name="cerrar" value="1"
                                class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Cerrar y sellar</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
