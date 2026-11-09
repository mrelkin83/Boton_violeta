<?php
/**
 * MÓDULO: DENUNCIAS - VER DETALLE
 *
 * Muestra información detallada de una denuncia
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Requiere autenticación
require_auth();

// Obtener ID de la denuncia
$denuncia_id = input_get('id', 0, 'int');

if (!$denuncia_id) {
    redirect_with_message(url('?page=denuncias'), 'Denuncia no encontrada.', 'error');
}

// Obtener denuncia con información relacionada
$query = "SELECT d.*, u.nombre as usuario_nombre, u.email as usuario_email
          FROM denuncias d
          LEFT JOIN usuarios u ON d.usuario_id = u.id
          WHERE d.id = ?
          LIMIT 1";

$denuncia = db_select_one($query, [$denuncia_id]);

if (!$denuncia) {
    redirect_with_message(url('?page=denuncias'), 'Denuncia no encontrada.', 'error');
}

// ────────────────────────────────────────────────────────────────────────────
// RN-02 · RN-08 · RN-12 — control de acceso al expediente
//
// El mensaje es el mismo que cuando la denuncia no existe: decir «no tienes
// permiso» confirmaría que ese expediente existe, y su sola existencia está
// bajo reserva (Ac. 003 art. 47).
// ────────────────────────────────────────────────────────────────────────────
$usuario_actual = auth_user();

if (!puede_ver_expediente($usuario_actual['id'], $usuario_actual['rol'], $denuncia_id)) {
    audit_log($usuario_actual['id'], 'acceso_expediente_denegado', 'denuncias', [
        'rol' => $usuario_actual['rol'],
    ], $denuncia_id);

    redirect_with_message(url('?page=denuncias'), 'Denuncia no encontrada.', 'error');
}

// RN-13 · se audita también la LECTURA del expediente, no solo la escritura
audit_log($usuario_actual['id'], 'expediente_consultado', 'denuncias', [
    'rol'   => $usuario_actual['rol'],
    'etapa' => $denuncia['etapa_procesal'] ?? null,
], $denuncia_id);

// La verificación CSRF se aplica a cualquier POST, no solo al de cambio de estado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
}

// ────────────────────────────────────────────────────────────────────────────
// Datos de contacto de la persona denunciante (Fase 2)
//
// Se muestran enmascarados. Revelarlos es una acción deliberada y queda
// auditada: quién los consultó, cuándo y desde dónde. Sin este registro, el
// cifrado en reposo protege frente a una copia de la base de datos pero no
// frente a la consulta indebida desde dentro.
// ────────────────────────────────────────────────────────────────────────────
$contacto_correo   = descifrar_contacto($denuncia['denunciante_correo_cifrado'] ?? null);
$contacto_telefono = descifrar_contacto($denuncia['denunciante_telefono_cifrado'] ?? null);

// Denuncias anteriores a la migración 0009 conservan el campo concatenado
if ($contacto_correo === null && $contacto_telefono === null && !empty($denuncia['denunciante_contacto'])) {
    $contacto_heredado = $denuncia['denunciante_contacto'];
}

$contacto_revelado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'revelar_contacto') {
    $contacto_revelado = true;

    // El rol se toma del usuario ya cargado, no de una clave suelta de sesión:
    // si `user_rol` no existía, esta auditoría —la de revelar datos de contacto,
    // justo la que más importa— quedaba registrada con el rol en blanco.
    audit_log($usuario_actual['id'], 'contacto_consultado', 'denuncias', [
        'rol' => $usuario_actual['rol'],
    ], $denuncia_id);
}

// Actualizar estado (si se envía formulario)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_any_role(['admin', 'coordinador'])) {
    $nuevo_estado  = input('estado');
    $observaciones = input('observaciones');
    $estado_anterior = $denuncia['estado'];

    $estados_validos = ['nueva', 'en_proceso', 'resuelta', 'archivada'];

    if ($nuevo_estado && in_array($nuevo_estado, $estados_validos, true)) {
        try {
            db_begin_transaction();

            $result = db_update_simple('denuncias', [
                'estado'     => $nuevo_estado,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $denuncia_id]);

            if ($result === false) {
                throw new RuntimeException('No se pudo actualizar el estado');
            }

            // ────────────────────────────────────────────────────────────
            // H-04: la observación se persiste en `seguimientos`.
            // Antes solo viajaba al detalle de auditoría y se perdía, por lo
            // que el historial que ve la persona denunciante quedaba vacío.
            // ────────────────────────────────────────────────────────────
            if ($observaciones !== null && trim($observaciones) !== '') {
                $seguimiento_id = db_insert_simple('seguimientos', [
                    'denuncia_id' => $denuncia_id,
                    'usuario_id'  => auth_user_id(),
                    'tipo'        => 'actualizacion',
                    'contenido'   => trim($observaciones),
                    'privado'     => 0, // visible en el seguimiento público
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);

                if (!$seguimiento_id) {
                    throw new RuntimeException('No se pudo registrar el seguimiento');
                }
            }

            db_commit();

            // Auditoría: sin el texto de la observación (prohibición 1)
            audit_log(auth_user_id(), 'estado_actualizado', 'denuncias', [
                'estado_anterior' => $estado_anterior,
                'estado_nuevo'    => $nuevo_estado,
                'con_seguimiento' => ($observaciones !== null && trim($observaciones) !== ''),
            ], $denuncia_id);

            redirect_with_message(url('?page=denuncia-ver&id=' . $denuncia_id), 'Estado actualizado correctamente.', 'success');

        } catch (Throwable $e) {
            if (db_in_transaction()) {
                db_rollback();
            }
            log_error('Fallo al actualizar estado de denuncia: ' . $e->getMessage(), 'error');
            set_flash('No se pudo actualizar el estado. Intente nuevamente.', 'error');
        }
    }
}

/*
 * Estados disponibles
 *
 * Se guarda la clase CSS completa, no el nombre del color. Construir la clase
 * por concatenación —interpolando el color dentro de «bg-…-100»— tiene dos
 * problemas: un generador de CSS que recorta por clases usadas nunca ve esa
 * cadena y la omite, y el valor termina impreso sin escapar dentro de un
 * atributo.
 *
 * Este comentario va en bloque y no con //, porque escribir la etiqueta de
 * cierre de PHP dentro de un comentario de línea la cierra de verdad: el
 * analizador vuelve a modo HTML, el resto del archivo se imprime como texto y
 * `php -l` no protesta, porque sigue siendo PHP válido.
 */
$estados = [
    'nueva'      => ['label' => 'Nueva',      'clases' => 'bg-blue-100 text-blue-800'],
    'en_proceso' => ['label' => 'En Proceso', 'clases' => 'bg-yellow-100 text-yellow-800'],
    'resuelta'   => ['label' => 'Resuelta',   'clases' => 'bg-green-100 text-green-800'],
    'archivada'  => ['label' => 'Archivada',  'clases' => 'bg-gray-100 text-gray-800'],
];

$estado_actual = $estados[$denuncia['estado']] ?? ['label' => 'Desconocido', 'clases' => 'bg-gray-100 text-gray-800'];

// Tipos de violencia
$tipos_violencia = [
    'fisica' => 'Violencia Física',
    'psicologica' => 'Violencia Psicológica',
    'sexual' => 'Violencia Sexual',
    'economica' => 'Violencia Económica',
    'verbal' => 'Violencia Verbal',
    'otro' => 'Otro',
];

// Evidencias del expediente. La función aplica ella misma el control de
// acceso, así que no hay forma de listarlas sin haberlo pasado.
$evidencias = evidencias_del_caso($denuncia_id, $usuario_actual['id'], $usuario_actual['rol']);

$title = 'Detalle de Denuncia - ' . e($denuncia['codigo']);
ob_start();
?>

<!-- Breadcrumb -->
<nav class="mb-6 text-sm">
    <a href="?page=dashboard" class="text-purple-600 hover:text-purple-800">Dashboard</a>
    <span class="mx-2 text-gray-400">/</span>
    <a href="?page=denuncias" class="text-purple-600 hover:text-purple-800">Denuncias</a>
    <span class="mx-2 text-gray-400">/</span>
    <span class="text-gray-600"><?= e($denuncia['codigo']) ?></span>
</nav>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Información Principal -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800"><?= e($denuncia['codigo']) ?></h2>
                    <p class="text-gray-600 mt-1"><?= e($tipos_violencia[$denuncia['tipo_violencia']] ?? $denuncia['tipo_violencia']) ?></p>
                </div>
                <span class="px-4 py-2 <?= e($estado_actual['clases']) ?> rounded-full text-sm font-medium">
                    <?= e($estado_actual['label']) ?>
                </span>
            </div>

            <!-- Descripción -->
            <div class="border-t pt-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Descripción de los Hechos</h3>
                <p class="text-gray-600 whitespace-pre-line"><?= e($denuncia['descripcion']) ?></p>
            </div>
        </div>

        <!-- Detalles -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Detalles</h3>
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500">Fecha de los Hechos</dt>
                    <dd class="mt-1 text-sm text-gray-900"><?= format_date($denuncia['fecha_hechos']) ?></dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-gray-500">Lugar</dt>
                    <dd class="mt-1 text-sm text-gray-900"><?= e($denuncia['lugar_hechos'] ?: 'No especificado') ?></dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-gray-500">Fecha de Registro</dt>
                    <dd class="mt-1 text-sm text-gray-900"><?= format_datetime($denuncia['created_at']) ?></dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-gray-500">Última Actualización</dt>
                    <dd class="mt-1 text-sm text-gray-900"><?= $denuncia['updated_at'] ? format_datetime($denuncia['updated_at']) : 'N/A' ?></dd>
                </div>
            </dl>
        </div>

        <!-- Actualizar Estado (solo admin/coordinador) -->
        <?php if (has_any_role(['admin', 'coordinador'])): ?>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Actualizar Estado</h3>
            <form method="POST" action="">
                <?= csrf_field() ?>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nuevo Estado</label>
                        <select name="estado" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
                            <?php foreach ($estados as $key => $info): ?>
                                <option value="<?= e($key) ?>" <?= $denuncia['estado'] === $key ? 'selected' : '' ?>>
                                    <?= e($info['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition">
                            Actualizar
                        </button>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Observaciones</label>
                    <textarea
                        name="observaciones"
                        rows="3"
                        placeholder="Agregar nota sobre el cambio de estado..."
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600"
                    ></textarea>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Evidencias -->
        <!--
            Los archivos podían adjuntarse desde el formulario público y quedaban
            sellados con su hash, pero ninguna pantalla los mostraba: el equipo no
            tenía forma de saber que existían. Aquí se listan con su estado de
            integridad, que es lo que hace útil el sello.
        -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-1">Evidencias</h3>
            <p class="text-sm text-gray-600 mb-4">
                Cada archivo lleva un sello SHA-256 tomado al cargarlo. Si el
                contenido cambia después, el sello deja de coincidir.
            </p>

            <?php if (empty($evidencias)): ?>
                <p class="text-gray-700 bg-gray-50 border border-gray-200 rounded-lg p-4">
                    No hay archivos adjuntos a este expediente.
                </p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <caption class="sr-only">Archivos adjuntos al expediente</caption>
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Archivo</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Tamaño</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Cargado</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Integridad</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($evidencias as $evidencia): ?>
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900"><?= e($evidencia['nombre_original']) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-700"><?= e(format_bytes($evidencia['tamano'])) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-700"><?= e(format_datetime($evidencia['created_at'])) ?></td>
                                <td class="px-4 py-3 text-sm">
                                    <?php if ($evidencia['hash_sha256'] === null): ?>
                                        <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded-full text-xs font-medium">Sin sellar</span>
                                    <?php elseif ((int)$evidencia['integridad_ok'] === 1): ?>
                                        <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium">Verificada</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs font-medium">Sello no coincide</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="space-y-6">
        <!-- Contacto de la persona denunciante -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-1">Contacto</h3>
            <p class="text-xs text-gray-500 mb-4">Cifrado en reposo · toda consulta queda auditada</p>

            <div class="space-y-3">
                <div>
                    <p class="text-sm font-medium text-gray-500">Persona denunciante</p>
                    <p class="text-sm text-gray-900 mt-1">
                        <?= e($denuncia['denunciante_nombre'] ?: 'No registrada') ?>
                    </p>
                </div>

                <?php if (isset($contacto_heredado)): ?>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Contacto (formato heredado)</p>
                        <p class="text-sm text-gray-900 mt-1"><?= e($contacto_heredado) ?></p>
                        <p class="text-xs text-yellow-700 mt-1">Sin cifrar: pendiente de la migración 0009.</p>
                    </div>
                <?php else: ?>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Correo</p>
                        <p class="text-sm text-gray-900 mt-1 font-mono">
                            <?php if ($contacto_correo === null): ?>
                                <span class="text-gray-400">No registrado</span>
                            <?php elseif ($contacto_revelado): ?>
                                <?= e($contacto_correo) ?>
                            <?php else: ?>
                                <?= e(enmascarar_contacto($contacto_correo, 'correo')) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Teléfono</p>
                        <p class="text-sm text-gray-900 mt-1 font-mono">
                            <?php if ($contacto_telefono === null): ?>
                                <span class="text-gray-400">No registrado</span>
                            <?php elseif ($contacto_revelado): ?>
                                <?= e($contacto_telefono) ?>
                            <?php else: ?>
                                <?= e(enmascarar_contacto($contacto_telefono, 'telefono')) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Canal preferido</p>
                        <p class="text-sm text-gray-900 mt-1">
                            <?= ($denuncia['canal_preferido'] ?? 'correo') === 'sms' ? 'Mensaje de texto' : 'Correo electrónico' ?>
                        </p>
                    </div>

                    <?php if (!$contacto_revelado && ($contacto_correo !== null || $contacto_telefono !== null)): ?>
                    <form method="POST" action="" class="pt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="revelar_contacto">
                        <button type="submit" class="w-full px-4 py-2 bg-purple-100 hover:bg-purple-200 text-purple-700 text-sm rounded-lg transition">
                            Ver datos de contacto
                        </button>
                    </form>
                    <?php else: ?>
                        <?php if ($contacto_revelado): ?>
                        <p class="text-xs text-purple-700 pt-2">Consulta registrada en la auditoría.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Origen del registro -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Origen del registro</h3>
            <div class="space-y-3">
                <div>
                    <p class="text-sm font-medium text-gray-500">Canal</p>
                    <p class="text-sm text-gray-900 mt-1">
                        <?= $denuncia['usuario_id'] ? 'Radicación interna' : 'Botón Violeta (canal público)' ?>
                    </p>
                </div>
                <?php if ($denuncia['usuario_id']): ?>
                <div>
                    <p class="text-sm font-medium text-gray-500">Registrado por</p>
                    <p class="text-sm text-gray-900 mt-1"><?= e($denuncia['usuario_nombre'] ?? '—') ?></p>
                    <p class="text-xs text-gray-500"><?= e($denuncia['usuario_email'] ?? '') ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Acciones -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Acciones</h3>
            <div class="space-y-2">
                <a href="?page=denuncias" class="block w-full px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-center rounded-lg transition">
                    Volver a Lista
                </a>

                <a href="?page=denuncia-imprimir&amp;id=<?= (int)$denuncia['id'] ?>" target="_blank" rel="noopener"
                   class="block w-full px-4 py-2 bg-purple-100 hover:bg-purple-200 text-purple-800 text-center rounded-lg transition">
                    Imprimir expediente
                </a>
                <p class="text-xs text-gray-600">
                    La impresión queda registrada en la auditoría y lleva marca de agua
                    con tu nombre y la fecha.
                </p>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
?>
