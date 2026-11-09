<?php
/**
 * ADMIN · PLAZOS, FESTIVOS Y CALENDARIO ACADÉMICO
 *
 * v3.1 §7.5 · Catálogo de plazos normativos (solo lectura de fundamento),
 * festivos por año (Ley 51 de 1983, recalculables) y calendario académico.
 */

require BASE_PATH . '/modules/admin/_guard.php';

// ── POST: recalcular festivos de un año ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'recalcular_festivos') {
    $anio = input('anio', 0, 'int');

    if ($anio < 2020 || $anio > 2100) {
        $flash_local = ['error', 'Año fuera de rango.'];
    } else {
        $festivos = festivos_colombia($anio);
        $insertados = 0;

        foreach ($festivos as $fecha => $nombre) {
            if (!db_exists('festivos', ['fecha' => $fecha])) {
                if (db_insert_simple('festivos', [
                    'fecha' => $fecha, 'nombre' => $nombre, 'origen' => 'ley_51_1983',
                ])) {
                    $insertados++;
                }
            }
        }

        audit_log($usuario_actual['id'], 'festivos_recalculados', 'festivos', [
            'motivo' => $anio . '_' . $insertados,
        ]);
        $flash_local = ['ok', "Festivos de $anio: $insertados fechas nuevas cargadas."];
    }
}

// ── POST: editar cantidad de un plazo ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'editar_plazo') {
    $cantidad = input('cantidad', 0, 'int');

    if ($cantidad < 1) {
        $flash_local = ['error', 'La cantidad debe ser positiva.'];
    } else {
        $plazo_id = input('plazo_id', 0, 'int');
        $anterior = db_select_one('SELECT cantidad FROM catalogo_plazos WHERE id = ? LIMIT 1', [$plazo_id]);
        $ok = db_update_simple('catalogo_plazos', ['cantidad' => $cantidad], ['id' => $plazo_id]);

        if ($ok !== false) {
            audit_log($usuario_actual['id'], 'plazo_editado', 'catalogo_plazos', [
                'plazo' => ($anterior['cantidad'] ?? '?') . '→' . $cantidad,
            ], $plazo_id);
        }
        $flash_local = $ok !== false ? ['ok', 'Plazo actualizado.'] : ['error', 'No se pudo actualizar.'];
    }
}

// ── POST: añadir hito de calendario académico ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'agregar_hito') {
    $id = db_insert_simple('calendario_academico', [
        'periodo'     => trim((string)input('periodo')),
        'hito'        => trim((string)input('hito')),
        'fecha_desde' => input('fecha_desde'),
        'fecha_hasta' => input('fecha_hasta') ?: input('fecha_desde'),
    ]);
    $flash_local = $id ? ['ok', 'Hito registrado.'] : ['error', 'No se pudo registrar.'];
}

$plazos = db_select('SELECT * FROM catalogo_plazos ORDER BY codigo') ?: [];
$festivos_proximos = db_select(
    'SELECT * FROM festivos WHERE fecha >= CURDATE() ORDER BY fecha LIMIT 12') ?: [];
$calendario = db_select(
    'SELECT * FROM calendario_academico ORDER BY fecha_desde DESC LIMIT 20') ?: [];

$title = 'Plazos y festivos';

ob_start();
?>
<div class="max-w-5xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-4 mb-4 overflow-x-auto">
        <h2 class="font-semibold text-gray-800 mb-1">Catálogo de plazos normativos</h2>
        <p class="text-xs text-gray-500 mb-3">Cada plazo cita su fuente. Cambiar una cantidad queda auditado
            con el valor anterior.</p>
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left">
                <th class="p-2">Plazo</th><th class="p-2">Fuente</th><th class="p-2">Cantidad</th><th class="p-2"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($plazos as $p): ?>
                <tr class="border-b border-gray-100">
                    <td class="p-2"><?= e($p['nombre']) ?></td>
                    <td class="p-2 text-xs text-gray-500"><?= e($p['fundamento']) ?></td>
                    <td class="p-2"><?= (int)$p['cantidad'] ?> <?= e($p['unidad']) ?></td>
                    <td class="p-2">
                        <form method="POST" class="flex gap-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="editar_plazo">
                            <input type="hidden" name="plazo_id" value="<?= (int)$p['id'] ?>">
                            <input type="number" name="cantidad" min="1" value="<?= (int)$p['cantidad'] ?>"
                                   class="w-16 border border-gray-300 rounded-lg px-1 py-1 text-sm">
                            <button type="submit" class="text-purple-700 hover:underline text-xs">Guardar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($plazos)): ?>
                <tr><td colspan="4" class="p-2 text-gray-400">El catálogo de plazos se siembra en la migración 0014.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-3">Festivos (Ley 51 de 1983)</h2>
            <form method="POST" class="flex gap-2 mb-3">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="recalcular_festivos">
                <input type="number" name="anio" value="<?= (int)date('Y') + 1 ?>" min="2020" max="2100"
                       class="w-24 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">
                    Calcular y cargar año</button>
            </form>
            <ul class="text-sm space-y-1">
                <?php foreach ($festivos_proximos as $f): ?>
                    <li class="flex justify-between"><span><?= e($f['nombre']) ?></span>
                        <span class="text-gray-500"><?= e(format_date($f['fecha'])) ?></span></li>
                <?php endforeach; ?>
                <?php if (empty($festivos_proximos)): ?><li class="text-gray-400">Sin festivos futuros cargados.</li><?php endif; ?>
            </ul>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-3">Calendario académico</h2>
            <form method="POST" class="grid grid-cols-2 gap-2 mb-3">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="agregar_hito">
                <input type="text" name="periodo" placeholder="2026-2" required class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                <input type="text" name="hito" placeholder="Hito (p. ej. receso)" required class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                <input type="date" name="fecha_desde" required class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                <input type="date" name="fecha_hasta" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                <button type="submit" class="col-span-2 px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Añadir</button>
            </form>
            <ul class="text-sm space-y-1">
                <?php foreach ($calendario as $c): ?>
                    <li class="flex justify-between"><span><?= e($c['periodo']) ?> · <?= e($c['hito']) ?></span>
                        <span class="text-gray-500"><?= e(format_date($c['fecha_desde'])) ?>—<?= e(format_date($c['fecha_hasta'])) ?></span></li>
                <?php endforeach; ?>
                <?php if (empty($calendario)): ?><li class="text-gray-400">Sin hitos registrados.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
