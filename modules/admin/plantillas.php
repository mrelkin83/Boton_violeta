<?php
/**
 * ADMIN · PLANTILLAS DE NOTIFICACIÓN
 *
 * v3.1 §7.5 · El documento pedía un editor que rechace marcadores fuera de la
 * lista blanca RN-09. La implementación es aún más estricta: las plantillas
 * viven en el código (includes/notificaciones.php) y NO son editables desde
 * la interfaz — no existe forma de que un texto escrito por una persona
 * llegue a un correo saliente. Este panel las muestra junto con la lista
 * blanca, para verificación.
 */

require BASE_PATH . '/modules/admin/_guard.php';

$title = 'Plantillas de notificación';

ob_start();
?>
<div class="max-w-4xl mx-auto">

    <div class="mb-4 p-3 rounded-lg bg-purple-50 border border-purple-200 text-purple-900 text-sm" role="note">
        Las plantillas son <strong>fijas y viven en el código</strong>: es la garantía dura de RN-09.
        Cambiarlas exige una entrega de código revisada, no un formulario. Este panel es de solo
        lectura para verificación.
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-3">Plantillas registradas</h2>
            <ul class="text-sm space-y-2">
                <?php foreach (PLANTILLAS_NOTIFICACION as $clave => $nombre): ?>
                    <li class="flex justify-between border-b border-gray-100 pb-1">
                        <span class="font-mono text-xs text-gray-500"><?= e($clave) ?></span>
                        <span><?= e($nombre) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-800 mb-1">Lista blanca RN-09</h2>
            <p class="text-xs text-gray-500 mb-3">Únicas claves que pueden viajar en una notificación.
                Cualquier otra clave, o un texto de más de 120 caracteres, se rechaza y se audita.</p>
            <ul class="text-sm space-y-1">
                <?php foreach (NOTIFICACION_CLAVES_PERMITIDAS as $clave): ?>
                    <li class="font-mono text-xs bg-gray-50 rounded px-2 py-1 inline-block mr-1"><?= e($clave) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="text-xs text-gray-400 mt-4">Prohibición 1 del CLAUDE.md: ningún correo transporta
                relato, nombres, tipo de violencia, lugar ni adjuntos. Solo radicado, banda de riesgo,
                plazo y enlace autenticado.</p>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
