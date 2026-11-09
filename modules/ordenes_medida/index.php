<?php
/**
 * PORTAL DE LA UNIDAD ACADÉMICA Y TALENTO HUMANO
 *
 * v3.1 · Fase G · §7.7 · RN-08: este rol accede EXCLUSIVAMENTE a las órdenes
 * de medida (compromisos) de su propia unidad. Nunca a expedientes, relatos,
 * evidencia, valoraciones de riesgo ni a la identidad de la persona
 * denunciada. La proyección la construye ordenes_medida_para_unidad().
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_auth();

$usuario_actual = auth_user();

// El portal sirve a unidad_academica y talento_humano; la coordinación puede
// supervisarlo. Cualquier otro rol: fuera.
if (!in_array($usuario_actual['rol'], ['unidad_academica', 'talento_humano', 'coordinacion_edheg', 'coordinador'], true)) {
    audit_log($usuario_actual['id'], 'acceso_denegado_ordenes', 'compromisos', [
        'rol' => $usuario_actual['rol'],
    ]);
    http_response_code(403);
    require BASE_PATH . '/views/errors/404.php';
    exit;
}

$unidad_id = (int)($usuario_actual['unidad_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
}

$flash_local = null;

// ── POST: registrar constancia de cumplimiento ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'constancia') {
    $compromiso_id = input('compromiso_id', 0, 'int');

    // La unidad solo toca compromisos propios
    $propio = db_select_one(
        'SELECT id FROM compromisos WHERE id = ? AND responsable_unidad_id = ? LIMIT 1',
        [$compromiso_id, $unidad_id]);

    if (!$propio && !in_array($usuario_actual['rol'], ['coordinacion_edheg', 'coordinador'], true)) {
        audit_log($usuario_actual['id'], 'constancia_denegada_rn08', 'compromisos', [
            'rol' => $usuario_actual['rol'],
        ], $compromiso_id);
        $flash_local = ['error', 'La orden no pertenece a su unidad.'];
    } else {
        $r = cambiar_estado_compromiso($compromiso_id, 'cumplido', $usuario_actual['id'], [
            'constancia_texto' => input('constancia_texto'),
        ]);
        $flash_local = $r['exito'] ? ['ok', 'Constancia registrada. La medida queda documentada.'] : ['error', $r['error']];
    }
}

// ── POST: responder solicitud de estado (§16 E5) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'responder') {
    $compromiso_id = input('compromiso_id', 0, 'int');
    $propio = db_select_one(
        'SELECT id FROM compromisos WHERE id = ? AND responsable_unidad_id = ? LIMIT 1',
        [$compromiso_id, $unidad_id]);

    if (!$propio) {
        $flash_local = ['error', 'La solicitud no pertenece a su unidad.'];
    } else {
        $r = responder_compromiso($compromiso_id, input('respuesta'), $usuario_actual['id']);
        $flash_local = $r['exito'] ? ['ok', 'Respuesta enviada a Bienestar.'] : ['error', $r['error']];
    }
}

$ordenes = $unidad_id > 0 ? ordenes_medida_para_unidad($usuario_actual['id'], $unidad_id) : [];

// Talento Humano filtra por su bandeja de medidas laborales (misma proyección)
$title = 'Órdenes de medida';

ob_start();
?>
<div class="max-w-4xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-800 mb-1">Órdenes de medida de su unidad</h2>
        <p class="text-xs text-gray-500 mb-4">Cada orden es una referencia opaca: no incluye —ni incluirá—
            el tipo de hechos, el relato ni la identidad de las personas del caso.</p>

        <?php if ($unidad_id <= 0): ?>
            <p class="text-sm text-red-600">Su cuenta no tiene unidad asignada. Solicite al administrador
                que la configure.</p>
        <?php elseif (empty($ordenes)): ?>
            <p class="text-sm text-gray-400">Su unidad no tiene órdenes de medida.</p>
        <?php else: ?>
            <ul class="space-y-3 text-sm">
                <?php foreach ($ordenes as $o): ?>
                    <?php $activa = in_array($o['estado'], ['pendiente', 'en_curso', 'prorrogado', 'incumplido'], true); ?>
                    <li class="border border-gray-200 rounded-lg p-3">
                        <div class="flex justify-between items-start gap-2">
                            <div>
                                <p class="text-xs text-gray-500">Radicado: <?= e($o['radicado']) ?></p>
                                <p class="font-medium"><?= e($o['descripcion']) ?></p>
                                <p class="text-xs text-gray-500">Plazo: <?= e(format_date($o['fecha_limite'])) ?></p>
                                <?php if (!empty($o['constancia_texto'])): ?>
                                    <p class="text-xs text-gray-600 mt-1">Constancia: <?= e($o['constancia_texto']) ?></p>
                                <?php endif; ?>
                            </div>
                            <span class="px-2 py-0.5 rounded text-xs h-fit
                                <?= $o['estado'] === 'cumplido' ? 'bg-green-100 text-green-800'
                                  : ($o['estado'] === 'incumplido' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') ?>">
                                <?= e($o['estado']) ?></span>
                        </div>

                        <?php if ($activa && $o['origen'] === 'seguimiento' && $o['respondido_at'] === null): ?>
                            <form method="POST" class="mt-2 flex gap-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="responder">
                                <input type="hidden" name="compromiso_id" value="<?= (int)$o['compromiso_id'] ?>">
                                <input type="text" name="respuesta" required placeholder="Estado de la actuación en su dependencia"
                                       class="flex-1 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                                <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Responder</button>
                            </form>
                        <?php elseif ($activa): ?>
                            <form method="POST" class="mt-2 flex gap-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="constancia">
                                <input type="hidden" name="compromiso_id" value="<?= (int)$o['compromiso_id'] ?>">
                                <input type="text" name="constancia_texto" required placeholder="Constancia de cumplimiento"
                                       class="flex-1 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                                <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">
                                    Registrar constancia</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
