<?php
/**
 * SISTEMA DE AUTENTICACIÓN NATIVO
 *
 * Manejo de sesiones, login, logout y verificación de permisos
 */

// ============================================================================
// FUNCIONES DE AUTENTICACIÓN
// ============================================================================

/**
 * Iniciar sesión de usuario
 *
 * @param string $email Email del usuario
 * @param string $password Contraseña en texto plano
 * @return bool|array false o datos del usuario
 */
function login($email, $password) {
    // Buscar usuario por email
    $query = "SELECT * FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1";
    $user = db_select_one($query, [$email]);

    if (!$user) {
        // CLAUDE.md §11: la auditoría nunca almacena correos. Se guarda un
        // identificador derivado que permite correlacionar intentos sin
        // conservar el dato personal.
        audit_log(null, 'login_fallido', 'usuarios', [
            'motivo' => 'usuario_no_encontrado',
            'correo_ref' => substr(hash('sha256', strtolower($email)), 0, 16),
        ]);
        return false;
    }

    // Verificar contraseña
    if (!verify_password($password, $user['password'])) {
        audit_log($user['id'], 'login_fallido', 'usuarios', [
            'motivo' => 'contrasena_invalida',
        ], $user['id']);
        return false;
    }

    // Actualizar último acceso
    db_execute("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?", [$user['id']]);

    // Guardar en sesión
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_nombre'] = $user['nombre'];
    $_SESSION['user_rol'] = $user['rol'];
    $_SESSION['authenticated'] = true;
    $_SESSION['login_time'] = time();

    // Regenerar ID de sesión por seguridad
    session_regenerate_id(true);

    // Registrar login exitoso
    audit_log($user['id'], 'login_exitoso', 'usuarios', null, $user['id']);

    return $user;
}

/**
 * Cerrar sesión
 */
function logout() {
    $user_id = $_SESSION['user_id'] ?? 0;

    // Registrar cierre de sesión
    if ($user_id > 0) {
        audit_log($user_id, 'cierre_sesion', 'usuarios', null, $user_id);
    }

    // Limpiar sesión
    $_SESSION = [];

    // Destruir cookie de sesión
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }

    // Destruir sesión
    session_destroy();

    // Iniciar nueva sesión limpia
    session_start();
}

/**
 * Verificar si el usuario está autenticado
 *
 * @return bool
 */
function is_authenticated() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

/**
 * Obtener ID del usuario autenticado
 *
 * @return int|null
 */
function auth_user_id() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Obtener datos del usuario autenticado
 *
 * @return array|null
 */
function auth_user($recargar = false) {
    if (!is_authenticated()) {
        return null;
    }

    static $user = null;

    if ($user === null || $recargar) {
        $user_id = auth_user_id();
        $query = "SELECT * FROM usuarios WHERE id = ? LIMIT 1";
        $user = db_select_one($query, [$user_id]);
    }

    return $user;
}

/**
 * Releer del sistema los datos de la persona autenticada
 *
 * `auth_user()` guarda la fila en una estática para no consultarla en cada
 * llamada, lo que está bien mientras la fila no cambie. Cuando cambia dentro de
 * la misma petición —alguien edita su perfil— todo lo que se pinte después
 * sigue mostrando los datos viejos: se guarda el nombre nuevo y el menú lateral
 * sigue con el anterior hasta la siguiente carga.
 *
 * Quien modifique la fila del propio usuario debe llamar a esto.
 *
 * @return array|null
 */
function auth_refresh() {
    return auth_user(true);
}

/**
 * Verificar si el usuario tiene un rol específico
 *
 * @param string $rol Rol requerido
 * @return bool
 */
function has_role($rol) {
    $user = auth_user();
    return $user && $user['rol'] === $rol;
}

/**
 * Verificar si el usuario tiene alguno de los roles especificados
 *
 * @param array $roles Array de roles
 * @return bool
 */
function has_any_role($roles) {
    $user = auth_user();
    return $user && in_array($user['rol'], $roles);
}

/**
 * Requiere autenticación (redirige a login si no está autenticado)
 */
function require_auth() {
    if (!is_authenticated()) {
        // H-11: antes apuntaba a 'login.php', ruta inexistente en el enrutador
        redirect(url('?page=login'));
        exit;
    }
}

/**
 * Requiere rol específico (redirige si no tiene permiso)
 *
 * @param string $rol Rol requerido
 */
function require_role($rol) {
    require_auth();

    if (!has_role($rol)) {
        redirect_with_message(url('?page=dashboard'), 'No tienes permisos para acceder a esta página.', 'error');
        exit;
    }
}

/**
 * Requiere alguno de los roles especificados
 *
 * @param array $roles Array de roles
 */
function require_any_role($roles) {
    require_auth();

    if (!has_any_role($roles)) {
        redirect_with_message(url('?page=dashboard'), 'No tienes permisos para acceder a esta página.', 'error');
        exit;
    }
}

// ============================================================================
// FUNCIONES DE GESTIÓN DE USUARIOS
// ============================================================================

/**
 * Crear nuevo usuario
 *
 * @param array $data Datos del usuario
 * @return int|false ID del usuario creado o false
 */
function create_user($data) {
    // Validar email único
    if (db_exists('usuarios', ['email' => $data['email']])) {
        return false;
    }

    // ── RN-15 (v3.1 · Fase C) · doble correo obligatorio ─────────────────
    // El institucional se valida contra DOMINIO_INSTITUCIONAL; el personal
    // se cifra en reposo. Sin correo personal, el alta se rechaza.
    if (function_exists('config_valor')) {
        $dominio = (string)config_valor('DOMINIO_INSTITUCIONAL', 'udistrital.edu.co');

        if ($dominio !== '' && !str_ends_with(strtolower($data['email']), '@' . strtolower($dominio))) {
            log_error('RN-15: el correo institucional no pertenece al dominio ' . $dominio, 'error');
            return false;
        }

        $correo_personal = trim((string)($data['correo_personal'] ?? ''));
        unset($data['correo_personal']);

        if ($correo_personal === '' || !valid_email($correo_personal)) {
            log_error('RN-15: el correo personal es obligatorio al crear la cuenta', 'error');
            return false;
        }

        $data['correo_personal_cifrado'] = cifrar_contacto($correo_personal);
        $data['correo_personal_indice']  = indice_ciego($correo_personal, 'correo');
    }

    // Hashear contraseña
    $data['password'] = hash_password($data['password']);

    // Campos por defecto
    $data['activo'] = $data['activo'] ?? 1;
    $data['created_at'] = date('Y-m-d H:i:s');

    return db_insert_simple('usuarios', $data);
}

/**
 * Actualizar usuario
 *
 * @param int $user_id ID del usuario
 * @param array $data Datos a actualizar
 * @return int|false
 */
function update_user($user_id, $data) {
    // Si se envía password, hashearla
    if (isset($data['password']) && !empty($data['password'])) {
        $data['password'] = hash_password($data['password']);
    } else {
        // No actualizar password si no se envió
        unset($data['password']);
    }

    $data['updated_at'] = date('Y-m-d H:i:s');

    return db_update_simple('usuarios', $data, ['id' => $user_id]);
}

/**
 * Eliminar usuario (soft delete)
 *
 * @param int $user_id ID del usuario
 * @return int|false
 */
function delete_user($user_id) {
    return db_update_simple('usuarios', ['activo' => 0], ['id' => $user_id]);
}

/**
 * Obtener usuario por ID
 *
 * @param int $user_id ID del usuario
 * @return array|false
 */
function get_user($user_id) {
    $query = "SELECT * FROM usuarios WHERE id = ? LIMIT 1";
    return db_select_one($query, [$user_id]);
}

/**
 * Obtener usuario por email
 *
 * @param string $email Email del usuario
 * @return array|false
 */
function get_user_by_email($email) {
    $query = "SELECT * FROM usuarios WHERE email = ? LIMIT 1";
    return db_select_one($query, [$email]);
}

/**
 * Listar usuarios
 *
 * @param array $filters Filtros opcionales
 * @param int $page Página actual
 * @param int $per_page Registros por página
 * @return array
 */
function list_users($filters = [], $page = 1, $per_page = 20) {
    $where_conditions = [];
    $params = [];

    if (isset($filters['rol'])) {
        $where_conditions[] = "rol = ?";
        $params[] = $filters['rol'];
    }

    if (isset($filters['activo'])) {
        $where_conditions[] = "activo = ?";
        $params[] = $filters['activo'];
    }

    if (isset($filters['search'])) {
        $where_conditions[] = "(nombre LIKE ? OR email LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }

    $where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    // Obtener total
    $count_query = "SELECT COUNT(*) as total FROM usuarios $where_sql";
    $count_result = db_select_one($count_query, $params);
    $total = $count_result['total'] ?? 0;

    // Obtener registros paginados
    $offset = ($page - 1) * $per_page;
    $query = "SELECT * FROM usuarios $where_sql ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $users = db_select($query, $params);

    return [
        'data' => $users,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page)
    ];
}

// ============================================================================
// FUNCIONES DE RECUPERACIÓN DE CONTRASEÑA
// ============================================================================

/**
 * Generar token de recuperación de contraseña
 *
 * @param string $email Email del usuario
 * @return string|false Token generado o false
 */
function generate_password_reset_token($email) {
    $user = get_user_by_email($email);

    if (!$user) {
        return false;
    }

    // Solo cuentas activas: una cuenta desactivada no debe poder reactivarse
    // por la puerta de la recuperación
    if ((int)$user['activo'] !== 1) {
        return false;
    }

    $token = generate_token(64);
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Invalidar los tokens anteriores en lugar de borrarlos: se conserva la
    // constancia de cuántas veces se solicitó y desde dónde
    db_execute(
        "UPDATE password_resets SET usado_at = NOW()
          WHERE email = ? AND usado_at IS NULL",
        [$email]
    );

    $data = [
        'email'      => $email,
        'token'      => hash('sha256', $token), // el token en claro no se almacena
        'ip'         => get_user_ip(),
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => $expires_at,
    ];

    $result = db_insert_simple('password_resets', $data);

    return $result ? $token : false;
}

/**
 * Validar token de recuperación
 *
 * @param string $token Token a validar
 * @return string|false Email del usuario o false
 */
function validate_password_reset_token($token) {
    if (!is_string($token) || strlen($token) !== 64) {
        return false;
    }

    $hashed_token = hash('sha256', $token);

    // `usado_at IS NULL` es imprescindible: sin esa condición un enlace
    // seguiría sirviendo después de haberse usado, durante la hora restante
    $query = "SELECT email FROM password_resets
              WHERE token = ? AND expires_at > NOW() AND usado_at IS NULL LIMIT 1";

    $result = db_select_one($query, [$hashed_token]);

    return $result ? $result['email'] : false;
}

/**
 * Resetear contraseña
 *
 * @param string $token Token de recuperación
 * @param string $new_password Nueva contraseña
 * @return bool
 */
function reset_password($token, $new_password) {
    $email = validate_password_reset_token($token);

    if (!$email) {
        return false;
    }

    $user = get_user_by_email($email);

    if (!$user) {
        return false;
    }

    // Actualizar contraseña
    $result = update_user($user['id'], ['password' => $new_password]);

    if ($result !== false) {
        // Marcar el token como usado y anular cualquier otro vigente de esa
        // cuenta: si alguien solicitó dos enlaces, el segundo también muere
        db_execute(
            "UPDATE password_resets SET usado_at = NOW()
              WHERE email = ? AND usado_at IS NULL",
            [$email]
        );

        // Cerrar la sesión activa de esa cuenta, si la hubiera: quien cambia
        // la contraseña puede estar haciéndolo justamente porque otra persona
        // tenía acceso
        db_execute("UPDATE usuarios SET updated_at = NOW() WHERE id = ?", [$user['id']]);

        audit_log($user['id'], 'contrasena_restablecida', 'usuarios', null, $user['id']);

        return true;
    }

    return false;
}
?>
