<?php
/**
 * PESTAÑA · SANCIONES
 *
 * v3.1 §7.1 · Ac. 003 art. 41: registro de la decisión y su firmeza.
 * Solo decisor y coordinación. Alimenta los reportes de los arts. 31 y 40 y
 * habilita los compromisos restaurativos (RN-16).
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit('Acceso denegado'); }

// ── POST: registrar sanción ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'registrar_sancion') {
    $r = registrar_sancion([
        'denuncia_id' => $denuncia_id,
        'autoridad'   => input('autoridad'),
        'tipo'        => input('tipo_sancion'),
        'acto_numero' => input('acto_numero'),
        'acto_fecha'  => input('acto_fecha'),
    ], $usuario_actual['id'], $usuario_actual['rol']);

    $flash_local = $r['exito'] ? ['ok', 'Sanción registrada (aún sin firmeza).'] : ['error', $r['error']];
}

// ── POST: marcar en firme ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'en_firme') {
    $r = marcar_sancion_firme(input('sancion_id', 0, 'int'), input('fecha_firmeza'),
                              $usuario_actual['id'], $usuario_actual['rol']);
    $flash_local = $r['exito'] ? ['ok', 'Firmeza registrada. Habilita medidas restaurativas (RN-16).'] : ['error', $r['error']];
}

$sanciones = sanciones_del_caso($denuncia_id);
?>

<?php if ($flash_local): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
        <?= e($flash_local[1]) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Registrar sanción (art. 41)</h3>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="registrar_sancion">
            <label class="block text-sm text-gray-600" for="autoridad">Autoridad que decide</label>
            <input type="text" name="autoridad" id="autoridad" required
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <label class="block text-sm text-gray-600" for="tipo_sancion">Tipo de sanción (según el régimen aplicable)</label>
            <input type="text" name="tipo_sancion" id="tipo_sancion" required
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-sm text-gray-600" for="acto_numero">Número del acto</label>
                    <input type="text" name="acto_numero" id="acto_numero"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-gray-600" for="acto_fecha">Fecha del acto</label>
                    <input type="date" name="acto_fecha" id="acto_fecha" required
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
            </div>
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Registrar</button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Sanciones del caso</h3>
        <ul class="text-sm space-y-3">
            <?php if (empty($sanciones)): ?>
                <li class="text-gray-400">Sin sanciones registradas.</li>
            <?php endif; ?>
            <?php foreach ($sanciones as $s): ?>
                <li class="border border-gray-200 rounded-lg p-3">
                    <div class="flex justify-between">
                        <div>
                            <p class="font-medium"><?= e($s['tipo']) ?></p>
                            <p class="text-xs text-gray-500"><?= e($s['autoridad']) ?>
                                · acto <?= e($s['acto_numero'] ?? 's/n') ?> del <?= e(format_date($s['acto_fecha'])) ?></p>
                        </div>
                        <span class="px-2 py-0.5 rounded text-xs h-fit <?= (int)$s['en_firme'] === 1 ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                            <?= (int)$s['en_firme'] === 1 ? 'En firme (' . e(format_date($s['en_firme_at'])) . ')' : 'Sin firmeza' ?></span>
                    </div>
                    <?php if ((int)$s['en_firme'] !== 1): ?>
                        <form method="POST" class="mt-2 flex gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="en_firme">
                            <input type="hidden" name="sancion_id" value="<?= (int)$s['id'] ?>">
                            <input type="date" name="fecha_firmeza" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Marcar en firme</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
