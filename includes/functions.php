<?php
/**
 * FUNCIONES HELPER GLOBALES
 *
 * Funciones de utilidad usadas en todo el sistema
 */

// ============================================================================
// FUNCIONES DE LOGGING
// ============================================================================

/**
 * Registrar log en archivo
 *
 * @param string $message Mensaje a registrar
 * @param string $level Nivel: debug|info|warning|error
 * @param array $context Contexto adicional
 */
function log_error($message, $level = 'error', $context = []) {
    if (!LOG_ENABLED) {
        return;
    }

    $log_file = LOG_PATH . '/app_' . date('Y-m-d') . '.log';

    $timestamp = date('Y-m-d H:i:s');
    $level = strtoupper($level);

    $log_entry = "[$timestamp] [$level] $message";

    if (!empty($context)) {
        $log_entry .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }

    $log_entry .= PHP_EOL;

    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// ============================================================================
// FUNCIONES DE SEGURIDAD
// ============================================================================

/**
 * Escapar HTML para prevenir XSS
 *
 * @param string $string Cadena a escapar
 * @return string
 */
function e($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Limpiar input de usuario
 *
 * CLAUDE.md prohibición 11: se escapa SOLO en salida, nunca en entrada.
 * Esta función normaliza, no escapa. El escapado HTML es responsabilidad
 * exclusiva de e() en el momento de imprimir.
 *
 * Antes aplicaba htmlspecialchars() aquí, lo que corrompía los datos
 * almacenados (hallazgo H-14): `Ana & José` quedaba `Ana &amp; Jos&eacute;`
 * en base de datos y se volvía a escapar al mostrarse.
 *
 * @param string $input Input a limpiar
 * @return string
 */
function clean_input($input) {
    $input = (string)$input;

    // Bytes nulos y caracteres de control (salvo tabulador y salto de línea)
    $input = str_replace("\0", '', $input);
    $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);

    return trim($input);
}

/**
 * ¿Los recursos del frontend se cargan desde CDN?
 *
 * H-21 reabierto por decisión de Elkin: el CSS local generado alcanzó el
 * 100 %% de cobertura de clases pero no logró equivalencia visual con
 * Tailwind. Ver views/componentes/assets.php para el detalle y el camino
 * de vuelta.
 *
 * @return bool
 */
function usar_assets_cdn() {
    return !defined('ASSETS_CDN') || ASSETS_CDN;
}

/**
 * Generar un nombre de archivo no adivinable
 *
 * CLAUDE.md prohibición 9: nunca uniqid() ni mt_rand() para valores de
 * seguridad. CLAUDE.md §8.4: el nombre en disco no debe permitir enumerar
 * la evidencia de otras personas.
 *
 * @param string $nombre_original Nombre subido por la persona usuaria
 * @return string Nombre seguro con la extensión validada
 */
function nombre_archivo_seguro($nombre_original) {
    $extension = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]/', '', $extension);

    return bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
}

/**
 * Sanitizar nombre de archivo
 *
 * @param string $filename Nombre de archivo
 * @return string
 */
function sanitize_filename($filename) {
    // Obtener extensión
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $name = pathinfo($filename, PATHINFO_FILENAME);

    // Limpiar nombre
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    $name = substr($name, 0, 100); // Limitar longitud

    return $name . '.' . $ext;
}

/**
 * Generar token aleatorio seguro
 *
 * @param int $length Longitud del token
 * @return string
 */
function generate_token($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Hashear contraseña
 *
 * @param string $password Contraseña en texto plano
 * @return string Hash de la contraseña
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 3
    ]);
}

/**
 * Verificar contraseña
 *
 * @param string $password Contraseña en texto plano
 * @param string $hash Hash almacenado
 * @return bool
 */
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Cifrar datos (AES-256-GCM)
 *
 * @param string $data Datos a cifrar
 * @return string|false Datos cifrados en base64 o false
 */
function encrypt_data($data) {
    if (empty(ENCRYPTION_KEY)) {
        log_error('ENCRYPTION_KEY not configured');
        return false;
    }

    $key = base64_decode(ENCRYPTION_KEY);
    $iv = random_bytes(12); // GCM usa IV de 12 bytes
    $tag = '';

    $ciphertext = openssl_encrypt(
        $data,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($ciphertext === false) {
        return false;
    }

    // Combinar IV + Tag + Ciphertext
    $encrypted = base64_encode($iv . $tag . $ciphertext);

    return $encrypted;
}

/**
 * Descifrar datos
 *
 * @param string $encrypted Datos cifrados en base64
 * @return string|false Datos descifrados o false
 */
function decrypt_data($encrypted) {
    if (empty(ENCRYPTION_KEY)) {
        log_error('ENCRYPTION_KEY not configured');
        return false;
    }

    $key = base64_decode(ENCRYPTION_KEY);
    $data = base64_decode($encrypted);

    // Extraer componentes
    $iv = substr($data, 0, 12);
    $tag = substr($data, 12, 16);
    $ciphertext = substr($data, 28);

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plaintext === false) {
        return false;
    }

    return $plaintext;
}

// ============================================================================
// FUNCIONES DE VALIDACIÓN
// ============================================================================

/**
 * Validar email
 *
 * @param string $email Email a validar
 * @return bool
 */
function valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validar teléfono colombiano
 *
 * @param string $phone Teléfono a validar
 * @return bool
 */
function valid_phone($phone) {
    // Formato: +57XXXXXXXXXX o 3XXXXXXXXX
    $pattern = '/^(\+57)?3[0-9]{9}$/';
    return preg_match($pattern, $phone) === 1;
}

/**
 * Validar extensión de archivo
 *
 * @param string $filename Nombre del archivo
 * @return bool
 */
function valid_file_extension($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowed = explode(',', strtolower(UPLOAD_ALLOWED_EXTENSIONS));
    return in_array($ext, $allowed);
}

/**
 * Validar tamaño de archivo
 *
 * @param int $size Tamaño en bytes
 * @return bool
 */
function valid_file_size($size) {
    return $size <= UPLOAD_MAX_SIZE;
}

// ============================================================================
// FUNCIONES DE URL Y REDIRECCIÓN
// ============================================================================

/**
 * Obtener URL base
 *
 * @param string $path Ruta adicional (opcional)
 * @return string
 */
function url($path = '') {
    $base = rtrim(APP_URL, '/');
    $path = ltrim($path, '/');
    return $path ? $base . '/' . $path : $base;
}

/**
 * Redireccionar a otra página
 *
 * @param string $url URL de destino
 * @param int $status_code Código HTTP (default: 302)
 */
function redirect($url, $status_code = 302) {
    header('Location: ' . $url, true, $status_code);
    exit;
}

/**
 * Redireccionar con mensaje flash
 *
 * @param string $url URL de destino
 * @param string $message Mensaje
 * @param string $type Tipo: success|error|warning|info
 */
function redirect_with_message($url, $message, $type = 'success') {
    set_flash($message, $type);
    redirect($url);
}

/**
 * Redireccionar atrás
 */
function redirect_back() {
    $referer = $_SERVER['HTTP_REFERER'] ?? url();
    redirect($referer);
}

// ============================================================================
// FUNCIONES DE SESIÓN Y FLASH MESSAGES
// ============================================================================

/**
 * Establecer mensaje flash
 *
 * @param string $message Mensaje
 * @param string $type Tipo: success|error|warning|info
 */
function set_flash($message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

/**
 * Obtener y limpiar mensaje flash
 *
 * @return array|null ['message' => string, 'type' => string]
 */
function get_flash() {
    if (isset($_SESSION['flash_message'])) {
        $flash = [
            'message' => $_SESSION['flash_message'],
            'type' => $_SESSION['flash_type'] ?? 'info'
        ];

        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);

        return $flash;
    }

    return null;
}

/**
 * Obtener valor de input anterior (para repoblar formularios)
 *
 * @param string $key Nombre del campo
 * @param mixed $default Valor por defecto
 * @return mixed
 */
function old($key, $default = '') {
    if (isset($_SESSION['old_input'][$key])) {
        $value = $_SESSION['old_input'][$key];
        return e($value);
    }
    return $default;
}

/**
 * Guardar input para repoblar formularios
 */
function save_old_input() {
    $_SESSION['old_input'] = $_POST;
}

/**
 * Limpiar old input
 */
function clear_old_input() {
    unset($_SESSION['old_input']);
}

// ============================================================================
// FUNCIONES DE FECHA Y HORA
// ============================================================================

/**
 * Formatear fecha para mostrar
 *
 * @param string $date Fecha en formato Y-m-d H:i:s
 * @param string $format Formato deseado
 * @return string
 */
function format_date($date, $format = 'd/m/Y') {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '';
    }

    $timestamp = strtotime($date);
    return date($format, $timestamp);
}

/**
 * Formatear fecha y hora
 *
 * @param string $datetime Fecha en formato Y-m-d H:i:s
 * @return string
 */
function format_datetime($datetime) {
    return format_date($datetime, 'd/m/Y H:i');
}

/**
 * Formatear un tamaño en bytes de forma legible
 *
 * `tamano` se guarda en bytes porque así lo entrega PHP y así se compara con
 * el límite de carga. Para leerlo hace falta traducirlo: «2,4 MB» dice algo,
 * «2517834» no.
 *
 * @param int|null $bytes
 * @return string
 */
function format_bytes($bytes) {
    $bytes = (int)$bytes;

    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $unidades = ['KB', 'MB', 'GB'];
    $valor = $bytes / 1024;
    $i = 0;

    while ($valor >= 1024 && $i < count($unidades) - 1) {
        $valor /= 1024;
        $i++;
    }

    return number_format($valor, $valor < 10 ? 1 : 0, ',', '.') . ' ' . $unidades[$i];
}

/**
 * Tiempo transcurrido (ej: "hace 2 horas")
 *
 * @param string $datetime Fecha en formato Y-m-d H:i:s
 * @return string
 */
function time_ago($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'hace ' . $diff . ' segundo' . ($diff != 1 ? 's' : '');
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return 'hace ' . $mins . ' minuto' . ($mins != 1 ? 's' : '');
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return 'hace ' . $hours . ' hora' . ($hours != 1 ? 's' : '');
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return 'hace ' . $days . ' día' . ($days != 1 ? 's' : '');
    } else {
        return format_datetime($datetime);
    }
}

// ============================================================================
// FUNCIONES DE ARCHIVOS
// ============================================================================

/**
 * Subir archivo
 *
 * @param array $file Archivo de $_FILES
 * @param string $directory Subdirectorio en uploads/
 * @return string|false Ruta relativa del archivo o false
 */
function upload_file($file, $directory = '') {
    // Validar que no haya errores
    if ($file['error'] !== UPLOAD_ERR_OK) {
        log_error('Upload error: ' . $file['error']);
        return false;
    }

    // Validar tamaño
    if (!valid_file_size($file['size'])) {
        log_error('File too large: ' . $file['size'] . ' bytes');
        return false;
    }

    // Validar extensión
    if (!valid_file_extension($file['name'])) {
        log_error('Invalid file extension: ' . $file['name']);
        return false;
    }

    // Sanitizar nombre
    $filename = sanitize_filename($file['name']);

    // Agregar timestamp para evitar colisiones
    $filename = time() . '_' . $filename;

    // Crear directorio si no existe
    $upload_dir = UPLOAD_PATH . '/' . $directory;
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $filepath = $upload_dir . '/' . $filename;

    // Mover archivo
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Retornar ruta relativa
        return $directory . '/' . $filename;
    }

    log_error('Failed to move uploaded file');
    return false;
}

/**
 * Eliminar archivo
 *
 * @param string $filepath Ruta relativa del archivo
 * @return bool
 */
function delete_file($filepath) {
    $fullpath = UPLOAD_PATH . '/' . $filepath;

    if (file_exists($fullpath)) {
        return unlink($fullpath);
    }

    return false;
}

// ============================================================================
// FUNCIONES DE AUDITORÍA
// ============================================================================

/**
 * Claves prohibidas en el payload de auditoría
 *
 * CLAUDE.md prohibición 1 y §11: la auditoría NO puede contener relato,
 * nombres, correos, teléfonos, tipo de violencia, lugar de los hechos
 * ni ningún dato que reconstruya el caso. Solo referencias opacas.
 */
const AUDITORIA_CLAVES_PROHIBIDAS = [
    'descripcion', 'relato', 'testigos', 'tipo_violencia', 'tipo_agresor',
    'agresor_nombre', 'agresor_identificacion', 'lugar_hechos', 'facultad',
    'sede', 'edificio', 'salon_area', 'denunciante_nombre', 'denunciante_contacto',
    'denunciante_email', 'denunciante_telefono', 'email', 'correo', 'telefono',
    'nombre', 'password', 'observaciones', 'contenido', 'join_url', 'codigo_otp',
];

/**
 * Depurar el payload de auditoría antes de persistirlo
 *
 * Falla cerrada: ante la duda, se elimina la clave.
 *
 * @param mixed $detalles Array o cadena
 * @return string|null JSON depurado
 */
function sanear_datos_auditoria($detalles) {
    if ($detalles === null || $detalles === '') {
        return null;
    }

    if (!is_array($detalles)) {
        return (string)$detalles;
    }

    $limpio = [];
    $descartadas = [];

    foreach ($detalles as $clave => $valor) {
        if (in_array(strtolower((string)$clave), AUDITORIA_CLAVES_PROHIBIDAS, true)) {
            $descartadas[] = $clave;
            continue;
        }
        $limpio[$clave] = is_scalar($valor) || $valor === null ? $valor : '[omitido]';
    }

    if (!empty($descartadas)) {
        $limpio['_campos_omitidos'] = count($descartadas);
    }

    return json_encode($limpio, JSON_UNESCAPED_UNICODE);
}

/**
 * Registrar acción de auditoría
 *
 * Escribe en la tabla `auditoria` (Ac. 003 art. 45: integridad y trazabilidad).
 *
 * @param int|null $usuario_id ID del usuario; 0 o null para actuaciones sin sesión
 * @param string $accion Acción realizada (verbo en snake_case)
 * @param string $tabla Entidad afectada
 * @param mixed $detalles Datos adicionales — se depuran con sanear_datos_auditoria()
 * @param int|null $registro_id ID del registro afectado
 * @return bool
 */
function audit_log($usuario_id, $accion, $tabla, $detalles = null, $registro_id = null) {
    if (!AUDIT_ENABLED) {
        return false;
    }

    // La FK apunta a usuarios(id): 0 no existe, debe viajar como NULL
    $usuario_id = (int)$usuario_id > 0 ? (int)$usuario_id : null;

    $data = [
        'usuario_id'  => $usuario_id,
        'accion'      => $accion,
        'tabla'       => $tabla,
        'registro_id' => $registro_id !== null ? (int)$registro_id : null,
        'datos'       => sanear_datos_auditoria($detalles),
        'ip'          => AUDIT_IP_TRACKING ? get_user_ip() : null,
        'user_agent'  => isset($_SERVER['HTTP_USER_AGENT'])
            ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500)
            : null,
        'created_at'  => date('Y-m-d H:i:s'),
    ];

    // RN-13 · encadenamiento por hash (Fase 6)
    if (auditoria_encadenada_disponible()) {
        $anterior = db_select_one('SELECT hash_actual FROM auditoria ORDER BY id DESC LIMIT 1');

        $hash_anterior = ($anterior && !empty($anterior['hash_actual']))
            ? $anterior['hash_actual']
            : str_repeat('0', 64); // registro génesis

        $data['hash_anterior'] = $hash_anterior;
        $data['hash_actual']   = calcular_hash_auditoria($data, $hash_anterior);
    }

    $resultado = db_insert_simple('auditoria', $data);

    if ($resultado === false) {
        // Una auditoría que falla en silencio fue el hallazgo H-02.
        // Nunca más: si no se puede auditar, queda constancia en el log de archivo.
        log_error('FALLO AL AUDITAR: ' . $accion . ' sobre ' . $tabla, 'error');
        return false;
    }

    return true;
}

/**
 * ¿La tabla `auditoria` tiene las columnas del encadenamiento?
 *
 * Se comprueba una sola vez por petición. Sin esta detección, desplegar el
 * código sin la migración 0006 rompería TODA la auditoría — que es exactamente
 * el hallazgo H-02 repetido, esta vez por la puerta de al lado.
 *
 * @return bool
 */
function auditoria_encadenada_disponible() {
    static $disponible = null;

    if ($disponible !== null) {
        return $disponible;
    }

    $columnas = db_select("SHOW COLUMNS FROM auditoria LIKE 'hash_actual'");
    $disponible = !empty($columnas);

    if (!$disponible) {
        log_error('La auditoría no está encadenada: falta la migración 0006 (RN-13)', 'warning');
    }

    return $disponible;
}

/**
 * Calcular el hash de un registro de auditoría
 *
 * El orden de los campos es parte del contrato: cambiarlo invalida toda la
 * cadena existente.
 *
 * @param array $data
 * @param string $hash_anterior
 * @return string
 */
function calcular_hash_auditoria(array $data, $hash_anterior) {
    // La marca temporal se normaliza a 19 caracteres («Y-m-d H:i:s»).
    // La migración 0006 declara `created_at` como DATETIME(6), así que la base
    // devuelve «.000000» al leer mientras que al insertar se envía sin
    // fracción. Sin esta normalización el hash recalculado nunca coincidiría
    // con el almacenado y la cadena se leería como rota siempre.
    $marca = substr((string)($data['created_at'] ?? ''), 0, 19);

    return hash('sha256', implode('|', [
        $hash_anterior,
        (string)($data['usuario_id'] ?? ''),
        (string)($data['accion'] ?? ''),
        (string)($data['tabla'] ?? ''),
        (string)($data['registro_id'] ?? ''),
        (string)($data['datos'] ?? ''),
        (string)($data['ip'] ?? ''),
        $marca,
    ]));
}

/**
 * Verificar la integridad de la cadena de auditoría
 *
 * Recorre los registros en orden y recalcula cada hash. Cualquier alteración
 * retroactiva —modificar un campo, borrar una fila, insertar una intermedia—
 * rompe la cadena a partir de ese punto y queda señalada.
 *
 * Ac. 003 art. 45 exige integridad y trazabilidad. Bajo seguimiento de la
 * Corte Constitucional, una auditoría que no se puede verificar no es prueba.
 *
 * @param int $desde_id
 * @param int $limite
 * @return array ['integra'=>bool,'verificados'=>int,'primer_fallo'=>int|null,'motivo'=>string]
 */
function verificar_cadena_auditoria($desde_id = 0, $limite = 10000) {
    if (!auditoria_encadenada_disponible()) {
        return ['integra' => false, 'verificados' => 0, 'primer_fallo' => null,
                'motivo' => 'La auditoría no está encadenada (falta la migración 0006).'];
    }

    $filas = db_select(
        'SELECT * FROM auditoria WHERE id > ? AND hash_actual IS NOT NULL
          ORDER BY id LIMIT ' . (int)$limite,
        [(int)$desde_id]
    ) ?: [];

    $verificados = 0;
    $hash_previo = null;

    foreach ($filas as $fila) {
        // El primer registro verificado establece el punto de partida: puede
        // no ser el génesis si se verifica un tramo.
        if ($hash_previo !== null && $fila['hash_anterior'] !== $hash_previo) {
            return [
                'integra' => false, 'verificados' => $verificados,
                'primer_fallo' => (int)$fila['id'],
                'motivo' => 'Ruptura de la cadena: el enlace al registro anterior no coincide. '
                          . 'Puede haberse eliminado o insertado un registro.',
            ];
        }

        $recalculado = calcular_hash_auditoria($fila, $fila['hash_anterior']);

        if (!hash_equals($fila['hash_actual'], $recalculado)) {
            return [
                'integra' => false, 'verificados' => $verificados,
                'primer_fallo' => (int)$fila['id'],
                'motivo' => 'El contenido del registro fue alterado después de su creación.',
            ];
        }

        $hash_previo = $fila['hash_actual'];
        $verificados++;
    }

    return ['integra' => true, 'verificados' => $verificados, 'primer_fallo' => null, 'motivo' => ''];
}

/**
 * Obtener IP del usuario
 *
 * @return string
 */
function get_user_ip() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ============================================================================
// FUNCIONES DE PAGINACIÓN
// ============================================================================

/**
 * Generar HTML de paginación
 *
 * @param int $total Total de registros
 * @param int $per_page Registros por página
 * @param int $current_page Página actual
 * @param string $base_url URL base (sin parámetro page)
 * @return string HTML de paginación
 */
function paginate($total, $per_page, $current_page, $base_url) {
    $total_pages = ceil($total / $per_page);

    if ($total_pages <= 1) {
        return '';
    }

    $html = '<nav class="flex justify-center mt-6"><ul class="flex space-x-2">';

    // Botón anterior
    if ($current_page > 1) {
        $prev_url = $base_url . '?page=' . ($current_page - 1);
        $html .= '<li><a href="' . $prev_url . '" class="px-3 py-2 bg-gray-200 rounded hover:bg-gray-300">Anterior</a></li>';
    }

    // Páginas
    for ($i = 1; $i <= $total_pages; $i++) {
        $active = $i === $current_page ? 'bg-purple-600 text-white' : 'bg-gray-200 hover:bg-gray-300';
        $page_url = $base_url . '?page=' . $i;
        $html .= '<li><a href="' . $page_url . '" class="px-3 py-2 ' . $active . ' rounded">' . $i . '</a></li>';
    }

    // Botón siguiente
    if ($current_page < $total_pages) {
        $next_url = $base_url . '?page=' . ($current_page + 1);
        $html .= '<li><a href="' . $next_url . '" class="px-3 py-2 bg-gray-200 rounded hover:bg-gray-300">Siguiente</a></li>';
    }

    $html .= '</ul></nav>';

    return $html;
}

// ============================================================================
// FUNCIONES MISCELÁNEAS
// ============================================================================

/**
 * Alfabeto del radicado
 *
 * 31 símbolos sin caracteres ambiguos (se excluyen 0, O, 1, I, L).
 * Motivo: el radicado se dicta por teléfono a personas en crisis.
 */
const RADICADO_ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

/**
 * Generar código único de denuncia (radicado)
 *
 * Formato: BV-AAAAMMDD-XXXXXX   ·   Ejemplo: BV-20260726-K7QM2X
 *
 * CLAUDE.md §8.2 · corrige el hallazgo H-06.
 *
 * El formato anterior era `BV-` + fecha + mt_rand(1,9999): 9.999 combinaciones
 * por día, enumerables por un script en minutos, y mt_rand no es un generador
 * criptográfico (prohibición 9). Ahora son 31^6 ≈ 887 millones de combinaciones
 * por día, obtenidas con el CSPRNG del sistema.
 *
 * Se usa random_int() en lugar de indexar random_bytes() directamente porque
 * el alfabeto tiene 31 símbolos: el módulo introduciría sesgo estadístico.
 *
 * @param int $max_intentos Reintentos ante colisión con un radicado existente
 * @return string
 * @throws RuntimeException Si no se logra un código único
 */
function generate_codigo_denuncia($max_intentos = 5) {
    $ultimo_maximo = strlen(RADICADO_ALFABETO) - 1;

    for ($intento = 1; $intento <= $max_intentos; $intento++) {
        $sufijo = '';
        for ($i = 0; $i < 6; $i++) {
            $sufijo .= RADICADO_ALFABETO[random_int(0, $ultimo_maximo)];
        }

        $codigo = 'BV-' . date('Ymd') . '-' . $sufijo;

        // H-07: `denuncias.codigo` es UNIQUE. Sin esta verificación, una
        // colisión hace fallar el INSERT y la persona ve un error genérico
        // después de haber escrito su relato.
        if (!db_exists('denuncias', ['codigo' => $codigo])) {
            return $codigo;
        }

        log_error('Colisión de radicado en el intento ' . $intento, 'warning');
    }

    throw new RuntimeException('No fue posible generar un radicado único');
}

// ─────────────────────────────────────────────────────────────────────────────
// Aquí vivía `dd()`, que volcaba cualquier variable al navegador con var_dump()
// y terminaba la ejecución. Se retiró por dos razones:
//
//   · No la usaba nadie. Era una herramienta de depuración olvidada en el
//     código de producción.
//   · Su única defensa era `APP_DEBUG`. En este sistema, la variable que
//     alguien depuraría es una fila de `denuncias`, y volcarla imprime el
//     relato completo en pantalla. Basta un despliegue con la constante mal
//     puesta para que la depuración se convierta en una filtración.
//
// Para depurar hay `log_error()`, que escribe a un archivo con permisos y no
// se lo enseña a nadie que pase por delante.
// ─────────────────────────────────────────────────────────────────────────────

