<?php
/**
 * CLIENTE DE MICROSOFT GRAPH
 *
 * Fase 4 · CLAUDE.md §12.1 · ADR-07
 *
 * cURL nativo, sin librerías. Flujo `client_credentials`. No rompe la política
 * de cero dependencias (§5): son unas 150 líneas contra un SDK completo.
 *
 * Por qué Graph y no SMTP (ADR-07): la Universidad ya opera Microsoft 365, se
 * requiere Teams de todos modos, y evita almacenar credenciales SMTP.
 *
 * ⚠ ESTADO: este cliente NO ha podido probarse contra la API real —requiere
 *   registro de aplicación en Azure AD, que es una acción institucional. Lo
 *   que sí está probado es todo lo que ocurre antes de la llamada: la
 *   construcción del mensaje, la validación RN-09 y RN-04, el Outbox y los
 *   reintentos. La primera ejecución real debe hacerse contra un buzón de
 *   pruebas, nunca contra un caso vivo.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * ¿Está configurada y activa la integración con Graph?
 *
 * @return bool
 */
function graph_activo() {
    return defined('GRAPH_ACTIVO') && GRAPH_ACTIVO
        && defined('GRAPH_TENANT_ID')     && GRAPH_TENANT_ID !== ''
        && defined('GRAPH_CLIENT_ID')     && GRAPH_CLIENT_ID !== ''
        && defined('GRAPH_CLIENT_SECRET') && GRAPH_CLIENT_SECRET !== ''
        && defined('GRAPH_REMITENTE')     && GRAPH_REMITENTE !== '';
}

/**
 * Obtener un token de aplicación, con caché cifrada
 *
 * El token se guarda cifrado con AES-256-GCM y vive 55 minutos, por debajo de
 * los 60 que dura del lado de Microsoft. Un token en claro en disco es una
 * credencial en claro en disco.
 *
 * @return string|false
 */
function graph_token() {
    if (!graph_activo()) {
        return false;
    }

    $ruta_cache = BASE_PATH . '/cache/graph_token.json';

    if (is_file($ruta_cache)) {
        $cache = json_decode((string)file_get_contents($ruta_cache), true);

        if (is_array($cache) && ($cache['expira'] ?? 0) > time() + 60) {
            $token = descifrar_contacto($cache['token'] ?? null);
            if ($token !== null && $token !== '') {
                return $token;
            }
        }
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode(GRAPH_TENANT_ID) . '/oauth2/v2.0/token';

    $respuesta = graph_peticion_http($url, 'POST', http_build_query([
        'client_id'     => GRAPH_CLIENT_ID,
        'client_secret' => GRAPH_CLIENT_SECRET,
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ]), ['Content-Type: application/x-www-form-urlencoded'], false);

    if ($respuesta === false || empty($respuesta['cuerpo']['access_token'])) {
        // El secreto del cliente NUNCA se registra
        log_error('No se pudo obtener el token de Graph (HTTP '
            . ($respuesta['codigo'] ?? 'sin respuesta') . ')', 'error');
        return false;
    }

    $token = $respuesta['cuerpo']['access_token'];

    @file_put_contents($ruta_cache, json_encode([
        'token'  => cifrar_contacto($token),
        'expira' => time() + 55 * 60,
    ]), LOCK_EX);
    @chmod($ruta_cache, 0600);

    return $token;
}

/**
 * Petición HTTP con cURL nativo
 *
 * @param string $url
 * @param string $metodo
 * @param string|null $cuerpo
 * @param array $cabeceras
 * @param bool $json_entrada El cuerpo ya viene serializado como JSON
 * @return array|false ['codigo' => int, 'cuerpo' => array|string]
 */
function graph_peticion_http($url, $metodo = 'GET', $cuerpo = null, array $cabeceras = [], $json_entrada = true) {
    if (!function_exists('curl_init')) {
        log_error('La extensión cURL no está disponible', 'error');
        return false;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $cabeceras,
    ]);

    if ($cuerpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
    }

    $respuesta = curl_exec($ch);
    $codigo    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error     = curl_error($ch);

    curl_close($ch);

    if ($respuesta === false) {
        log_error('Fallo de red en la petición a Graph: ' . $error, 'error');
        return false;
    }

    $decodificado = json_decode((string)$respuesta, true);

    return [
        'codigo' => $codigo,
        'cuerpo' => $decodificado !== null ? $decodificado : (string)$respuesta,
    ];
}

/**
 * Llamada autenticada a la API de Graph
 *
 * @return array|false
 */
function graph_llamar($ruta, $metodo = 'GET', array $datos = null) {
    $token = graph_token();

    if ($token === false) {
        return false;
    }

    $cabeceras = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $cuerpo = $datos !== null ? json_encode($datos, JSON_UNESCAPED_UNICODE) : null;

    return graph_peticion_http('https://graph.microsoft.com/v1.0' . $ruta, $metodo, $cuerpo, $cabeceras);
}

// ============================================================================
// CORREO
// ============================================================================

/**
 * Enviar un correo
 *
 * Permiso `Mail.Send` (application), restringido con *Application Access
 * Policy* a un único buzón institucional. Sin esa política, el permiso alcanza
 * a TODOS los buzones del inquilino.
 *
 * @return bool
 */
function graph_enviar_correo($destinatario, $asunto, $cuerpo) {
    if (!graph_activo()) {
        // Sin canal configurado la notificación permanece pendiente: no se
        // marca como enviada ni como fallida. Cuando Graph se active, saldrá.
        return false;
    }

    $respuesta = graph_llamar(
        '/users/' . rawurlencode(GRAPH_REMITENTE) . '/sendMail',
        'POST',
        [
            'message' => [
                'subject' => $asunto,
                'body'    => ['contentType' => 'Text', 'content' => $cuerpo],
                'toRecipients' => [
                    ['emailAddress' => ['address' => $destinatario]],
                ],
            ],
            'saveToSentItems' => false, // no se deja copia en Elementos enviados
        ]
    );

    if ($respuesta === false) {
        return false;
    }

    if ($respuesta['codigo'] === 202) {
        return true;
    }

    // El mensaje de error de Graph puede incluir el destinatario: no se registra
    log_error('Graph rechazó el envío de correo (HTTP ' . $respuesta['codigo'] . ')', 'error');

    return false;
}

// ============================================================================
// REUNIONES EN LÍNEA
// ============================================================================

/**
 * Configuración fija de toda reunión creada por el sistema
 *
 * CLAUDE.md §12.2. No es configurable desde la interfaz: son garantías, no
 * preferencias.
 */
function graph_configuracion_reunion($permitir_grabacion = false) {
    return [
        'allowRecording'           => (bool)$permitir_grabacion, // exige consentimiento registrado
        'allowTranscription'       => (bool)$permitir_grabacion,
        'lobbyBypassSettings'      => ['scope' => 'organizer'],  // sala de espera activa
        'allowAttendeeToEnableMic' => true,
        'isEntryExitAnnounced'     => false,
    ];
}

/**
 * Crear una reunión de Teams
 *
 * ⚠ El asunto usa una plantilla FIJA (RN-09): nunca contiene tipo de
 * violencia, nombres ni relato. El asunto de una reunión es visible en el
 * calendario de todos los participantes y en las notificaciones del teléfono.
 *
 * @return array|false ['id' => string, 'join_url' => string]
 */
function graph_crear_reunion($organizador_upn, $radicado, $area, $inicia_at, $termina_at, $permitir_grabacion = false) {
    if (!graph_activo()) {
        return false;
    }

    // Plantilla fija. No admite interpolación de datos del caso.
    $asunto = "Acompañamiento · {$radicado} · {$area}";

    $datos = array_merge([
        'subject'      => $asunto,
        'startDateTime' => gmdate('c', strtotime($inicia_at)),
        'endDateTime'   => gmdate('c', strtotime($termina_at)),
    ], graph_configuracion_reunion($permitir_grabacion));

    $respuesta = graph_llamar(
        '/users/' . rawurlencode($organizador_upn) . '/onlineMeetings',
        'POST',
        $datos
    );

    if ($respuesta === false || !in_array($respuesta['codigo'], [200, 201], true)) {
        log_error('Graph rechazó la creación de la reunión (HTTP '
            . ($respuesta['codigo'] ?? 'sin respuesta') . ')', 'error');
        return false;
    }

    return [
        'id'       => $respuesta['cuerpo']['id'] ?? '',
        'join_url' => $respuesta['cuerpo']['joinWebUrl'] ?? '',
    ];
}

/**
 * Cancelar una reunión
 *
 * @return bool
 */
function graph_cancelar_reunion($organizador_upn, $reunion_id) {
    if (!graph_activo()) {
        return false;
    }

    $respuesta = graph_llamar(
        '/users/' . rawurlencode($organizador_upn) . '/onlineMeetings/' . rawurlencode($reunion_id),
        'DELETE'
    );

    return $respuesta !== false && in_array($respuesta['codigo'], [200, 204], true);
}
