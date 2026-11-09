<?php
/**
 * MÓDULO: MI PERFIL
 *
 * Cierra el hallazgo H-10: la ruta `?page=perfil` y el enlace del menú lateral
 * existían desde el principio, pero el módulo no. Quien pulsaba «Mi Perfil»
 * recibía un 404 sin plantilla.
 *
 * Permite a cada persona del equipo:
 *   · ver sus datos y su rol
 *   · cambiar su contraseña
 *   · consultar sus expedientes asignados
 *   · declarar impedimento sobre un expediente (RN-12)
 *
 * Nadie puede cambiar aquí su propio rol: eso es competencia de coordinación.
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_auth();

$usuario = auth_user();
$aviso      = null;
$aviso_tipo = 'info';

// ─────────────────────────────────────────────────────────────────────────────
// Procesamiento
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $accion = input('accion', '');

    // ── Cambio de contraseña ─────────────────────────────────────────────
    if ($accion === 'cambiar_contrasena') {
        $actual     = (string)($_POST['contrasena_actual'] ?? '');
        $nueva      = (string)($_POST['contrasena_nueva'] ?? '');
        $confirmar  = (string)($_POST['contrasena_confirmar'] ?? '');

        // El límite se consume SOLO al fallar la contraseña actual.
        //
        // `rate_limit_check()` incrementa el contador cada vez que se la llama.
        // Puesta al principio, gastaba un intento con cada envío del
        // formulario: cinco erratas en la confirmación —sin equivocarse nunca
        // en la contraseña actual— y la persona quedaba bloqueada un cuarto de
        // hora. Lo que hay que frenar es adivinar la contraseña, no escribir
        // mal la nueva.
        if (!verify_password($actual, $usuario['password'])) {
            $dentro_del_limite = rate_limit_check('cambio_contrasena:' . $usuario['id'], 5, 15);

            // Se audita: un intento fallido de cambio puede indicar que alguien
            // se sentó en una sesión abierta que no es suya
            audit_log($usuario['id'], 'cambio_contrasena_fallido', 'usuarios', [
                'motivo' => 'contrasena_actual_incorrecta',
            ], $usuario['id']);

            $aviso = $dentro_del_limite
                ? 'La contraseña actual no es correcta.'
                : 'Demasiados intentos fallidos. Espera unos minutos.';
            $aviso_tipo = 'error';

        } elseif (strlen($nueva) < 12) {
            $aviso = 'La contraseña nueva debe tener al menos 12 caracteres.';
            $aviso_tipo = 'error';

        } elseif ($nueva !== $confirmar) {
            $aviso = 'Las dos contraseñas nuevas no coinciden.';
            $aviso_tipo = 'error';

        } elseif (!preg_match('/[A-Za-z]/', $nueva) || !preg_match('/\d/', $nueva)) {
            $aviso = 'La contraseña debe combinar letras y números.';
            $aviso_tipo = 'error';

        } elseif (verify_password($nueva, $usuario['password'])) {
            $aviso = 'La contraseña nueva debe ser distinta de la actual.';
            $aviso_tipo = 'error';

        } else {
            $resultado = db_update_simple('usuarios', [
                'password' => hash_password($nueva),
            ], ['id' => $usuario['id']]);

            if ($resultado === false) {
                $aviso = 'No se pudo actualizar la contraseña. Intenta nuevamente.';
                $aviso_tipo = 'error';
            } else {
                rate_limit_clear('cambio_contrasena:' . $usuario['id']);

                audit_log($usuario['id'], 'contrasena_cambiada', 'usuarios', null, $usuario['id']);

                $aviso = 'Contraseña actualizada correctamente.';
                $aviso_tipo = 'exito';
            }
        }
    }

    // ── Actualización del nombre ─────────────────────────────────────────
    if ($accion === 'actualizar_datos') {
        $nombre = trim(input('nombre', ''));

        if (mb_strlen($nombre) < 3) {
            $aviso = 'El nombre debe tener al menos 3 caracteres.';
            $aviso_tipo = 'error';
        } else {
            db_update_simple('usuarios', ['nombre' => $nombre], ['id' => $usuario['id']]);

            // El nombre es un dato personal: la auditoría registra que hubo
            // cambio, no el valor (prohibición 1)
            audit_log($usuario['id'], 'perfil_actualizado', 'usuarios', null, $usuario['id']);

            $aviso = 'Datos actualizados.';
            $aviso_tipo = 'exito';
        }
    }

    // ── Firma digitalizada y cargo para oficios (migración 0029) ─────────
    if ($accion === 'guardar_firma') {
        $cargo = trim(input('cargo_oficio', ''));

        if ($cargo !== '') {
            db_update_simple('usuarios', ['cargo_oficio' => mb_substr($cargo, 0, 150)],
                             ['id' => $usuario['id']]);
        }

        if (!empty($_FILES['firma']['name'])) {
            $r = guardar_firma_usuario($usuario['id'], $_FILES['firma']);
            $aviso = $r['exito']
                ? 'Firma guardada. Aparecerá en los oficios que generes o proyectes.'
                : $r['error'];
            $aviso_tipo = $r['exito'] ? 'exito' : 'error';
        } elseif ($cargo !== '') {
            $aviso = 'Cargo actualizado.';
            $aviso_tipo = 'exito';
        } else {
            $aviso = 'Adjunta la imagen de tu firma o escribe tu cargo.';
            $aviso_tipo = 'error';
        }
    }

    // ── Declaración de impedimento · RN-12 ───────────────────────────────
    if ($accion === 'declarar_impedimento') {
        $expediente = input('denuncia_id', 0, 'int');
        $causal     = trim(input('causal', ''));

        if ($expediente > 0 && esta_asignado($usuario['id'], $expediente)) {
            if (declarar_impedimento($usuario['id'], $expediente, $causal !== '' ? $causal : null)) {
                $aviso = 'Impedimento declarado. El expediente ya no aparece en tu lista y '
                       . 'coordinación fue notificada para reasignarlo.';
                $aviso_tipo = 'exito';
            } else {
                $aviso = 'No se pudo registrar el impedimento.';
                $aviso_tipo = 'error';
            }
        } else {
            $aviso = 'No tienes asignado ese expediente.';
            $aviso_tipo = 'error';
        }
    }

    // Los datos en pantalla deben reflejar el cambio recién hecho, y también
    // los que pinta el layout: sin recargar, el menú lateral seguiría
    // mostrando el nombre anterior.
    $usuario = auth_refresh();
}

// ─────────────────────────────────────────────────────────────────────────────
// Datos para la vista
// ─────────────────────────────────────────────────────────────────────────────
$roles_legibles = [
    'administrador'      => 'Administración del sistema',
    'admin'              => 'Administración del sistema',
    'coordinacion_edheg' => 'Coordinación EDHEG',
    'coordinador'        => 'Coordinación',
    'psicologo'          => 'Psicología',
    'trabajador_social'  => 'Trabajo social',
    'abogado'            => 'Jurídica',
    'instructor'         => 'Instrucción',
    'decisor'            => 'Juzgamiento',
    'unidad_academica'   => 'Unidad académica',
    'talento_humano'     => 'Talento humano',
    'reportes'           => 'Reportes',
];

$funciones_legibles = [
    'instruccion'    => 'Instrucción',
    'juzgamiento'    => 'Juzgamiento',
    'coordinacion'   => 'Coordinación',
    'psicologia'     => 'Psicología',
    'trabajo_social' => 'Trabajo social',
    'juridica'       => 'Jurídica',
];

// Expedientes asignados, respetando la reserva por etapa (RN-02)
$restriccion = restriccion_listado_expedientes($usuario['id'], $usuario['rol']);

$asignados = db_select(
    "SELECT d.id, d.codigo, d.estado, d.etapa_procesal, a.funcion, a.created_at
       FROM asignaciones a
       JOIN denuncias d ON d.id = a.denuncia_id
      WHERE a.usuario_id = ? AND a.activa = 1 AND {$restriccion['sql']}
      ORDER BY a.created_at DESC",
    array_merge([$usuario['id']], $restriccion['params'])
) ?: [];

// Certificación vigente (RN-11)
$certificacion = db_select_one(
    'SELECT nombre_curso, vence_at FROM certificaciones_equipo
      WHERE usuario_id = ? ORDER BY vence_at DESC LIMIT 1',
    [$usuario['id']]
);

$dias_para_vencer = null;
if ($certificacion) {
    $dias_para_vencer = (int)floor(
        (strtotime($certificacion['vence_at']) - strtotime(date('Y-m-d'))) / 86400
    );
}

$clases_aviso = [
    'error' => 'bg-red-50 border-red-500 text-red-800',
    'exito' => 'bg-green-50 border-green-600 text-green-800',
    'info'  => 'bg-blue-50 border-blue-500 text-blue-800',
];

$title = 'Mi perfil';
ob_start();
?>

<?php if ($aviso): ?>
    <div class="border-l-4 p-4 rounded mb-6 <?= $clases_aviso[$aviso_tipo] ?? $clases_aviso['info'] ?>"
         role="status">
        <p><?= e($aviso) ?></p>
    </div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">

    <!-- ─── Columna principal ─────────────────────────────────────────── -->
    <div class="lg:col-span-2 space-y-6">

        <!-- Datos personales -->
        <section class="bg-white rounded-lg shadow p-6" aria-labelledby="titulo-datos">
            <h2 id="titulo-datos" class="text-lg font-bold text-gray-800 mb-4">Mis datos</h2>

            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="actualizar_datos">

                <div>
                    <label for="campo_nombre" class="block text-sm font-medium text-gray-700 mb-2">
                        Nombre
                    </label>
                    <input type="text" id="campo_nombre" name="nombre" required minlength="3"
                           value="<?= e($usuario['nombre']) ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
                </div>

                <div>
                    <span class="block text-sm font-medium text-gray-700 mb-2">Correo institucional</span>
                    <p class="px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-gray-700">
                        <?= e($usuario['email']) ?>
                    </p>
                    <p class="text-sm text-gray-600 mt-1">
                        El correo identifica tu cuenta y solo puede cambiarlo coordinación.
                    </p>
                </div>

                <div>
                    <span class="block text-sm font-medium text-gray-700 mb-2">Rol</span>
                    <p class="px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-gray-700">
                        <?= e($roles_legibles[$usuario['rol']] ?? $usuario['rol']) ?>
                    </p>
                    <p class="text-sm text-gray-600 mt-1">
                        Tu rol determina a qué expedientes accedes. Solo coordinación puede cambiarlo.
                    </p>
                </div>

                <button type="submit"
                        class="px-6 py-2 bg-purple-700 hover:bg-purple-800 text-white rounded-lg transition">
                    Guardar cambios
                </button>
            </form>
        </section>

        <!-- Firma para oficios (migración 0029 · formatos DBI) -->
        <section class="bg-white rounded-lg shadow p-6" aria-labelledby="titulo-firma">
            <h2 id="titulo-firma" class="text-lg font-bold text-gray-800 mb-1">Mi firma para oficios</h2>
            <p class="text-sm text-gray-600 mb-4">
                Los oficios de medida y de remisión llevan la firma de quien los suscribe y de quien
                los proyecta. Sube tu firma escaneada (PNG o JPG, máx. 500 KB, ideal sobre fondo
                blanco). Se guarda cifrada de la web: solo aparece dentro de documentos cuyo acceso
                queda auditado.
            </p>

            <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="guardar_firma">

                <div>
                    <label for="campo_cargo_oficio" class="block text-sm font-medium text-gray-700 mb-2">
                        Cargo con el que firmas o proyectas
                    </label>
                    <input type="text" id="campo_cargo_oficio" name="cargo_oficio" maxlength="150"
                           value="<?= e($usuario['cargo_oficio'] ?? '') ?>"
                           placeholder="p. ej. CPS Bienestar Universitario"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
                </div>

                <div>
                    <label for="campo_firma" class="block text-sm font-medium text-gray-700 mb-2">
                        Imagen de la firma
                    </label>
                    <input type="file" id="campo_firma" name="firma" accept=".png,.jpg,.jpeg"
                           class="w-full text-sm">
                    <?php if (!empty($usuario['firma_ruta'])): ?>
                        <?php $firma_previa = firma_datauri($usuario['id']); ?>
                        <?php if ($firma_previa): ?>
                            <div class="mt-3 border border-gray-200 rounded-lg p-3 bg-gray-50">
                                <p class="text-sm text-gray-600 mb-2">Firma registrada (subir otra la reemplaza):</p>
                                <img src="<?= $firma_previa ?>" alt="Firma registrada"
                                     style="max-height: 80px; max-width: 260px;">
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <button type="submit"
                        class="px-6 py-2 bg-purple-700 hover:bg-purple-800 text-white rounded-lg transition">
                    Guardar firma y cargo
                </button>
            </form>
        </section>

        <!-- Contraseña -->
        <section class="bg-white rounded-lg shadow p-6" aria-labelledby="titulo-contrasena">
            <h2 id="titulo-contrasena" class="text-lg font-bold text-gray-800 mb-4">Cambiar contraseña</h2>

            <form method="POST" action="" class="space-y-4" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="cambiar_contrasena">

                <div>
                    <label for="campo_actual" class="block text-sm font-medium text-gray-700 mb-2">
                        Contraseña actual
                    </label>
                    <input type="password" id="campo_actual" name="contrasena_actual" required
                           autocomplete="current-password"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
                </div>

                <div>
                    <label for="campo_nueva" class="block text-sm font-medium text-gray-700 mb-2">
                        Contraseña nueva
                    </label>
                    <input type="password" id="campo_nueva" name="contrasena_nueva" required minlength="12"
                           autocomplete="new-password" aria-describedby="ayuda_contrasena"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
                    <p id="ayuda_contrasena" class="text-sm text-gray-600 mt-1">
                        Mínimo 12 caracteres, combinando letras y números.
                    </p>
                </div>

                <div>
                    <label for="campo_confirmar" class="block text-sm font-medium text-gray-700 mb-2">
                        Repite la contraseña nueva
                    </label>
                    <input type="password" id="campo_confirmar" name="contrasena_confirmar" required minlength="12"
                           autocomplete="new-password"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
                </div>

                <button type="submit"
                        class="px-6 py-2 bg-purple-700 hover:bg-purple-800 text-white rounded-lg transition">
                    Cambiar contraseña
                </button>
            </form>
        </section>

        <!-- Expedientes asignados -->
        <section class="bg-white rounded-lg shadow p-6" aria-labelledby="titulo-asignados">
            <h2 id="titulo-asignados" class="text-lg font-bold text-gray-800 mb-1">
                Mis expedientes asignados
            </h2>
            <p class="text-sm text-gray-600 mb-4">
                Si tienes un conflicto de interés con alguno, declara el impedimento:
                el acceso se bloquea y coordinación lo reasigna (Ley 1437, art. 11).
            </p>

            <?php if (empty($asignados)): ?>
                <p class="text-gray-700 bg-gray-50 border border-gray-200 rounded-lg p-4">
                    No tienes expedientes asignados.
                </p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <caption class="sr-only">Expedientes asignados a mi cuenta</caption>
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Radicado</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Función</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Estado</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($asignados as $expediente): ?>
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                    <a href="?page=denuncia-ver&amp;id=<?= (int)$expediente['id'] ?>"
                                       class="text-purple-700 hover:text-purple-900 underline">
                                        <?= e($expediente['codigo']) ?>
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    <?= e($funciones_legibles[$expediente['funcion']] ?? $expediente['funcion']) ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    <?= e(ucfirst(str_replace('_', ' ', $expediente['estado']))) ?>
                                </td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="declarar_impedimento">
                                        <input type="hidden" name="denuncia_id" value="<?= (int)$expediente['id'] ?>">
                                        <input type="hidden" name="causal" value="">
                                        <button type="submit"
                                                onclick="return confirm('Declarar impedimento es irreversible: perderás el acceso a este expediente de forma permanente. ¿Continuar?')"
                                                class="text-sm text-red-700 hover:text-red-900 underline">
                                            Declarar impedimento
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- ─── Barra lateral ─────────────────────────────────────────────── -->
    <div class="space-y-6">

        <section class="bg-white rounded-lg shadow p-6" aria-labelledby="titulo-cuenta">
            <h2 id="titulo-cuenta" class="text-lg font-bold text-gray-800 mb-4">Cuenta</h2>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="font-medium text-gray-600">Estado</dt>
                    <dd class="mt-1">
                        <?php if ((int)$usuario['activo'] === 1): ?>
                            <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium">Activa</span>
                        <?php else: ?>
                            <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs font-medium">Inactiva</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Último acceso</dt>
                    <dd class="mt-1 text-gray-900">
                        <?= $usuario['ultimo_acceso'] ? e(format_datetime($usuario['ultimo_acceso'])) : 'Este es el primero' ?>
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Cuenta creada</dt>
                    <dd class="mt-1 text-gray-900"><?= e(format_date($usuario['created_at'])) ?></dd>
                </div>
            </dl>
        </section>

        <!-- Certificación · RN-11 -->
        <section class="bg-white rounded-lg shadow p-6" aria-labelledby="titulo-certificacion">
            <h2 id="titulo-certificacion" class="text-lg font-bold text-gray-800 mb-1">Certificación</h2>
            <p class="text-sm text-gray-600 mb-4">
                Acuerdo 002 de 2026, art. 20: vigencia de dos años.
            </p>

            <?php if ($certificacion === false || $certificacion === null): ?>
                <div class="bg-yellow-50 border-l-4 border-yellow-500 p-3 rounded">
                    <p class="text-sm text-yellow-900">
                        No hay certificación registrada. Comunícate con coordinación
                        para que la cargue.
                    </p>
                </div>
            <?php elseif ($dias_para_vencer !== null && $dias_para_vencer < 0): ?>
                <div class="bg-red-50 border-l-4 border-red-600 p-3 rounded">
                    <p class="text-sm text-red-900">
                        <strong>Vencida</strong> el <?= e(format_date($certificacion['vence_at'])) ?>.
                        No podrás recibir nuevas asignaciones.
                    </p>
                </div>
            <?php elseif ($dias_para_vencer !== null && $dias_para_vencer <= 60): ?>
                <div class="bg-yellow-50 border-l-4 border-yellow-500 p-3 rounded">
                    <p class="text-sm text-yellow-900">
                        Vence en <strong><?= (int)$dias_para_vencer ?> días</strong>
                        (<?= e(format_date($certificacion['vence_at'])) ?>).
                    </p>
                </div>
            <?php else: ?>
                <div class="bg-green-50 border-l-4 border-green-600 p-3 rounded">
                    <p class="text-sm text-green-900">
                        Vigente hasta el <?= e(format_date($certificacion['vence_at'])) ?>.
                    </p>
                </div>
            <?php endif; ?>
        </section>

        <section class="bg-purple-50 border-l-4 border-purple-700 rounded p-4">
            <h2 class="font-bold text-purple-900 mb-2 text-sm">Confidencialidad</h2>
            <p class="text-sm text-purple-900">
                Todo acceso a un expediente queda registrado: quién, cuándo y desde
                dónde. Es una garantía para las personas que confían su caso al
                sistema, y también para ti.
            </p>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
