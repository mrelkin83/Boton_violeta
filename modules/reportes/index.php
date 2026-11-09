<?php
/**
 * REPORTES · SELECTOR, VISTA PREVIA Y EXPORTACIÓN
 *
 * v3.1 · Fase F · §7.6 · RN-10 se aplica en generación: el sistema se NIEGA
 * a exportar celdas por debajo del umbral, no las marca con advertencia.
 * Roles: reportes, comision_seguimiento (Res. 142/2026 art. 13) y coordinación.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_auth();

$usuario_actual = auth_user();

if (!in_array($usuario_actual['rol'],
    ['reportes', 'comision_seguimiento', 'coordinacion_edheg', 'coordinador', 'administrador', 'admin'], true)) {
    audit_log($usuario_actual['id'], 'acceso_denegado_reportes', 'reportes_generados', [
        'rol' => $usuario_actual['rol'],
    ]);
    http_response_code(403);
    require BASE_PATH . '/views/errors/404.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
}

$tipos = [
    'trimestral'        => 'Trimestral · quejas tramitadas y sanciones (Ac. 002 art. 31) — periodo AAAA-Tn',
    'sivige_semestral'  => 'Semestral · acoso sexual para SIVIGE (Ley 2365 art. 11.7) — periodo AAAA-Sn',
    'semestral_rectoria'=> 'Semestral · implementación de medidas para Rectoría (Ac. 002 art. 48) — periodo AAAA-Sn',
    'anual_art40'       => 'Anual · UD Libre de VBG para CSU y Corte Constitucional (Ac. 002 art. 40) — periodo AAAA',
    'estado_gestion'    => 'Estado de gestión e indicadores (Res. 142/2026) — periodo AAAA',
];

$flash_local = null;
$reporte = null;

// ── Descargar CSV de un reporte ya generado (y ya verificado por RN-10) ─────
if (input_get('descargar', 0, 'int')) {
    $fila = db_select_one('SELECT * FROM reportes_generados WHERE id = ? LIMIT 1',
                          [input_get('descargar', 0, 'int')]);

    if ($fila) {
        audit_log($usuario_actual['id'], 'reporte_exportado', 'reportes_generados', [
            'motivo' => $fila['tipo'],
        ], (int)$fila['id']);

        $datos = json_decode($fila['contenido_json'], true) ?: [];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="reporte_' . $fila['tipo'] . '_' . $fila['periodo'] . '.csv"');

        $salida = fopen('php://output', 'w');
        fwrite($salida, "\xEF\xBB\xBF"); // BOM para Excel

        fputcsv($salida, ['Reporte', $datos['tipo'] ?? $fila['tipo']], ';');
        fputcsv($salida, ['Periodo', $datos['periodo'] ?? $fila['periodo']], ';');
        fputcsv($salida, ['Fundamento', $datos['fundamento'] ?? ''], ';');
        fputcsv($salida, ['Umbral de supresión', $fila['umbral_aplicado']], ';');
        fputcsv($salida, [], ';');

        $secciones = $datos['secciones'] ?? ['principal' => $datos['tabla'] ?? []];

        foreach ($secciones as $nombre => $tabla) {
            fputcsv($salida, [strtoupper(str_replace('_', ' ', $nombre))], ';');

            if (is_array($tabla) && is_array(reset($tabla))) {
                $columnas = [];
                foreach ($tabla as $filas_t) {
                    foreach (array_keys($filas_t) as $c) { $columnas[$c] = true; }
                }
                fputcsv($salida, array_merge([''], array_keys($columnas)), ';');
                foreach ($tabla as $etiqueta => $filas_t) {
                    $linea = [$etiqueta];
                    foreach (array_keys($columnas) as $c) { $linea[] = $filas_t[$c] ?? ''; }
                    fputcsv($salida, $linea, ';');
                }
            } elseif (is_array($tabla)) {
                foreach ($tabla as $k => $v) {
                    fputcsv($salida, [$k, is_scalar($v) ? $v : json_encode($v)], ';');
                }
            }
            fputcsv($salida, [], ';');
        }

        fputcsv($salida, ['Nota', $datos['nota_metodologica'] ?? ''], ';');
        fclose($salida);
        exit;
    }
}

// ── POST: generar ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'generar') {
    $tipo = input('tipo');
    $periodo = trim((string)input('periodo'));

    if (!isset($tipos[$tipo]) || $periodo === '') {
        $flash_local = ['error', 'Seleccione el tipo de reporte y el periodo.'];
    } else {
        $r = generar_reporte($tipo, $periodo, $usuario_actual['id']);

        if ($r['exito']) {
            $reporte = $r['reporte'] ?? null;
            $reporte_id = $r['reporte_id'];
            $flash_local = ['ok', 'Reporte generado y verificado (RN-10).'];
        } else {
            $flash_local = ['error', $r['motivo']];
        }
    }
}

$historial = db_select(
    'SELECT id, tipo, periodo, celdas_suprimidas, created_at FROM reportes_generados
      ORDER BY created_at DESC LIMIT 15') ?: [];

$title = 'Reportes';

ob_start();
?>
<div class="max-w-5xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-3">Generar reporte</h2>
            <form method="POST" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="generar">
                <label class="block text-sm text-gray-600" for="tipo">Reporte</label>
                <select name="tipo" id="tipo" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Seleccione…</option>
                    <?php foreach ($tipos as $clave => $etiqueta): ?>
                        <option value="<?= e($clave) ?>"><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="block text-sm text-gray-600" for="periodo">Periodo (p. ej. 2026, 2026-T3 o 2026-S2)</label>
                <input type="text" name="periodo" id="periodo" required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">
                    Generar con verificación RN-10</button>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-3">Reportes generados</h2>
            <ul class="text-sm space-y-2">
                <?php if (empty($historial)): ?>
                    <li class="text-gray-400">Aún no se han generado reportes.</li>
                <?php endif; ?>
                <?php foreach ($historial as $h): ?>
                    <li class="flex justify-between items-center border-b border-gray-100 pb-2">
                        <div>
                            <p class="font-medium"><?= e($h['tipo']) ?> · <?= e($h['periodo']) ?></p>
                            <p class="text-xs text-gray-500"><?= e(format_datetime($h['created_at'])) ?>
                                · <?= (int)$h['celdas_suprimidas'] ?> celdas suprimidas</p>
                        </div>
                        <a href="?page=reportes&descargar=<?= (int)$h['id'] ?>"
                           class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-xs">CSV</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <?php if ($reporte): ?>
        <div class="bg-white rounded-lg shadow p-4 mt-4">
            <h2 class="font-semibold text-gray-800 mb-1">Vista previa · <?= e($reporte['tipo']) ?> <?= e($reporte['periodo']) ?></h2>
            <p class="text-xs text-gray-500 mb-3">Destino: <?= e($reporte['destino'] ?? '') ?> ·
                Celdas suprimidas: <?= (int)($reporte['suprimidas'] ?? 0) ?> (umbral <?= (int)($reporte['umbral'] ?? 5) ?>)</p>

            <?php $secciones = $reporte['secciones'] ?? ['principal' => $reporte['tabla'] ?? []]; ?>
            <?php foreach ($secciones as $nombre => $tabla): ?>
                <h3 class="text-sm font-semibold text-gray-700 mt-3 mb-1"><?= e(str_replace('_', ' ', $nombre)) ?></h3>
                <?php if (is_array($tabla) && !empty($tabla) && is_array(reset($tabla))): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm border border-gray-200">
                            <?php
                            $columnas = [];
                            foreach ($tabla as $filas_t) { foreach (array_keys($filas_t) as $c) { $columnas[$c] = true; } }
                            ?>
                            <thead><tr class="bg-gray-50">
                                <th class="p-2 text-left border-b border-gray-200"></th>
                                <?php foreach (array_keys($columnas) as $c): ?>
                                    <th class="p-2 text-left border-b border-gray-200"><?= e(str_replace('_', ' ', $c)) ?></th>
                                <?php endforeach; ?>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($tabla as $etiqueta => $filas_t): ?>
                                <tr>
                                    <td class="p-2 border-b border-gray-100 font-medium"><?= e(str_replace('_', ' ', $etiqueta)) ?></td>
                                    <?php foreach (array_keys($columnas) as $c): ?>
                                        <td class="p-2 border-b border-gray-100"><?= e((string)($filas_t[$c] ?? '')) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif (is_array($tabla)): ?>
                    <ul class="text-sm text-gray-700">
                        <?php foreach ($tabla as $k => $v): ?>
                            <li><?= e(str_replace('_', ' ', (string)$k)) ?>: <?= e(is_scalar($v) ? (string)$v : json_encode($v)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>

            <p class="text-xs text-gray-400 mt-3"><?= e($reporte['nota_metodologica'] ?? '') ?></p>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
