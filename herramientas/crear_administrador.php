<?php
/**
 * CREAR LA PRIMERA CUENTA ADMINISTRADORA
 *
 * Sustituye a los usuarios que la migración 0001 sembraba con contraseñas
 * publicadas en sus comentarios —y cuyos hashes, además, no correspondían a
 * esas contraseñas—.
 *
 * Diferencias que importan:
 *
 *   · La contraseña se genera aquí, con el generador criptográfico del
 *     sistema, y se muestra UNA SOLA VEZ. No queda escrita en ningún archivo
 *     del repositorio.
 *   · El hash se calcula en el momento, así que siempre corresponde.
 *   · No se registra la contraseña en el log ni en la auditoría.
 *
 * Uso:
 *   php herramientas/crear_administrador.php --email=persona@udistrital.edu.co
 *   php herramientas/crear_administrador.php --email=… --nombre="Nombre Apellido"
 *   php herramientas/crear_administrador.php --email=… --rol=coordinacion_edheg
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__));

ob_start();
require_once BASE_PATH . '/init.php';
ob_end_clean();

// ── Argumentos ───────────────────────────────────────────────────────────────
$correo = null;
$nombre = null;
$rol    = 'administrador';

foreach ($argv as $arg) {
    if (strpos($arg, '--email=')  === 0) { $correo = substr($arg, 8);  }
    if (strpos($arg, '--nombre=') === 0) { $nombre = substr($arg, 9);  }
    if (strpos($arg, '--rol=')    === 0) { $rol    = substr($arg, 6);  }
}

if ($correo === null) {
    echo "\nCrear la primera cuenta del sistema\n";
    echo str_repeat('-', 58) . "\n";
    echo "Uso:\n";
    echo "  php herramientas/crear_administrador.php --email=persona@udistrital.edu.co\n\n";
    echo "Opciones:\n";
    echo "  --nombre=\"Nombre Apellido\"   Nombre a mostrar\n";
    echo "  --rol=ROL                     Por defecto: administrador\n\n";
    echo "Roles disponibles:\n";
    echo "  administrador        Configuración y usuarios · SIN acceso a expedientes\n";
    echo "  coordinacion_edheg   Coordinación del EDHEG · acceso a los expedientes\n\n";
    echo "⚠ `administrador` NO puede ver expedientes (§9.1). Es deliberado: el rol\n";
    echo "  con más poder técnico es el que menos necesita leer relatos. Para\n";
    echo "  atender casos hace falta una cuenta `coordinacion_edheg`.\n\n";
    exit(0);
}

if (!valid_email($correo)) {
    exit("El correo «{$correo}» no es válido.\n");
}

// ── Validaciones ─────────────────────────────────────────────────────────────
$roles_validos = ['administrador', 'coordinacion_edheg', 'coordinador'];

if (!in_array($rol, $roles_validos, true)) {
    exit("Rol «{$rol}» no admitido. Use uno de: " . implode(', ', $roles_validos) . "\n");
}

if (db_exists('usuarios', ['email' => $correo])) {
    exit("Ya existe una cuenta con el correo «{$correo}».\n");
}

// ── Contraseña ───────────────────────────────────────────────────────────────
/**
 * Generar una contraseña legible y fuerte
 *
 * Se evitan los caracteres ambiguos por la misma razón que en el radicado:
 * esta contraseña se dicta o se transcribe a mano al menos una vez.
 */
function generar_contrasena_inicial() {
    $alfabeto = '23456789ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
    $maximo = strlen($alfabeto) - 1;
    $grupos = [];

    for ($g = 0; $g < 4; $g++) {
        $grupo = '';
        for ($i = 0; $i < 5; $i++) {
            $grupo .= $alfabeto[random_int(0, $maximo)];
        }
        $grupos[] = $grupo;
    }

    return implode('-', $grupos);
}

$contrasena = generar_contrasena_inicial();

// ── Crear ────────────────────────────────────────────────────────────────────
$id = db_insert_simple('usuarios', [
    'nombre'     => $nombre !== null ? $nombre : 'Administración del sistema',
    'email'      => $correo,
    'password'   => hash_password($contrasena),
    'rol'        => $rol,
    'activo'     => 1,
    'created_at' => date('Y-m-d H:i:s'),
]);

if (!$id) {
    exit("No se pudo crear la cuenta. Revise el log de la aplicación.\n");
}

// La auditoría registra la creación, NUNCA la contraseña (§11)
audit_log(null, 'cuenta_inicial_creada', 'usuarios', ['rol' => $rol], (int)$id);

// ── Salida ───────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 58) . "\n";
echo " CUENTA CREADA\n";
echo str_repeat('=', 58) . "\n\n";
printf("  Correo:      %s\n", $correo);
printf("  Rol:         %s\n", $rol);
printf("  Contraseña:  %s\n\n", $contrasena);
echo str_repeat('-', 58) . "\n";
echo " ⚠ Esta contraseña NO se vuelve a mostrar y no queda escrita en\n";
echo "   ningún archivo. Guárdela ahora en el gestor de credenciales de\n";
echo "   la Universidad y cámbiela en el primer inicio de sesión.\n";
echo str_repeat('-', 58) . "\n\n";

if ($rol === 'administrador') {
    echo "Recuerde: el rol `administrador` NO accede a expedientes (§9.1).\n";
    echo "Para atender casos, cree además una cuenta `coordinacion_edheg`.\n\n";
}
