<?php
/**
 * ADMIN · COLA DE NOTIFICACIONES (OUTBOX)
 *
 * v3.1 §7.5 y §7.3 · Estado por dirección, agrupado por grupo_envio (RN-15).
 * El destinatario se muestra ENMASCARADO: la consola administra la cola, no
 * lee correos personales.
 */

require BASE_PATH . '/modules/admin/_guard.php';

// ── POST: reintentar ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'reintentar') {
    $ok = db_update_simple('notificaciones_salientes', [
        'estado'          => 'pendiente',
        'intentos'        => 0,
        'proximo_intento' => date('Y-m-d H:i:s'),
        'ultimo_error'    => null,
    ], ['id' => input('notificacion_id', 0, 'int')]);

    if ($ok !== false) {
        audit_log($usuario_actual['id'], 'notificacion_reencolada', 'notificaciones_salientes', [],
                  input('notificacion_id', 0, 'int'));
    }
    $flash_local = $ok !== false ? ['ok', 'Notificación reencolada.'] : ['error', 'No se pudo reencolar.'];
}

// ── POST: descartar con motivo ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'descartar') {
    $motivo = trim((string)input('motivo'));

    if ($motivo === '') {
        $flash_local = ['error', 'Descartar exige el motivo.'];
    } else {
        $ok = db_update_simple('notificaciones_salientes', [
            'estado'       => 'descartado',
            'ultimo_error' => 'Descartada: ' . mb_substr($motivo, 0, 400),
        ], ['id' => input('notificacion_id', 0, 'int')]);

        if ($ok !== false) {
            audit_log($usuario_actual['id'], 'notificacion_descartada', 'notificaciones_salientes', [
                'motivo' => mb_substr($motivo, 0, 100),
            ], input('notificacion_id', 0, 'int'));
        }
        $flash_local = $ok !== false ? ['ok', 'Notificación descartada.'] : ['error', 'No se pudo descartar.'];
    }
}

$filtro = input_get('estado', 'pendiente');
if (!in_array($filtro, ['pendiente', 'enviado', 'fallido', 'descartado', 'todos'], true)) {
    $filtro = 'pendiente';
}

$sql = 'SELECT id, tipo, destinatario, destinatario_tipo, grupo_envio, plantilla,
               estado, intentos, proximo_intento, ultimo_error, created_at, enviado_at
          FROM notificaciones_salientes';
$params = [];

if ($filtro !== 'todos') {
    $sql .= ' WHERE estado = ?';
    $params[] = $filtro;
}

$notificaciones = db_select($sql . ' ORDER BY created_at DESC LIMIT 100', $params) ?: [];

$title = 'Cola de notificaciones';

ob_start();
?>
<div class="max-w-5xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-4 overflow-x-auto">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <h2 class="font-semibold text-gray-800">Outbox (ADR-08 · despacho por cron)</h2>
            <nav class="flex space-x-2 text-sm" aria-label="Filtro por estado">
                <?php foreach (['pendiente', 'enviado', 'fallido', 'descartado', 'todos'] as $estado): ?>
                    <a href="?page=admin-outbox&estado=<?= e($estado) ?>"
                       class="px-2 py-1 rounded <?= $filtro === $estado ? 'bg-purple-600 text-white' : 'text-purple-700 hover:underline' ?>">
                        <?= e(ucfirst($estado)) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>

        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left">
                <th class="p-2">Plantilla</th><th class="p-2">Destinatario</th><th class="p-2">Canal</th>
                <th class="p-2">Grupo</th><th class="p-2">Estado</th><th class="p-2">Intentos</th><th class="p-2"></th>
            </tr></thead>
            <tbody>
            <?php if (empty($notificaciones)): ?>
                <tr><td colspan="7" class="p-2 text-gray-400">Sin notificaciones en este estado.</td></tr>
            <?php endif; ?>
            <?php foreach ($notificaciones as $n): ?>
                <tr class="border-b border-gray-100">
                    <td class="p-2"><?= e($n['plantilla']) ?>
                        <p class="text-xs text-gray-400"><?= e(format_datetime($n['created_at'])) ?></p></td>
                    <td class="p-2 font-mono text-xs"><?= e(enmascarar_contacto($n['destinatario'], $n['tipo'] === 'sms' ? 'telefono' : 'correo')) ?></td>
                    <td class="p-2 text-xs"><?= e($n['destinatario_tipo']) ?></td>
                    <td class="p-2 font-mono text-xs text-gray-400"><?= e($n['grupo_envio'] ? substr($n['grupo_envio'], 0, 8) : '—') ?></td>
                    <td class="p-2">
                        <span class="px-2 py-0.5 rounded text-xs
                            <?= $n['estado'] === 'enviado' ? 'bg-green-100 text-green-800'
                              : ($n['estado'] === 'fallido' ? 'bg-red-100 text-red-800'
                              : ($n['estado'] === 'descartado' ? 'bg-gray-100 text-gray-800' : 'bg-yellow-100 text-yellow-800')) ?>">
                            <?= e($n['estado']) ?></span>
                        <?php if ($n['ultimo_error']): ?>
                            <p class="text-xs text-red-500 mt-1"><?= e(mb_substr($n['ultimo_error'], 0, 60)) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="p-2"><?= (int)$n['intentos'] ?></td>
                    <td class="p-2">
                        <?php if (in_array($n['estado'], ['fallido', 'descartado'], true)): ?>
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="reintentar">
                                <input type="hidden" name="notificacion_id" value="<?= (int)$n['id'] ?>">
                                <button type="submit" class="text-purple-700 hover:underline text-xs">Reintentar</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array($n['estado'], ['pendiente', 'fallido'], true)): ?>
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="descartar">
                                <input type="hidden" name="notificacion_id" value="<?= (int)$n['id'] ?>">
                                <input type="text" name="motivo" placeholder="motivo" required
                                       class="w-24 border border-gray-300 rounded px-1 py-0.5 text-xs">
                                <button type="submit" class="text-red-600 hover:underline text-xs">Descartar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
