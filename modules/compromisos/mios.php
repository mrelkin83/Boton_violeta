<?php
/**
 * BANDEJA · MIS COMPROMISOS
 *
 * v3.1 §9 · Toda cuenta ve los compromisos donde es responsable (o su
 * unidad), ordenados por vencimiento y con semáforo.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_auth();

$usuario_actual = auth_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
}

$flash_local = null;

// ── POST: responder solicitud de estado (§16 E5) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'responder') {
    $r = responder_compromiso(input('compromiso_id', 0, 'int'), input('respuesta'), $usuario_actual['id']);
    $flash_local = $r['exito'] ? ['ok', 'Respuesta registrada; el compromiso quedó cumplido.'] : ['error', $r['error']];
}

// ── POST: registrar constancia / cambiar estado ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'transicion') {
    $r = cambiar_estado_compromiso(input('compromiso_id', 0, 'int'), input('nuevo_estado'),
                                   $usuario_actual['id'], [
        'constancia_texto' => input('constancia_texto'),
        'motivacion'       => input('motivacion'),
    ]);
    $flash_local = $r['exito'] ? ['ok', 'Compromiso actualizado.'] : ['error', $r['error']];
}

$compromisos = compromisos_de_usuario($usuario_actual['id'], $usuario_actual['unidad_id'] ?? null);

$title = 'Mis compromisos';

ob_start();
?>
<div class="max-w-4xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-800 mb-1">Compromisos a mi cargo</h2>
        <p class="text-xs text-gray-500 mb-4">Verde: más de 3 días hábiles · Ámbar: 3 o menos · Rojo: vencido.
            El cron avisa antes del vencimiento y escala a coordinación después.</p>

        <ul class="space-y-3 text-sm">
            <?php if (empty($compromisos)): ?>
                <li class="text-gray-400">No tiene compromisos activos.</li>
            <?php endif; ?>
            <?php foreach ($compromisos as $c): ?>
                <?php $sem = semaforo_compromiso($c['fecha_limite']); ?>
                <li class="border border-gray-200 rounded-lg p-3">
                    <div class="flex justify-between items-start gap-2">
                        <div>
                            <p class="font-medium"><?= e($c['descripcion']) ?></p>
                            <p class="text-xs text-gray-500">Radicado: <?= e($c['radicado']) ?>
                                · Vence: <?= e(format_date($c['fecha_limite'])) ?> · Estado: <?= e($c['estado']) ?></p>
                        </div>
                        <span class="w-3 h-3 rounded-full inline-block mt-1 <?= $sem === 'verde' ? 'bg-green-500' : ($sem === 'ambar' ? 'bg-yellow-400' : 'bg-red-500') ?>"
                              title="Plazo: <?= e($sem) ?>" aria-label="Plazo: <?= e($sem) ?>"></span>
                    </div>

                    <?php if ($c['origen'] === 'seguimiento' && $c['respondido_at'] === null): ?>
                        <form method="POST" class="mt-2 flex gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="responder">
                            <input type="hidden" name="compromiso_id" value="<?= (int)$c['id'] ?>">
                            <input type="text" name="respuesta" required placeholder="Estado del caso en su dependencia"
                                   class="flex-1 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Responder</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" class="mt-2 flex flex-wrap gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="transicion">
                            <input type="hidden" name="compromiso_id" value="<?= (int)$c['id'] ?>">
                            <select name="nuevo_estado" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                                <?php foreach (TRANSICIONES_COMPROMISO[$c['estado']] ?? [] as $s): ?>
                                    <option value="<?= e($s) ?>"><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="constancia_texto" placeholder="Constancia (cumplido)"
                                   class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <input type="text" name="motivacion" placeholder="Motivación"
                                   class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
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
