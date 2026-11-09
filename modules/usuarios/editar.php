<?php
/**
 * MÓDULO: USUARIOS · CREAR Y EDITAR
 *
 * Cierra la mitad del hallazgo H-19. El botón «Nuevo Usuario» era un
 * `alert('Próximamente')` sobre funciones —`create_user()`, `update_user()`—
 * que existían desde la primera versión y estaban probadas. Solo faltaba la
 * pantalla.
 *
 * Restricciones que impone este módulo:
 *
 *   · Solo `admin` y `administrador` crean o editan cuentas.
 *   · Nadie puede cambiar su propio rol ni desactivarse a sí mismo: si la
 *     única cuenta administradora se degrada por error, nadie puede
 *     recuperarla desde la interfaz.
 *   · La contraseña inicial se genera y se muestra una sola vez.
 *   · Se comprueba que quede al menos una cuenta administradora activa.
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_any_role(['admin', 'administrador']);

$actual = auth_user();

$usuario_id = input_get('id', 0, 'int');
$es_nuevo   = $usuario_id === 0;

$usuario = null;

if (!$es_nuevo) {
    $usuario = db_select_one('SELECT * FROM usuarios WHERE id = ? LIMIT 1', [$usuario_id]);

    if (!$usuario) {
        redirect_with_message(url('?page=usuarios'), 'La cuenta no existe.', 'error');
    }
}

$roles = [
    'coordinacion_edheg' => 'Coordinación EDHEG · atiende expedientes',
    'coordinador'        => 'Coordinación · atiende expedientes',
    'psicologo'          => 'Psicología · expedientes asignados',
    'trabajador_social'  => 'Trabajo social · expedientes asignados',
    'abogado'            => 'Jurídica · expedientes asignados',
    'instructor'         => 'Instrucción · nunca falla (RN-01)',
    'decisor'            => 'Juzgamiento · nunca instruye (RN-01)',
    'unidad_academica'   => 'Unidad académica · solo órdenes de medida (RN-08)',
    'talento_humano'     => 'Talento humano · sin acceso a expedientes',
    'reportes'           => 'Reportes · solo agregados',
    'comision_seguimiento' => 'Comisión de Seguimiento · solo indicadores (Res. 142/2026 art. 13)',
    'administrador'      => 'Administración · SIN acceso a expedientes',
];

$aviso            = null;
$aviso_tipo       = 'info';
$contrasena_nueva = null;

// ─────────────────────────────────────────────────────────────────────────────
// Procesamiento
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $nombre    = trim(input('nombre', ''));
    $correo    = input('email', '', 'email');
    $rol       = input('rol', '');
    $activo    = input('activo', 0, 'int') === 1 ? 1 : 0;
    $unidad_id = input('unidad_id', 0, 'int');

    // v3.1 · Fase C · RN-15: doble correo obligatorio para el equipo
    $correo_personal = trim(input('correo_personal', ''));

    $errores = [];

    if (mb_strlen($nombre) < 3) {
        $errores[] = 'El nombre debe tener al menos 3 caracteres.';
    }
    if ($correo === null || $correo === '') {
        $errores[] = 'El correo no es válido.';
    }

    // RN-15: «Ambos son obligatorios al crear la cuenta». En una cuenta
    // existente, el dominio solo se exige si el correo institucional CAMBIA
    // (las cuentas heredadas no quedan ineditables), y el personal se
    // conserva si el formulario no lo reenvía.
    $dominio_inst = (string)config_valor('DOMINIO_INSTITUCIONAL', 'udistrital.edu.co');
    $correo_cambia = $es_nuevo || ($correo !== ($usuario['email'] ?? null));

    if ($correo && $correo_cambia && $dominio_inst !== ''
        && !str_ends_with(strtolower($correo), '@' . strtolower($dominio_inst))) {
        $errores[] = "RN-15: el correo institucional debe pertenecer al dominio @{$dominio_inst}.";
    }
    if ($correo_personal !== '') {
        if (!valid_email($correo_personal)) {
            $errores[] = 'El correo personal no es válido.';
        } elseif ($dominio_inst !== ''
            && str_ends_with(strtolower($correo_personal), '@' . strtolower($dominio_inst))) {
            $errores[] = 'RN-15: el correo personal no puede ser una cuenta institucional.';
        }
    } elseif ($es_nuevo) {
        $errores[] = 'RN-15: el correo personal es obligatorio al crear la cuenta '
                   . '(las notificaciones al equipo van a ambos correos).';
    }
    if (!isset($roles[$rol])) {
        $errores[] = 'El rol seleccionado no existe.';
    }

    // RN-08 · el rol de unidad académica no tiene sentido sin unidad asignada
    if ($rol === 'unidad_academica' && $unidad_id === 0) {
        $errores[] = 'Una cuenta de unidad académica necesita una unidad asignada: '
                   . 'sin ella no vería ninguna orden de medida.';
    }

    // Nadie se degrada ni se desactiva a sí mismo
    if (!$es_nuevo && (int)$usuario['id'] === (int)$actual['id']) {
        if ($rol !== $usuario['rol']) {
            $errores[] = 'No puedes cambiar tu propio rol. Pídeselo a otra persona '
                       . 'con permisos de administración.';
        }
        if ($activo === 0) {
            $errores[] = 'No puedes desactivar tu propia cuenta.';
        }
    }

    // Debe quedar siempre una cuenta administradora activa
    if (!$es_nuevo && in_array($usuario['rol'], ['admin', 'administrador'], true)) {
        $sigue_siendo_admin = in_array($rol, ['admin', 'administrador'], true) && $activo === 1;

        if (!$sigue_siendo_admin) {
            $otras = db_select_one(
                "SELECT COUNT(*) AS total FROM usuarios
                  WHERE rol IN ('admin','administrador') AND activo = 1 AND id <> ?",
                [$usuario['id']]
            );

            if ((int)($otras['total'] ?? 0) === 0) {
                $errores[] = 'Es la única cuenta administradora activa. Si se degrada o '
                           . 'desactiva, nadie podrá gestionar usuarios.';
            }
        }
    }

    // Correo único
    $duplicado = db_select_one(
        'SELECT id FROM usuarios WHERE email = ?' . ($es_nuevo ? '' : ' AND id <> ?') . ' LIMIT 1',
        $es_nuevo ? [$correo] : [$correo, $usuario_id]
    );

    if ($duplicado) {
        $errores[] = 'Ya existe una cuenta con ese correo.';
    }

    if (!empty($errores)) {
        $aviso = implode(' ', $errores);
        $aviso_tipo = 'error';

    } elseif ($es_nuevo) {
        // La contraseña se genera aquí y se muestra una sola vez: no viaja por
        // correo mientras no exista canal (Fase 4) y no queda en ningún log.
        $contrasena_nueva = generar_contrasena_temporal();

        $nuevo_id = db_insert_simple('usuarios', [
            'nombre'     => $nombre,
            'email'      => $correo,
            'correo_personal_cifrado' => cifrar_contacto($correo_personal),
            'correo_personal_indice'  => indice_ciego($correo_personal, 'correo'),
            'password'   => hash_password($contrasena_nueva),
            'rol'        => $rol,
            'unidad_id'  => $unidad_id > 0 ? $unidad_id : null,
            'activo'     => $activo,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$nuevo_id) {
            $aviso = 'No se pudo crear la cuenta.';
            $aviso_tipo = 'error';
            $contrasena_nueva = null;
        } else {
            audit_log($actual['id'], 'usuario_creado', 'usuarios', [
                'rol' => $rol,
            ], (int)$nuevo_id);

            $aviso = 'Cuenta creada.';
            $aviso_tipo = 'exito';
            $usuario_id = (int)$nuevo_id;
            $es_nuevo = false;
            $usuario = db_select_one('SELECT * FROM usuarios WHERE id = ?', [$usuario_id]);
        }

    } else {
        $cambios = [
            'nombre'    => $nombre,
            'email'     => $correo,
            'rol'       => $rol,
            'unidad_id' => $unidad_id > 0 ? $unidad_id : null,
            'activo'    => $activo,
        ];

        // El correo personal solo se toca si el formulario lo trae (RN-15)
        if ($correo_personal !== '') {
            $cambios['correo_personal_cifrado'] = cifrar_contacto($correo_personal);
            $cambios['correo_personal_indice']  = indice_ciego($correo_personal, 'correo');
        }

        if (db_update_simple('usuarios', $cambios, ['id' => $usuario_id]) === false) {
            $aviso = 'No se pudo actualizar la cuenta.';
            $aviso_tipo = 'error';
        } else {
            audit_log($actual['id'], 'usuario_actualizado', 'usuarios', [
                'rol'    => $rol,
                'activo' => $activo,
            ], $usuario_id);

            $aviso = 'Cuenta actualizada.';
            $aviso_tipo = 'exito';
            $usuario = db_select_one('SELECT * FROM usuarios WHERE id = ?', [$usuario_id]);
        }
    }

    // ── Restablecer la contraseña de otra persona ────────────────────────
    if (input('accion') === 'restablecer_contrasena' && !$es_nuevo && empty($errores)) {
        $contrasena_nueva = generar_contrasena_temporal();

        db_update_simple('usuarios', [
            'password' => hash_password($contrasena_nueva),
        ], ['id' => $usuario_id]);

        audit_log($actual['id'], 'contrasena_restablecida_por_admin', 'usuarios', null, $usuario_id);

        $aviso = 'Contraseña restablecida.';
        $aviso_tipo = 'exito';
    }
}

/**
 * Contraseña temporal legible
 *
 * Se transcribe a mano al menos una vez, así que se evitan los caracteres
 * ambiguos, igual que en el radicado.
 */
function generar_contrasena_temporal() {
    $alfabeto = '23456789ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
    $maximo = strlen($alfabeto) - 1;
    $grupos = [];

    for ($g = 0; $g < 3; $g++) {
        $grupo = '';
        for ($i = 0; $i < 5; $i++) {
            $grupo .= $alfabeto[random_int(0, $maximo)];
        }
        $grupos[] = $grupo;
    }

    return implode('-', $grupos);
}

$unidades = db_select("SELECT id, nombre, tipo FROM unidades WHERE activa = 1 ORDER BY nombre") ?: [];

$clases_aviso = [
    'error' => 'bg-red-50 border-red-500 text-red-800',
    'exito' => 'bg-green-50 border-green-600 text-green-800',
    'info'  => 'bg-blue-50 border-blue-500 text-blue-800',
];

$title = $es_nuevo ? 'Nueva cuenta' : 'Editar cuenta';
ob_start();
?>

<nav class="mb-6 text-sm" aria-label="Ruta de navegación">
    <a href="?page=dashboard" class="text-purple-700 hover:text-purple-900 underline">Dashboard</a>
    <span class="mx-2 text-gray-400">/</span>
    <a href="?page=usuarios" class="text-purple-700 hover:text-purple-900 underline">Usuarios</a>
    <span class="mx-2 text-gray-400">/</span>
    <span class="text-gray-700"><?= $es_nuevo ? 'Nueva' : e($usuario['nombre']) ?></span>
</nav>

<div class="max-w-2xl">

    <?php if ($aviso): ?>
        <div class="border-l-4 p-4 rounded mb-6 <?= $clases_aviso[$aviso_tipo] ?? $clases_aviso['info'] ?>"
             role="status">
            <p><?= e($aviso) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($contrasena_nueva !== null): ?>
        <div class="bg-purple-50 border-l-4 border-purple-700 p-5 rounded mb-6">
            <h2 class="font-bold text-purple-900 mb-2">Contraseña temporal</h2>
            <p class="text-2xl font-mono font-bold text-purple-900 mb-3 tracking-wide">
                <?= e($contrasena_nueva) ?>
            </p>
            <p class="text-sm text-purple-900">
                <strong>No se vuelve a mostrar.</strong> Entrégala por un canal seguro y
                pide que la cambie en el primer inicio de sesión. No queda registrada
                en ningún log ni en la auditoría.
            </p>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-xl font-bold text-gray-900 mb-6">
            <?= $es_nuevo ? 'Crear cuenta' : 'Editar cuenta' ?>
        </h1>

        <form method="POST" action="" class="space-y-5" autocomplete="off">
            <?= csrf_field() ?>

            <div>
                <label for="campo_nombre" class="block text-sm font-medium text-gray-700 mb-2">
                    Nombre completo <span class="text-red-700">*</span>
                </label>
                <input type="text" id="campo_nombre" name="nombre" required minlength="3"
                       value="<?= e($usuario['nombre'] ?? '') ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
            </div>

            <div>
                <label for="campo_email" class="block text-sm font-medium text-gray-700 mb-2">
                    Correo institucional <span class="text-red-700">*</span>
                </label>
                <input type="email" id="campo_email" name="email" required
                       value="<?= e($usuario['email'] ?? '') ?>"
                       placeholder="persona@udistrital.edu.co"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
            </div>

            <div>
                <label for="campo_correo_personal" class="block text-sm font-medium text-gray-700 mb-2">
                    Correo personal <span class="text-red-700">*</span>
                </label>
                <input type="email" id="campo_correo_personal" name="correo_personal" <?= $es_nuevo ? 'required' : '' ?>
                       value="<?= e(descifrar_contacto($usuario['correo_personal_cifrado'] ?? null) ?? '') ?>"
                       placeholder="persona@correo.com"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
                <p class="text-sm text-gray-600 mt-1">RN-15: toda notificación al equipo va SIEMPRE al
                    correo institucional y al personal. Se guarda cifrado.</p>
            </div>

            <div>
                <label for="campo_rol" class="block text-sm font-medium text-gray-700 mb-2">
                    Rol <span class="text-red-700">*</span>
                </label>
                <select id="campo_rol" name="rol" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
                    <?php foreach ($roles as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>"
                            <?= ($usuario['rol'] ?? '') === $valor ? 'selected' : '' ?>>
                            <?= e($etiqueta) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-sm text-gray-600 mt-1">
                    El rol determina a qué expedientes accede la cuenta. `Administración`
                    no accede a ninguno: es deliberado (§9.1).
                </p>
            </div>

            <div>
                <label for="campo_unidad" class="block text-sm font-medium text-gray-700 mb-2">
                    Unidad académica
                </label>
                <select id="campo_unidad" name="unidad_id"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
                    <option value="0">Sin unidad asignada</option>
                    <?php foreach ($unidades as $unidad): ?>
                        <option value="<?= (int)$unidad['id'] ?>"
                            <?= (int)($usuario['unidad_id'] ?? 0) === (int)$unidad['id'] ? 'selected' : '' ?>>
                            <?= e($unidad['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-sm text-gray-600 mt-1">
                    Obligatoria para el rol de unidad académica: define su alcance (RN-08).
                    <?php if (empty($unidades)): ?>
                        <br><strong>El catálogo de unidades está vacío</strong>: debe cargarlo Bienestar.
                    <?php endif; ?>
                </p>
            </div>

            <div>
                <label class="flex items-center gap-3">
                    <input type="checkbox" name="activo" value="1"
                        <?= ($es_nuevo || (int)($usuario['activo'] ?? 1) === 1) ? 'checked' : '' ?>
                           class="w-4 h-4 text-purple-700 border-gray-300 rounded focus:ring-purple-600">
                    <span class="text-sm font-medium text-gray-700">Cuenta activa</span>
                </label>
                <p class="text-sm text-gray-600 mt-1">
                    Una cuenta inactiva no puede iniciar sesión ni recibir asignaciones.
                </p>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 pt-4 border-t">
                <button type="submit"
                        class="px-6 py-2 bg-purple-700 hover:bg-purple-800 text-white rounded-lg transition">
                    <?= $es_nuevo ? 'Crear cuenta' : 'Guardar cambios' ?>
                </button>
                <a href="?page=usuarios"
                   class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg transition text-center">
                    Cancelar
                </a>
            </div>
        </form>
    </div>

    <?php if (!$es_nuevo): ?>
    <div class="bg-white rounded-lg shadow p-6 mt-6">
        <h2 class="text-lg font-bold text-gray-800 mb-2">Restablecer contraseña</h2>
        <p class="text-sm text-gray-700 mb-4">
            Genera una contraseña temporal y la muestra una sola vez. La contraseña
            anterior deja de funcionar de inmediato.
        </p>
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="restablecer_contrasena">
            <input type="hidden" name="nombre" value="<?= e($usuario['nombre']) ?>">
            <input type="hidden" name="email" value="<?= e($usuario['email']) ?>">
            <input type="hidden" name="rol" value="<?= e($usuario['rol']) ?>">
            <input type="hidden" name="unidad_id" value="<?= (int)($usuario['unidad_id'] ?? 0) ?>">
            <input type="hidden" name="activo" value="<?= (int)$usuario['activo'] ?>">
            <button type="submit"
                    onclick="return confirm('La contraseña actual dejará de funcionar. ¿Continuar?')"
                    class="px-6 py-2 bg-yellow-100 hover:bg-yellow-200 text-yellow-900 border border-yellow-400 rounded-lg transition">
                Restablecer contraseña
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
