<?php
/**
 * MÓDULO: LOGIN
 *
 * Formulario de inicio de sesión
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Si ya está autenticado, redirigir al dashboard
if (is_authenticated()) {
    redirect(url('?page=dashboard'));
}

// Procesar formulario de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Protección CSRF
    csrf_protect();

    // Obtener datos del formulario
    $email = input('email', '', 'email');
    $password = input('password', '');
    $remember = input('remember', false, 'bool');

    // Validar campos requeridos
    $errors = validate([
        'email' => $email,
        'password' => $password,
    ], [
        'email' => 'required|email',
        'password' => 'required|min:6',
    ]);

    if (empty($errors)) {
        // Rate limiting (anti-brute force)
        $rate_key = 'login:' . get_user_ip();

        if (!rate_limit_check($rate_key, 5, 15)) {
            set_flash('Demasiados intentos fallidos. Por favor espera 15 minutos.', 'error');
        } else {
            // Intentar login
            $user = login($email, $password);

            if ($user) {
                // Login exitoso - limpiar rate limit
                rate_limit_clear($rate_key);

                // Redireccionar al dashboard
                redirect(url('?page=dashboard'));
            } else {
                // Login fallido
                set_flash('Email o contraseña incorrectos.', 'error');
                save_old_input();
            }
        }
    } else {
        // Errores de validación
        set_flash('Por favor corrige los errores en el formulario.', 'error');
        save_old_input();
    }
}

// Obtener flash message
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - <?= e(APP_NAME) ?></title>
    <?php require BASE_PATH . '/views/componentes/assets.php'; ?>
</head>
<body class="bg-gradient-to-br from-purple-100 to-purple-200 min-h-screen flex items-center justify-center">

    <div class="w-full max-w-md">
        <!-- Logo/Header -->
        <div class="text-center mb-8">
            <div class="inline-block bg-purple-600 text-white rounded-full p-4 mb-4">
                <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-800"><?= e(APP_NAME) ?></h1>
            <p class="text-gray-600 mt-2">Acceso al Sistema</p>
        </div>

        <!-- Card de Login -->
        <div class="bg-white rounded-lg shadow-xl p-8">

            <!-- Flash Message -->
            <?php if ($flash): ?>
                <div class="mb-4 p-4 rounded-lg <?= $flash['type'] === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <!-- Formulario -->
            <form method="POST" action="">
                <?= csrf_field() ?>

                <!-- Email -->
                <div class="mb-4">
                    <label for="email" class="block text-gray-700 font-medium mb-2">
                        Email
                    </label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= old('email') ?>"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                        placeholder="usuario@universidad.edu.co"
                    >
                    <?php if (isset($errors['email'])): ?>
                        <p class="text-red-600 text-sm mt-1"><?= e($errors['email'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Password -->
                <div class="mb-4">
                    <label for="password" class="block text-gray-700 font-medium mb-2">
                        Contraseña
                    </label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                        placeholder="••••••••"
                    >
                    <?php if (isset($errors['password'])): ?>
                        <p class="text-red-600 text-sm mt-1"><?= e($errors['password'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Recordar sesión -->
                <div class="mb-6">
                    <label class="flex items-center">
                        <input
                            type="checkbox"
                            name="remember"
                            value="1"
                            class="w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500"
                        >
                        <span class="ml-2 text-gray-700">Recordar sesión</span>
                    </label>
                </div>

                <!-- Botón -->
                <button
                    type="submit"
                    class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 rounded-lg transition duration-200 transform hover:scale-105"
                >
                    Iniciar Sesión
                </button>

                <!-- Enlaces adicionales -->
                <div class="mt-6 text-center text-sm">
                    <a href="?page=recuperar" class="text-purple-600 hover:text-purple-800">
                        ¿Olvidaste tu contraseña?
                    </a>
                </div>
            </form>

            <!-- Credenciales de prueba (solo desarrollo) -->
            <?php if (APP_DEBUG): ?>
                <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <p class="text-yellow-800 font-semibold text-sm mb-2">Credenciales de Prueba:</p>
                    <p class="text-yellow-700 text-xs">
                        <strong>Admin:</strong> admin@botonvioleta.edu.co / Admin123!<br>
                        <strong>Coord:</strong> coordinadora@botonvioleta.edu.co / Coord123!
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="text-center mt-6 text-gray-600 text-sm">
            <p>&copy; <?= date('Y') ?> Universidad Distrital Francisco José de Caldas</p>
            <p class="mt-1">Sistema de protección y atención integral</p>
        </div>
    </div>

</body>
</html>
<?php clear_old_input(); ?>
