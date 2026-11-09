<?php
/**
 * ADMIN · INTEGRACIONES
 *
 * v3.1 §7.5 · Estado de Microsoft Graph (token en caché, interruptores) y
 * prueba de envío al buzón de control mediante el Outbox (nunca síncrona).
 */

require BASE_PATH . '/modules/admin/_guard.php';

// ── POST: prueba de envío (por la cola, ADR-08) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'prueba_envio') {
    $buzon = (string)config_valor('CORREO_REMITENTE', 'vbgbienestarud@udistrital.edu.co');

    $id = encolar_notificacion('correo', $buzon, 'caso_asignado', [
        'radicado' => 'PRUEBA-INTEGRACION',
        'plazo'    => date('Y-m-d'),
        'enlace'   => url('?page=login'),
    ]);

    $flash_local = $id
        ? ['ok', 'Prueba encolada hacia el buzón de control. El cron la despachará; revise el Outbox.']
        : ['error', 'No se pudo encolar la prueba.'];
}

$graph_on = function_exists('graph_activo') ? graph_activo() : false;
$token_cache = BASE_PATH . '/cache/graph_token.json';
$token_info = is_file($token_cache)
    ? 'Token en caché desde ' . date('Y-m-d H:i', filemtime($token_cache))
    : 'Sin token en caché';

$ultimo_envio = db_select_one(
    "SELECT enviado_at FROM notificaciones_salientes WHERE estado = 'enviado' ORDER BY enviado_at DESC LIMIT 1");
$pendientes = db_select_one(
    "SELECT COUNT(*) AS total FROM notificaciones_salientes WHERE estado = 'pendiente'");
$fallidas = db_select_one(
    "SELECT COUNT(*) AS total FROM notificaciones_salientes WHERE estado = 'fallido'");

$interruptores = [
    'GRAPH_ACTIVO'                 => config_valor('GRAPH_ACTIVO', false),
    'ENCUENTRO_INICIAL_AUTOMATICO' => config_valor('ENCUENTRO_INICIAL_AUTOMATICO', true),
    'CANAL_ANONIMO_ACTIVO'         => config_valor('CANAL_ANONIMO_ACTIVO', false),
    'SEGUIMIENTO_OTP_ACTIVO'       => defined('SEGUIMIENTO_OTP_ACTIVO') ? SEGUIMIENTO_OTP_ACTIVO : false,
];

$title = 'Integraciones';

ob_start();
?>
<div class="max-w-4xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-3">Microsoft Graph</h2>
            <dl class="text-sm space-y-2">
                <div><dt class="text-gray-500">Estado</dt>
                    <dd><span class="px-2 py-0.5 rounded text-xs <?= $graph_on ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                        <?= $graph_on ? 'Activo' : 'Inactivo' ?></span></dd></div>
                <div><dt class="text-gray-500">Token</dt><dd><?= e($token_info) ?></dd></div>
                <div><dt class="text-gray-500">Último envío exitoso</dt>
                    <dd><?= $ultimo_envio && $ultimo_envio['enviado_at'] ? e(format_datetime($ultimo_envio['enviado_at'])) : 'Ninguno' ?></dd></div>
                <div><dt class="text-gray-500">Cola</dt>
                    <dd><?= (int)($pendientes['total'] ?? 0) ?> pendientes · <?= (int)($fallidas['total'] ?? 0) ?> fallidas</dd></div>
            </dl>
            <form method="POST" class="mt-4">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="prueba_envio">
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">
                    Encolar prueba al buzón de control</button>
            </form>
            <p class="text-xs text-gray-400 mt-2">La prueba usa la cola (ADR-08): ninguna llamada a Graph
                se hace de forma síncrona desde la interfaz.</p>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-3">Interruptores</h2>
            <ul class="text-sm space-y-2">
                <?php foreach ($interruptores as $clave => $valor): ?>
                    <li class="flex justify-between border-b border-gray-100 pb-2">
                        <span class="font-mono text-xs"><?= e($clave) ?></span>
                        <span class="px-2 py-0.5 rounded text-xs <?= $valor ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                            <?= $valor ? 'Encendido' : 'Apagado' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="text-xs text-gray-400 mt-3">Los interruptores se cambian en «Configuración»
                (los marcados G-15 exigen esa compuerta) o en bv_config.php del servidor.</p>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
