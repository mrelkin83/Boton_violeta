<?php
/**
 * PESTAÑA · RELATO Y EVIDENCIA
 *
 * v3.1 §7.1 · Relato único en solo lectura (RN-03), evidencia con integridad,
 * carga de evidencia interna y ampliación del relato en dos momentos (§16 E2b).
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit('Acceso denegado'); }

$puede_relato = puede_ver_campo($usuario_actual['id'], $usuario_actual['rol'], $denuncia_id, 'descripcion');
$es_edheg = in_array($usuario_actual['rol'],
    ['coordinacion_edheg', 'coordinador', 'psicologo', 'trabajador_social', 'abogado'], true);

// ── POST: completar el relato una sola vez (RN-03 · §16 E2b) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'completar_relato' && $es_edheg && $puede_relato) {
    $r = completar_relato($denuncia_id, input('texto_adicional'), $usuario_actual['id']);
    $flash_local = $r['exito'] ? ['ok', 'Relato completado. No admite más modificaciones.'] : ['error', $r['error']];
    if ($r['exito']) {
        $denuncia = db_select_one('SELECT * FROM denuncias WHERE id = ? LIMIT 1', [$denuncia_id]);
    }
}

// ── POST: subir evidencia interna ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'subir_evidencia' && $es_edheg) {
    if (!empty($_FILES['evidencia']['name']) && ($_FILES['evidencia']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $ruta_relativa = upload_file($_FILES['evidencia'], 'denuncias/' . $denuncia_id);

        if ($ruta_relativa !== false) {
            $archivo_id = db_insert_simple('archivos', [
                'denuncia_id'      => $denuncia_id,
                'nombre_original'  => $_FILES['evidencia']['name'],
                'nombre_almacenado'=> basename($ruta_relativa),
                'ruta'             => $ruta_relativa,
                'tipo_mime'        => $_FILES['evidencia']['type'] ?? null,
                'tamano'           => (int)($_FILES['evidencia']['size'] ?? 0),
                'usuario_id'       => $usuario_actual['id'],
                'created_at'       => date('Y-m-d H:i:s'),
            ]);

            if ($archivo_id) {
                sellar_evidencia($archivo_id); // hash SHA-256 · cadena de custodia
                audit_log($usuario_actual['id'], 'evidencia_cargada', 'archivos', [
                    'rol' => $usuario_actual['rol'],
                ], (int)$archivo_id);
                $flash_local = ['ok', 'Evidencia cargada y sellada.'];
            } else {
                $flash_local = ['error', 'El archivo se guardó pero no pudo vincularse. Informe a soporte.'];
            }
        } else {
            $flash_local = ['error', 'No se pudo cargar el archivo (tipo o tamaño no admitido).'];
        }
    } else {
        $flash_local = ['error', 'Seleccione un archivo válido.'];
    }
}

$evidencias = evidencias_del_caso($denuncia_id, $usuario_actual['id'], $usuario_actual['rol']);
?>

<?php if ($flash_local): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
        <?= e($flash_local[1]) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Relato único (RN-03)</h3>
        <?php if ($puede_relato): ?>
            <div class="text-sm text-gray-700 whitespace-pre-wrap border border-gray-200 rounded-lg p-3 bg-gray-50 max-h-96 overflow-y-auto">
                <?= e($denuncia['descripcion']) ?>
            </div>
            <?php if (!empty($denuncia['testigos'])): ?>
                <p class="text-sm text-gray-500 mt-3">Personas testigo mencionadas:</p>
                <p class="text-sm text-gray-700"><?= e($denuncia['testigos']) ?></p>
            <?php endif; ?>

            <?php if ($es_edheg && empty($denuncia['relato_completado_at'])): ?>
                <form method="POST" class="mt-4 space-y-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="completar_relato">
                    <label class="block text-sm text-gray-600" for="texto_adicional">
                        Ampliación del encuentro inicial — se registra UNA sola vez (§16 E2)</label>
                    <textarea name="texto_adicional" id="texto_adicional" rows="4"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
                    <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">
                        Completar relato</button>
                </form>
            <?php elseif (!empty($denuncia['relato_completado_at'])): ?>
                <p class="text-xs text-gray-500 mt-3">Relato completado el <?= e(format_datetime($denuncia['relato_completado_at'])) ?>. No admite más modificaciones.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-sm text-gray-400">Su rol no tiene acceso al relato de este expediente.</p>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Evidencia · cadena de custodia</h3>
        <ul class="text-sm space-y-2 mb-4">
            <?php if (empty($evidencias)): ?>
                <li class="text-gray-400">Sin evidencia registrada.</li>
            <?php endif; ?>
            <?php foreach ($evidencias as $ev): ?>
                <li class="flex items-center justify-between border-b border-gray-100 pb-2">
                    <div>
                        <p class="font-medium"><?= e($ev['nombre_original']) ?></p>
                        <p class="text-xs text-gray-500"><?= e(format_bytes($ev['tamano'])) ?> · <?= e(format_date($ev['created_at'])) ?></p>
                    </div>
                    <?php if ($ev['hash_sha256']): ?>
                        <span class="px-2 py-0.5 rounded text-xs <?= (int)$ev['integridad_ok'] === 1 ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                            <?= (int)$ev['integridad_ok'] === 1 ? 'Íntegra' : 'Sellada' ?></span>
                    <?php else: ?>
                        <span class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-800">Sin sellar</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($es_edheg): ?>
            <form method="POST" enctype="multipart/form-data" class="space-y-2">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="subir_evidencia">
                <label class="block text-sm text-gray-600" for="evidencia">Adjuntar evidencia interna</label>
                <input type="file" name="evidencia" id="evidencia" class="w-full text-sm">
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Cargar y sellar</button>
            </form>
        <?php endif; ?>
    </div>
</div>
