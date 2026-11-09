<?php
/**
 * PESTAÑA · LÍNEA DE TIEMPO
 *
 * v3.1 §7.1 · Seguimientos no privados + auditoría del caso en orden
 * cronológico. H-05: se lee `contenido` y se respeta el filtro `privado`
 * según el rol.
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit('Acceso denegado'); }

$es_coordinacion = in_array($usuario_actual['rol'], ['coordinacion_edheg', 'coordinador'], true);

// ── POST: añadir seguimiento ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'seguimiento') {
    $contenido = trim((string)input('contenido'));

    if ($contenido === '') {
        $flash_local = ['error', 'El seguimiento no puede estar vacío.'];
    } else {
        $id = db_insert_simple('seguimientos', [
            'denuncia_id' => $denuncia_id,
            'usuario_id'  => $usuario_actual['id'],
            'tipo'        => 'actualizacion',
            'contenido'   => $contenido,
            'privado'     => input('privado') === '1' ? 1 : 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        if ($id) {
            audit_log($usuario_actual['id'], 'seguimiento_registrado', 'seguimientos', [
                'rol' => $usuario_actual['rol'],
            ], $denuncia_id);
        }
        $flash_local = $id ? ['ok', 'Seguimiento registrado.'] : ['error', 'No se pudo registrar.'];
    }
}

// El equipo ve todos los seguimientos; la línea marca cuáles son visibles
// para la persona consultante (privado = 0)
$seguimientos = db_select(
    'SELECT s.*, u.nombre AS autor FROM seguimientos s
      LEFT JOIN usuarios u ON u.id = s.usuario_id
     WHERE s.denuncia_id = ? ORDER BY s.created_at DESC LIMIT 100', [$denuncia_id]) ?: [];

// Auditoría del caso: solo la coordinación la ve completa, y sin `datos`
$eventos_auditoria = $es_coordinacion
    ? (db_select(
        'SELECT accion, created_at, usuario_id FROM auditoria
          WHERE tabla = ? AND registro_id = ? ORDER BY created_at DESC LIMIT 50',
        ['denuncias', $denuncia_id]) ?: [])
    : [];
?>

<?php if ($flash_local): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
        <?= e($flash_local[1]) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Seguimientos</h3>
        <form method="POST" class="space-y-2 mb-4">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="seguimiento">
            <textarea name="contenido" rows="2" required placeholder="Nuevo seguimiento…"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            <div class="flex items-center justify-between">
                <label class="flex items-center space-x-2 text-sm text-gray-600">
                    <input type="checkbox" name="privado" value="1" checked>
                    <span>Privado (no visible en la consulta pública)</span>
                </label>
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Registrar</button>
            </div>
        </form>
        <ul class="text-sm space-y-3">
            <?php foreach ($seguimientos as $s): ?>
                <li class="border-l-4 <?= (int)$s['privado'] === 1 ? 'border-gray-300' : 'border-purple-400' ?> pl-3">
                    <p class="text-xs text-gray-500"><?= e(format_datetime($s['created_at'])) ?>
                        · <?= e($s['autor'] ?? 'Sistema') ?>
                        <?= (int)$s['privado'] === 0 ? '· visible al consultar' : '' ?></p>
                    <p><?= e($s['contenido']) ?></p>
                </li>
            <?php endforeach; ?>
            <?php if (empty($seguimientos)): ?>
                <li class="text-gray-400">Sin seguimientos.</li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Auditoría del caso (RN-13)</h3>
        <?php if (!$es_coordinacion): ?>
            <p class="text-sm text-gray-400">La traza de auditoría completa la consulta la coordinación.</p>
        <?php else: ?>
            <ul class="text-xs space-y-2">
                <?php foreach ($eventos_auditoria as $ev): ?>
                    <li class="flex justify-between border-b border-gray-100 pb-1">
                        <span><?= e(str_replace('_', ' ', $ev['accion'])) ?></span>
                        <span class="text-gray-500"><?= e(format_datetime($ev['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($eventos_auditoria)): ?>
                    <li class="text-gray-400">Sin eventos.</li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
