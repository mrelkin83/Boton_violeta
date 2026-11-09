<?php
/**
 * ADMIN · CONFIGURACIÓN OPERATIVA
 *
 * v3.1 §7.5 · Claves de la migración 0023 con historial vía auditoría.
 * G-15: desactivar RN14_BLOQUEA_ETAPA o ENCUENTRO_INICIAL_AUTOMATICO se
 * advierte y queda auditado con valor anterior y nuevo.
 */

require BASE_PATH . '/modules/admin/_guard.php';

const CLAVES_G15 = ['RN14_BLOQUEA_ETAPA', 'ENCUENTRO_INICIAL_AUTOMATICO'];
const CLAVES_G8  = ['UMBRAL_SUPRESION'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'guardar') {
    $clave = trim((string)input('clave'));
    $valor = trim((string)input('valor'));

    if ($clave === '' || $valor === '') {
        $flash_local = ['error', 'Clave y valor son obligatorios.'];
    } else {
        $ok = config_establecer($clave, $valor, $usuario_actual['id']);

        if ($ok) {
            $nota = '';
            if (in_array($clave, CLAVES_G15, true)) {
                $nota = ' Cambio bajo compuerta G-15: quedó auditado quién y cuándo.';
            } elseif (in_array($clave, CLAVES_G8, true)) {
                $nota = ' Cambio bajo compuerta G-08: el umbral de supresión protege identidades.';
            }
            $flash_local = ['ok', 'Configuración guardada.' . $nota];
        } else {
            $flash_local = ['error', 'No se pudo guardar (¿la clave existe?).'];
        }
    }
}

$claves = db_select('SELECT * FROM configuracion ORDER BY clave') ?: [];
$cambios = db_select(
    "SELECT a.datos, a.created_at, u.nombre FROM auditoria a
      LEFT JOIN usuarios u ON u.id = a.usuario_id
     WHERE a.accion = 'configuracion_cambiada' ORDER BY a.id DESC LIMIT 15") ?: [];

$title = 'Configuración';

ob_start();
?>
<div class="max-w-4xl mx-auto">

    <?php if ($flash_local): ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
            <?= e($flash_local[1]) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-4 mb-4 overflow-x-auto">
        <h2 class="font-semibold text-gray-800 mb-3">Claves operativas (migración 0023)</h2>
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left">
                <th class="p-2">Clave</th><th class="p-2">Valor</th><th class="p-2">Descripción</th><th class="p-2"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($claves as $c): ?>
                <tr class="border-b border-gray-100 <?= in_array($c['clave'], CLAVES_G15, true) ? 'bg-yellow-50' : '' ?>">
                    <td class="p-2 font-mono text-xs"><?= e($c['clave']) ?>
                        <?php if (in_array($c['clave'], CLAVES_G15, true)): ?>
                            <span class="text-yellow-700 text-xs block">⚠ G-15</span>
                        <?php elseif (in_array($c['clave'], CLAVES_G8, true)): ?>
                            <span class="text-yellow-700 text-xs block">⚠ G-08</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-2">
                        <form method="POST" class="flex gap-1"
                              <?= in_array($c['clave'], array_merge(CLAVES_G15, CLAVES_G8), true)
                                  ? 'onsubmit="return confirm(\'Esta clave está bajo compuerta de supervisión. El cambio quedará auditado. ¿Continuar?\');"' : '' ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="guardar">
                            <input type="hidden" name="clave" value="<?= e($c['clave']) ?>">
                            <input type="text" name="valor" value="<?= e($c['valor']) ?>"
                                   class="w-40 border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <button type="submit" class="text-purple-700 hover:underline text-xs">Guardar</button>
                        </form>
                    </td>
                    <td class="p-2 text-xs text-gray-500"><?= e($c['descripcion'] ?? '') ?></td>
                    <td class="p-2 text-xs text-gray-400"><?= e($c['tipo']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-800 mb-3">Últimos cambios (auditados)</h2>
        <ul class="text-xs space-y-1">
            <?php foreach ($cambios as $c): ?>
                <?php $d = json_decode($c['datos'] ?? '', true) ?: []; ?>
                <li class="flex justify-between border-b border-gray-100 pb-1">
                    <span><?= e($d['clave'] ?? '?') ?>: <?= e($d['valor_anterior'] ?? '') ?> → <?= e($d['valor_nuevo'] ?? '') ?>
                        · <?= e($c['nombre'] ?? 'Sistema') ?></span>
                    <span class="text-gray-500"><?= e(format_datetime($c['created_at'])) ?></span>
                </li>
            <?php endforeach; ?>
            <?php if (empty($cambios)): ?><li class="text-gray-400">Sin cambios registrados.</li><?php endif; ?>
        </ul>
    </div>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
