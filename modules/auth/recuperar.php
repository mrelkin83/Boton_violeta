<?php
/**
 * MÓDULO: RECUPERACIÓN DE CONTRASEÑA
 *
 * Cierra el hallazgo H-09.
 *
 * Dos pasos en un solo archivo:
 *   1. Solicitar el enlace  (?page=recuperar)
 *   2. Definir la nueva contraseña  (?page=recuperar&token=…)
 *
 * Principios, los mismos que rigen el seguimiento público:
 *
 *   · RESPUESTA UNIFORME. El sistema no confirma si un correo está registrado.
 *     Decirlo permitiría enumerar las cuentas del equipo, y saber quién
 *     atiende casos de violencia de género es información sensible por sí sola.
 *   · El token viaja por el Outbox, nunca de forma síncrona.
 *   · El token en claro no se almacena ni se registra en ningún log.
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

if (is_authenticated()) {
    redirect(url('?page=dashboard'));
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
}

const MENSAJE_UNIFORME_RECUPERACION =
    'Si el correo corresponde a una cuenta activa, enviamos un enlace para restablecer la contraseña. '
  . 'El enlace vence en una hora.';

$token       = trim((string)input_get('token', ''));
$paso        = $token !== '' ? 'definir' : 'solicitar';
$aviso       = null;
$aviso_tipo  = 'info';
$completado  = false;

// ─────────────────────────────────────────────────────────────────────────────
// Procesamiento
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $accion = input('accion', '');

    // ── Paso 1 · solicitar el enlace ─────────────────────────────────────
    if ($accion === 'solicitar') {
        $email = input('email', '', 'email');
        $ip    = get_user_ip();

        if (!rate_limit_check('recuperacion:' . $ip, 5, 60)) {
            $aviso = 'Demasiadas solicitudes desde esta conexión. Intenta más tarde.';
            $aviso_tipo = 'error';
        } else {
            // El resultado no cambia la respuesta: se responde igual exista o
            // no la cuenta.
            if ($email !== null && $email !== '') {
                $token_generado = generate_password_reset_token($email);

                if ($token_generado !== false) {
                    $enlace = url('?page=recuperar&token=' . $token_generado);

                    // RN-09 · el correo no lleva datos de ningún caso.
                    // El enlace es el secreto: viaja cifrado en la cola.
                    encolar_notificacion('correo', $email, 'recuperar_contrasena', [
                        'enlace' => url('?page=recuperar'),
                    ], null, $enlace);

                    audit_log(null, 'recuperacion_solicitada', 'password_resets', [
                        'ip' => $ip,
                    ]);
                }
            }

            $aviso = MENSAJE_UNIFORME_RECUPERACION;
            $aviso_tipo = 'info';

            if (!graph_activo()) {
                $aviso .= ' El envío de correos aún no está habilitado en este entorno; '
                        . 'comunícate con el administrador del sistema.';
                $aviso_tipo = 'aviso';
            }
        }
    }

    // ── Paso 2 · definir la nueva contraseña ─────────────────────────────
    if ($accion === 'definir') {
        $token       = trim((string)input('token', ''));
        $contrasena  = (string)($_POST['password'] ?? '');
        $confirmar   = (string)($_POST['password_confirmar'] ?? '');
        $paso        = 'definir';

        if (!rate_limit_check('recuperacion_uso:' . get_user_ip(), 10, 60)) {
            $aviso = 'Demasiados intentos. Intenta más tarde.';
            $aviso_tipo = 'error';
        } elseif (strlen($contrasena) < 12) {
            $aviso = 'La contraseña debe tener al menos 12 caracteres.';
            $aviso_tipo = 'error';
        } elseif ($contrasena !== $confirmar) {
            $aviso = 'Las dos contraseñas no coinciden.';
            $aviso_tipo = 'error';
        } elseif (!preg_match('/[A-Za-z]/', $contrasena) || !preg_match('/\d/', $contrasena)) {
            $aviso = 'La contraseña debe combinar letras y números.';
            $aviso_tipo = 'error';
        } else {
            if (reset_password($token, $contrasena)) {
                $completado = true;
                $aviso = 'Tu contraseña fue actualizada. Ya puedes iniciar sesión.';
                $aviso_tipo = 'exito';
            } else {
                $aviso = 'El enlace no es válido o ya expiró. Solicita uno nuevo.';
                $aviso_tipo = 'error';
                $paso = 'solicitar';
            }
        }
    }
}

// Un token presente en la URL debe ser válido para mostrar el formulario
if ($paso === 'definir' && !$completado && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (validate_password_reset_token($token) === false) {
        $aviso = 'El enlace no es válido o ya expiró. Solicita uno nuevo.';
        $aviso_tipo = 'error';
        $paso = 'solicitar';
    }
}

$clases_aviso = [
    'error' => 'bg-red-50 border-red-500 text-red-800',
    'aviso' => 'bg-yellow-50 border-yellow-500 text-yellow-800',
    'exito' => 'bg-green-50 border-green-600 text-green-800',
    'info'  => 'bg-blue-50 border-blue-500 text-blue-800',
];

$title = 'Recuperar contraseña';
ob_start();
?>

<div class="max-w-md mx-auto">
    <div class="bg-white rounded-lg shadow-lg p-6 md:p-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Recuperar contraseña</h1>
        <p class="text-gray-700 mb-6">Acceso del equipo de atención.</p>

        <?php if ($aviso): ?>
            <div class="border-l-4 p-4 rounded mb-6 <?= $clases_aviso[$aviso_tipo] ?? $clases_aviso['info'] ?>"
                 role="status">
                <p><?= e($aviso) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($completado): ?>
            <a href="?page=login"
               class="block w-full text-center px-6 py-3 bg-purple-700 hover:bg-purple-800 text-white font-medium rounded-lg transition">
                Iniciar sesión
            </a>

        <?php elseif ($paso === 'definir'): ?>
            <form method="POST" action="" autocomplete="off" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="definir">
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div>
                    <label for="campo_password" class="block text-sm font-medium text-gray-800 mb-2">
                        Nueva contraseña
                    </label>
                    <input type="password" id="campo_password" name="password" required
                           minlength="12" autocomplete="new-password"
                           aria-describedby="ayuda_password"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-700">
                    <p id="ayuda_password" class="text-sm text-gray-600 mt-1">
                        Mínimo 12 caracteres, combinando letras y números.
                    </p>
                </div>

                <div>
                    <label for="campo_password_confirmar" class="block text-sm font-medium text-gray-800 mb-2">
                        Repite la contraseña
                    </label>
                    <input type="password" id="campo_password_confirmar" name="password_confirmar" required
                           minlength="12" autocomplete="new-password"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-700">
                </div>

                <button type="submit"
                        class="w-full px-6 py-3 bg-purple-700 hover:bg-purple-800 text-white font-medium rounded-lg transition">
                    Guardar contraseña
                </button>
            </form>

        <?php else: ?>
            <form method="POST" action="" autocomplete="off" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="solicitar">

                <div>
                    <label for="campo_email" class="block text-sm font-medium text-gray-800 mb-2">
                        Correo institucional
                    </label>
                    <input type="email" id="campo_email" name="email" required
                           autocomplete="username"
                           placeholder="usuario@udistrital.edu.co"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-700">
                </div>

                <button type="submit"
                        class="w-full px-6 py-3 bg-purple-700 hover:bg-purple-800 text-white font-medium rounded-lg transition">
                    Enviar enlace de recuperación
                </button>
            </form>
        <?php endif; ?>

        <p class="text-center mt-6">
            <a href="?page=login" class="text-purple-800 underline">Volver al inicio de sesión</a>
        </p>
    </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/guest.php';
