<?php
/**
 * FUNCIONES DE SEGURIDAD
 *
 * CSRF Protection, Rate Limiting, Input Validation
 */

// ============================================================================
// CSRF PROTECTION
// ============================================================================

/**
 * Generar token CSRF
 *
 * @return string
 */
function csrf_token() {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        $_SESSION['csrf_token'] = generate_token(32);
        $_SESSION['csrf_token_time'] = time();
    } else {
        // Regenerar token si ha expirado
        if (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_LIFETIME) {
            $_SESSION['csrf_token'] = generate_token(32);
            $_SESSION['csrf_token_time'] = time();
        }
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validar token CSRF
 *
 * @param string $token Token a validar
 * @return bool
 */
function csrf_verify($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Campo hidden con token CSRF para formularios
 *
 * @return string HTML
 */
function csrf_field() {
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Verificar CSRF en request POST
 */
function csrf_protect() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (!csrf_verify($token)) {
            log_error('CSRF token validation failed', 'security', [
                'ip' => get_user_ip(),
                'user_id' => auth_user_id()
            ]);

            http_response_code(403);
            die('CSRF token validation failed');
        }
    }
}

// ============================================================================
// RATE LIMITING
// ============================================================================

/**
 * Verificar rate limit
 *
 * @param string $key Clave única (ej: 'login:' . $ip)
 * @param int $max_attempts Máximo de intentos
 * @param int $decay_minutes Minutos hasta resetear
 * @return bool true si está dentro del límite
 */
function rate_limit_check($key, $max_attempts = RATE_LIMIT_MAX_ATTEMPTS, $decay_minutes = RATE_LIMIT_DECAY_MINUTES) {
    $cache_file = BASE_PATH . '/cache/rate_limit_' . md5($key) . '.json';

    // Crear directorio si no existe
    $cache_dir = dirname($cache_file);
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }

    $data = [];

    if (file_exists($cache_file)) {
        $content = file_get_contents($cache_file);
        $data = json_decode($content, true) ?? [];
    }

    $now = time();
    $reset_time = $data['reset_time'] ?? $now;
    $attempts = $data['attempts'] ?? 0;

    // Si pasó el tiempo de decay, resetear
    if ($now >= $reset_time) {
        $data = [
            'attempts' => 1,
            'reset_time' => $now + ($decay_minutes * 60)
        ];

        file_put_contents($cache_file, json_encode($data), LOCK_EX);
        return true;
    }

    // Si está dentro del límite
    if ($attempts < $max_attempts) {
        $data['attempts']++;
        file_put_contents($cache_file, json_encode($data), LOCK_EX);
        return true;
    }

    // Excedió el límite
    log_error('Rate limit exceeded', 'security', [
        'key' => $key,
        'attempts' => $attempts,
        'max_attempts' => $max_attempts
    ]);

    return false;
}

/**
 * Limpiar rate limit para una clave
 *
 * @param string $key Clave única
 */
function rate_limit_clear($key) {
    $cache_file = BASE_PATH . '/cache/rate_limit_' . md5($key) . '.json';

    if (file_exists($cache_file)) {
        unlink($cache_file);
    }
}

// ============================================================================
// VALIDACIÓN DE INPUT
// ============================================================================

/**
 * Obtener input de POST con validación
 *
 * @param string $key Nombre del campo
 * @param mixed $default Valor por defecto
 * @param string $filter Tipo de filtro: string|email|int|float|bool
 * @return mixed
 */
function input($key, $default = null, $filter = 'string') {
    if (!isset($_POST[$key])) {
        return $default;
    }

    $value = $_POST[$key];

    switch ($filter) {
        case 'email':
            return filter_var($value, FILTER_VALIDATE_EMAIL) ?: $default;

        case 'int':
            return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : $default;

        case 'float':
            return filter_var($value, FILTER_VALIDATE_FLOAT) !== false ? (float)$value : $default;

        case 'bool':
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);

        case 'string':
        default:
            return clean_input($value);
    }
}

/**
 * Obtener input de GET con validación
 *
 * @param string $key Nombre del campo
 * @param mixed $default Valor por defecto
 * @param string $filter Tipo de filtro
 * @return mixed
 */
function input_get($key, $default = null, $filter = 'string') {
    if (!isset($_GET[$key])) {
        return $default;
    }

    $value = $_GET[$key];

    switch ($filter) {
        case 'int':
            return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : $default;

        case 'string':
        default:
            return clean_input($value);
    }
}

/**
 * Validar array de reglas
 *
 * @param array $data Datos a validar
 * @param array $rules Reglas de validación
 * @return array Errores de validación (vacío si todo OK)
 */
function validate($data, $rules) {
    $errors = [];

    foreach ($rules as $field => $rule_string) {
        $rules_array = explode('|', $rule_string);
        $value = $data[$field] ?? null;

        foreach ($rules_array as $rule) {
            $rule_parts = explode(':', $rule);
            $rule_name = $rule_parts[0];
            $rule_param = $rule_parts[1] ?? null;

            switch ($rule_name) {
                case 'required':
                    if (empty($value)) {
                        $errors[$field][] = "El campo $field es obligatorio";
                    }
                    break;

                case 'email':
                    if (!empty($value) && !valid_email($value)) {
                        $errors[$field][] = "El campo $field debe ser un email válido";
                    }
                    break;

                case 'min':
                    if (!empty($value) && strlen($value) < (int)$rule_param) {
                        $errors[$field][] = "El campo $field debe tener al menos $rule_param caracteres";
                    }
                    break;

                case 'max':
                    if (!empty($value) && strlen($value) > (int)$rule_param) {
                        $errors[$field][] = "El campo $field no debe exceder $rule_param caracteres";
                    }
                    break;

                case 'numeric':
                    if (!empty($value) && !is_numeric($value)) {
                        $errors[$field][] = "El campo $field debe ser numérico";
                    }
                    break;

                case 'unique':
                    // unique:tabla,columna
                    list($table, $column) = explode(',', $rule_param);
                    if (!empty($value) && db_exists($table, [$column => $value])) {
                        $errors[$field][] = "El campo $field ya existe en la base de datos";
                    }
                    break;
            }
        }
    }

    return $errors;
}

// ============================================================================
// PROTECCIÓN CONTRA XSS
// ============================================================================

/**
 * Limpiar HTML permitiendo solo tags seguros
 *
 * @param string $html HTML a limpiar
 * @return string
 */
function clean_html($html) {
    $allowed_tags = '<p><br><strong><em><u><ul><ol><li><a>';
    return strip_tags($html, $allowed_tags);
}

// ============================================================================
// HEADERS DE SEGURIDAD
// ============================================================================

/**
 * Establecer headers de seguridad
 */
function set_security_headers() {
    if (headers_sent()) {
        return;
    }

    // Prevenir clickjacking
    header('X-Frame-Options: DENY');

    // Prevenir MIME sniffing
    header('X-Content-Type-Options: nosniff');

    // Protección XSS
    header('X-XSS-Protection: 1; mode=block');

    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Content Security Policy
    //
    // La política se ajusta al modo de carga de recursos. Mantener una CSP
    // permisiva cuando no hace falta es regalar la defensa; mantenerla
    // estricta cuando el sitio necesita el CDN rompe el sitio en silencio,
    // que es peor: el navegador bloquea sin avisar a nadie.
    if (function_exists('usar_assets_cdn') && usar_assets_cdn()) {
        // H-21 reabierto: Tailwind se compila en el navegador, y para hacerlo
        // necesita 'unsafe-inline'. Ver views/componentes/assets.php.
        //
        // `cdn.jsdelivr.net` se retiró junto con Alpine: era el único recurso
        // que venía de ahí. Una política que sigue autorizando un origen que ya
        // no se usa deja abierta una puerta por la que nadie entra a propósito.
        $script_src = "'self' 'unsafe-inline' https://cdn.tailwindcss.com";
        $style_src  = "'self' 'unsafe-inline' https://cdn.tailwindcss.com";
        $connect    = "'self' https://cdn.tailwindcss.com";
    } else {
        // Sin CDN ni scripts en línea, `script-src` se reduce a 'self':
        // un XSS que logre inyectar un <script> no se ejecuta.
        $script_src = "'self'";
        $style_src  = "'self' 'unsafe-inline'";
        $connect    = "'self'";
    }

    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src $script_src; "
        . "style-src $style_src; "
        . "img-src 'self' data:; "
        . "font-src 'self' data:; "
        . "connect-src $connect; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "object-src 'none'"
    );

    // HSTS (solo si está en HTTPS)
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
?>
