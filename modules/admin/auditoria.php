<?php
/**
 * ADMIN · AUDITORÍA
 *
 * v3.1 §7.5 · Verificación de la cadena de hash bajo demanda (RN-13) y
 * búsqueda por usuario/acción/fecha. La columna `datos` de eventos de
 * expedientes NO se muestra: la consola ve metadatos, no contenido.
 */

require BASE_PATH . '/modules/admin/_guard.php';

$verificacion = null;

// ── POST: verificar cadena ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'verificar_cadena') {
    $verificacion = verificar_cadena_auditoria(0, 50000);

    audit_log($usuario_actual['id'], 'cadena_auditoria_verificada', 'auditoria', [
        'motivo' => $verificacion['integra'] ? 'integra' : 'con_fallos',
    ]);
}

// ── Búsqueda ────────────────────────────────────────────────────────────────
$f_accion = trim((string)input_get('f_accion', ''));
$f_usuario = input_get('f_usuario', 0, 'int');
$f_fecha = trim((string)input_get('f_fecha', ''));

$sql = 'SELECT a.id, a.accion, a.tabla, a.registro_id, a.usuario_id, u.nombre AS usuario_nombre,
               a.ip, a.created_at
          FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id WHERE 1=1';
$params = [];

if ($f_accion !== '') { $sql .= ' AND a.accion LIKE ?'; $params[] = '%' . $f_accion . '%'; }
if ($f_usuario > 0)   { $sql .= ' AND a.usuario_id = ?'; $params[] = $f_usuario; }
if ($f_fecha !== '')  { $sql .= ' AND DATE(a.created_at) = ?'; $params[] = $f_fecha; }

$eventos = db_select($sql . ' ORDER BY a.id DESC LIMIT 100', $params) ?: [];
$total = db_select_one('SELECT COUNT(*) AS total FROM auditoria');

$title = 'Auditoría';

ob_start();
?>
<div class="max-w-5xl mx-auto">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-2">Cadena de hash (RN-13)</h2>
            <p class="text-xs text-gray-500 mb-3"><?= number_format((int)($total['total'] ?? 0)) ?> registros,
                solo inserción. Cada uno sella el hash del anterior.</p>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="verificar_cadena">
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">
                    Verificar integridad ahora</button>
            </form>
            <?php if ($verificacion): ?>
                <div class="mt-3 p-2 rounded text-sm <?= $verificacion['integra'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                    <?= $verificacion['integra']
                        ? 'Cadena ÍNTEGRA · ' . (int)$verificacion['verificados'] . ' registros verificados.'
                        : 'FALLO en el registro #' . e((string)$verificacion['primer_fallo']) . ' — ' . e($verificacion['motivo'] ?? '') ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="lg:col-span-2 bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-2">Buscar eventos</h2>
            <form method="GET" class="flex flex-wrap gap-2 items-end">
                <input type="hidden" name="page" value="admin-auditoria">
                <input type="text" name="f_accion" value="<?= e($f_accion) ?>" placeholder="Acción"
                       class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                <input type="number" name="f_usuario" value="<?= $f_usuario ?: '' ?>" placeholder="ID usuario"
                       class="w-28 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                <input type="date" name="f_fecha" value="<?= e($f_fecha) ?>"
                       class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                <button type="submit" class="px-3 py-1 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm">Buscar</button>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left">
                <th class="p-2">#</th><th class="p-2">Acción</th><th class="p-2">Tabla</th>
                <th class="p-2">Usuario</th><th class="p-2">IP</th><th class="p-2">Fecha</th>
            </tr></thead>
            <tbody>
            <?php foreach ($eventos as $ev): ?>
                <tr class="border-b border-gray-100">
                    <td class="p-2 text-gray-400"><?= (int)$ev['id'] ?></td>
                    <td class="p-2"><?= e($ev['accion']) ?></td>
                    <td class="p-2 text-gray-500"><?= e($ev['tabla'] ?? '—') ?></td>
                    <td class="p-2"><?= e($ev['usuario_nombre'] ?? 'Sistema') ?></td>
                    <td class="p-2 font-mono text-xs text-gray-400"><?= e($ev['ip'] ?? '—') ?></td>
                    <td class="p-2 text-xs"><?= e(format_datetime($ev['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($eventos)): ?>
                <tr><td colspan="6" class="p-2 text-gray-400">Sin eventos con esos filtros.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <p class="text-xs text-gray-400 mt-2">La columna «datos» no se muestra: puede referir a expedientes
            y el rol administrador no accede a su contenido (§9.1).</p>
    </div>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
