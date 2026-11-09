<?php
/**
 * SEGUIMIENTO PÚBLICO CON SEGUNDO FACTOR
 *
 * Fase 2 · CLAUDE.md §10.2
 *
 * Corrige el hallazgo H-06: el radicado, por sí solo, daba acceso a los datos
 * del caso. Ahora el radicado es solo el primer factor; el segundo es un
 * código de un solo uso enviado al medio de contacto que la propia persona
 * registró en la denuncia.
 *
 * Tres principios que gobiernan este archivo:
 *
 *   1. RESPUESTA UNIFORME. El sistema jamás confirma ni desmiente que un
 *      radicado exista. Sin esto, el segundo factor no impide la enumeración:
 *      solo la encarece.
 *   2. LÍMITE EN TRES EJES. Por IP, por radicado y global.
 *   3. FALLA CERRADA. Ante cualquier error, se deniega el acceso.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// ============================================================================
// PARÁMETROS
// ============================================================================

const OTP_LONGITUD            = 6;      // dígitos
const OTP_VIGENCIA_MINUTOS    = 10;
const OTP_MAX_INTENTOS        = 3;      // por código
const OTP_MAX_SOLICITUDES_24H = 5;      // por radicado
const OTP_MAX_POR_IP          = 10;     // consultas por IP
const OTP_DECAIMIENTO_IP      = 60;     // minutos
const SESION_CONSULTA_MINUTOS = 15;

/**
 * Mensaje único de la primera pantalla.
 *
 * Es deliberadamente idéntico exista o no el radicado. No modificar sin
 * entender que cualquier diferencia —de texto, de tiempo de respuesta o de
 * código HTTP— reabre la enumeración.
 */
const MENSAJE_UNIFORME = 'Si el radicado existe, enviamos un código de verificación al medio de contacto registrado. Revisa tu correo o tus mensajes.';

// ============================================================================
// SOLICITUD DEL CÓDIGO
// ============================================================================

/**
 * Solicitar un código de verificación para un radicado
 *
 * Devuelve SIEMPRE la misma estructura, exista o no la denuncia. La única
 * variación posible es el bloqueo por exceso de solicitudes, que tampoco
 * revela existencia porque se aplica antes de consultar la base de datos.
 *
 * @param string $radicado Código introducido por la persona
 * @return array ['aceptada' => bool, 'mensaje' => string, 'bloqueada' => bool]
 */
function solicitar_codigo_seguimiento($radicado) {
    $radicado = strtoupper(trim((string)$radicado));
    $ip = get_user_ip();

    // ── Eje 1: límite por IP (se evalúa ANTES de tocar la base de datos) ──
    if (!rate_limit_check('seguimiento_ip:' . $ip, OTP_MAX_POR_IP, OTP_DECAIMIENTO_IP)) {
        audit_log(null, 'seguimiento_limite_ip', 'codigos_seguimiento', ['ip' => $ip]);
        return [
            'aceptada'  => false,
            'bloqueada' => true,
            'mensaje'   => 'Demasiadas consultas desde esta conexión. Intenta de nuevo más tarde o comunícate con la línea de atención.',
        ];
    }

    // ── Eje 2: límite global ──
    if (!rate_limit_check('seguimiento_global', 200, 60)) {
        log_error('Límite global de seguimiento alcanzado', 'warning');
        return [
            'aceptada'  => false,
            'bloqueada' => true,
            'mensaje'   => 'El servicio de consulta está temporalmente saturado. Intenta de nuevo en unos minutos.',
        ];
    }

    // Formato inválido: se responde igual que si no existiera
    if (!preg_match('/^BV-\d{8}-[A-Z0-9]{4,8}$/', $radicado)) {
        return ['aceptada' => true, 'bloqueada' => false, 'mensaje' => MENSAJE_UNIFORME];
    }

    $denuncia = db_select_one(
        'SELECT id, codigo, denunciante_correo_cifrado, denunciante_telefono_cifrado, canal_preferido
           FROM denuncias WHERE codigo = ? LIMIT 1',
        [$radicado]
    );

    // No existe: misma respuesta.
    //
    // Antes de devolverla se ejecuta un trabajo equivalente al del camino
    // exitoso —consulta de límite, generación y hash de un código— para que el
    // tiempo de respuesta no delate la existencia del radicado. La igualación
    // no es perfecta (el camino real hace además un INSERT y un UPDATE), pero
    // reduce la diferencia por debajo de la variación normal de la red; la
    // defensa efectiva contra un ataque estadístico de tiempos es el límite
    // por IP, que restringe el número de muestras obtenibles.
    if (!$denuncia) {
        db_select_one(
            'SELECT COUNT(*) AS total FROM codigos_seguimiento
              WHERE denuncia_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)',
            [0]
        );
        hash('sha256', bin2hex(random_bytes(16)) . generar_codigo_otp());

        audit_log(null, 'seguimiento_radicado_inexistente', 'denuncias', ['ip' => $ip]);

        return ['aceptada' => true, 'bloqueada' => false, 'mensaje' => MENSAJE_UNIFORME];
    }

    // ── Eje 3: límite por radicado (5 solicitudes cada 24 h) ──
    $solicitudes = db_select_one(
        'SELECT COUNT(*) AS total FROM codigos_seguimiento
          WHERE denuncia_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)',
        [$denuncia['id']]
    );

    if (($solicitudes['total'] ?? 0) >= OTP_MAX_SOLICITUDES_24H) {
        audit_log(null, 'seguimiento_limite_radicado', 'codigos_seguimiento', [
            'ip' => $ip,
        ], $denuncia['id']);
        // Respuesta uniforme también aquí: no se revela que el radicado existe
        return ['aceptada' => true, 'bloqueada' => false, 'mensaje' => MENSAJE_UNIFORME];
    }

    // RN-15 · el código va SOLO al canal que la persona eligió, nunca a ambos
    // (v3.1 §7.3). 'ninguno' = la persona pidió no ser contactada: no hay
    // segundo factor posible y la respuesta sigue siendo uniforme.
    $preferencia = $denuncia['canal_preferido'] ?: 'personal';

    if ($preferencia === 'ninguno') {
        audit_log(null, 'seguimiento_sin_canal', 'denuncias', ['ip' => $ip], $denuncia['id']);
        return ['aceptada' => true, 'bloqueada' => false, 'mensaje' => MENSAJE_UNIFORME];
    }

    // Transporte real del OTP: 'sms' o 'correo' (ENUM de codigos_seguimiento)
    $canal = $preferencia === 'sms' ? 'sms' : 'correo';

    if ($preferencia === 'sms') {
        $destino = descifrar_contacto($denuncia['denunciante_telefono_cifrado']);
    } elseif ($preferencia === 'institucional') {
        // El correo institucional vive en personas_caso (migración 0018)
        $persona = db_select_one(
            "SELECT correo_institucional_cifrado FROM personas_caso
              WHERE denuncia_id = ? AND papel = 'denunciante' LIMIT 1",
            [$denuncia['id']]
        );
        $destino = descifrar_contacto($persona['correo_institucional_cifrado'] ?? null);
    } else {
        // 'personal' y 'ambos': el personal es el canal primario del OTP
        $destino = descifrar_contacto($denuncia['denunciante_correo_cifrado']);
    }

    if ($destino === null || $destino === '') {
        // Sin medio de contacto no hay segundo factor posible.
        log_error('Denuncia sin medio de contacto para el segundo factor', 'warning');
        return ['aceptada' => true, 'bloqueada' => false, 'mensaje' => MENSAJE_UNIFORME];
    }

    // Invalida los códigos vigentes anteriores del mismo radicado
    db_execute(
        'UPDATE codigos_seguimiento SET consumido_at = NOW()
          WHERE denuncia_id = ? AND consumido_at IS NULL',
        [$denuncia['id']]
    );

    $codigo = generar_codigo_otp();
    $sal = bin2hex(random_bytes(16));

    $registro_id = db_insert_simple('codigos_seguimiento', [
        'denuncia_id'  => $denuncia['id'],
        'persona_id'   => null, // Fase 3: se vinculará a personas_caso
        'codigo_hash'  => hash('sha256', $sal . $codigo),
        'sal'          => $sal,
        'canal'        => $canal,
        'intentos'     => 0,
        'expira_at'    => date('Y-m-d H:i:s', time() + OTP_VIGENCIA_MINUTOS * 60),
        'ip_solicitud' => $ip,
        'created_at'   => date('Y-m-d H:i:s'),
    ]);

    if (!$registro_id) {
        log_error('No se pudo registrar el código de seguimiento', 'error');
        return ['aceptada' => true, 'bloqueada' => false, 'mensaje' => MENSAJE_UNIFORME];
    }

    // El despacho no viaja con datos del caso (RN-09)
    despachar_codigo_seguimiento($canal, $destino, $codigo, $denuncia['codigo']);

    // La auditoría NUNCA guarda el código ni el destino
    audit_log(null, 'seguimiento_codigo_emitido', 'codigos_seguimiento', [
        'canal' => $canal,
        'ip'    => $ip,
    ], $denuncia['id']);

    return ['aceptada' => true, 'bloqueada' => false, 'mensaje' => MENSAJE_UNIFORME];
}

/**
 * Generar el código de un solo uso
 *
 * random_int() es criptográfico (prohibición 9). Se conserva el cero a la
 * izquierda: el código es una cadena de dígitos, no un número.
 *
 * @return string
 */
function generar_codigo_otp() {
    $codigo = '';
    for ($i = 0; $i < OTP_LONGITUD; $i++) {
        $codigo .= (string)random_int(0, 9);
    }
    return $codigo;
}

/**
 * Entregar el código por el canal elegido
 *
 * CLAUDE.md §12 sitúa la integración con Microsoft Graph en la Fase 4. Hasta
 * entonces el código se encola sin despachar: la maquinaria queda completa y
 * probada, y solo falta conectar el transporte.
 *
 * El mensaje no contiene relato, tipo de violencia ni nombres (RN-09).
 *
 * @param string $canal 'correo' | 'sms'
 * @param string $destino Medio de contacto en claro
 * @param string $codigo Código de un solo uso
 * @param string $radicado Radicado, como referencia opaca
 * @return bool
 */
function despachar_codigo_seguimiento($canal, $destino, $codigo, $radicado) {
    if (!defined('SEGUIMIENTO_OTP_ACTIVO') || !SEGUIMIENTO_OTP_ACTIVO) {
        return false;
    }

    // Fase 4 · el código viaja por el Outbox, nunca de forma síncrona: una
    // llamada de red dentro de esta petición dejaría a la persona esperando.
    //
    // El código NO va en el payload en claro: se entrega como `secreto`, que
    // se cifra en reposo y se borra en cuanto el mensaje sale. Tampoco se
    // registra en ningún log (§11).
    //
    // El radicado sí viaja: es la referencia opaca del caso (ADR-06).
    $id = encolar_notificacion(
        $canal === 'sms' ? 'sms' : 'correo',
        $destino,
        'codigo_seguimiento',
        ['radicado' => $radicado],
        null,      // no se vincula la notificación al expediente: quien lea la
                   // cola no debe poder cruzar destinatarios con casos
        $codigo    // secreto cifrado en reposo
    );

    return $id !== false;
}

// ============================================================================
// VERIFICACIÓN DEL CÓDIGO
// ============================================================================

/**
 * Verificar el código introducido
 *
 * @param string $radicado
 * @param string $codigo
 * @return array ['valido' => bool, 'mensaje' => string]
 */
function verificar_codigo_seguimiento($radicado, $codigo) {
    $radicado = strtoupper(trim((string)$radicado));
    $codigo   = preg_replace('/\D+/', '', (string)$codigo);
    $ip       = get_user_ip();

    $generico = ['valido' => false, 'mensaje' => 'El código no es válido o ya expiró. Solicita uno nuevo.'];

    if (!rate_limit_check('seguimiento_verif:' . $ip, 15, 60)) {
        audit_log(null, 'seguimiento_limite_verificacion', 'codigos_seguimiento', ['ip' => $ip]);
        return ['valido' => false, 'mensaje' => 'Demasiados intentos. Intenta de nuevo más tarde.'];
    }

    if (strlen($codigo) !== OTP_LONGITUD) {
        return $generico;
    }

    $denuncia = db_select_one('SELECT id FROM denuncias WHERE codigo = ? LIMIT 1', [$radicado]);

    if (!$denuncia) {
        return $generico;
    }

    $registro = db_select_one(
        'SELECT * FROM codigos_seguimiento
          WHERE denuncia_id = ? AND consumido_at IS NULL AND expira_at > NOW()
          ORDER BY id DESC LIMIT 1',
        [$denuncia['id']]
    );

    if (!$registro) {
        return $generico;
    }

    // Límite de intentos por código: al agotarse, el código muere
    if ((int)$registro['intentos'] >= OTP_MAX_INTENTOS) {
        db_execute('UPDATE codigos_seguimiento SET consumido_at = NOW() WHERE id = ?', [$registro['id']]);
        audit_log(null, 'seguimiento_codigo_agotado', 'codigos_seguimiento', ['ip' => $ip], $denuncia['id']);
        return $generico;
    }

    db_execute('UPDATE codigos_seguimiento SET intentos = intentos + 1 WHERE id = ?', [$registro['id']]);

    $esperado = hash('sha256', $registro['sal'] . $codigo);

    if (!hash_equals($registro['codigo_hash'], $esperado)) {
        audit_log(null, 'seguimiento_codigo_incorrecto', 'codigos_seguimiento', [
            'ip' => $ip,
            'intento' => (int)$registro['intentos'] + 1,
        ], $denuncia['id']);
        return $generico;
    }

    // Un solo uso
    db_execute('UPDATE codigos_seguimiento SET consumido_at = NOW() WHERE id = ?', [$registro['id']]);

    abrir_sesion_consulta($denuncia['id'], $radicado);

    audit_log(null, 'seguimiento_acceso_concedido', 'denuncias', ['ip' => $ip], $denuncia['id']);

    return ['valido' => true, 'mensaje' => 'Acceso concedido.'];
}

// ============================================================================
// SESIÓN EFÍMERA DE CONSULTA
// ============================================================================

/**
 * Abrir la sesión de consulta
 *
 * Está ligada a un único radicado y no otorga ningún privilegio adicional.
 * No es una sesión de usuario: no puede convertirse en una.
 */
function abrir_sesion_consulta($denuncia_id, $radicado) {
    session_regenerate_id(true);

    $_SESSION['consulta_publica'] = [
        'denuncia_id' => (int)$denuncia_id,
        'radicado'    => $radicado,
        'expira'      => time() + SESION_CONSULTA_MINUTOS * 60,
    ];
}

/**
 * Obtener la sesión de consulta vigente
 *
 * @return array|null
 */
function sesion_consulta_vigente() {
    if (empty($_SESSION['consulta_publica'])) {
        return null;
    }

    $sesion = $_SESSION['consulta_publica'];

    if (($sesion['expira'] ?? 0) < time()) {
        cerrar_sesion_consulta();
        return null;
    }

    return $sesion;
}

/**
 * Cerrar la sesión de consulta
 */
function cerrar_sesion_consulta() {
    unset($_SESSION['consulta_publica']);
}

// ============================================================================
// PROYECCIÓN PÚBLICA DEL CASO
// ============================================================================

/**
 * Datos del caso visibles para la persona que consulta
 *
 * Proyección estricta. NO incluye el relato de los hechos.
 *
 * Motivo: mientras el sistema no distinga a la persona afectada de un tercero
 * que puso la queja (campo `es_denunciante_la_afectada`, Fase 3), no puede
 * garantizarse el art. 44 del Ac. 003 —el quejoso que no es sujeto procesal
 * solo conoce decisiones generales— y RN-07. Ante la duda, se muestra menos.
 *
 * @param int $denuncia_id
 * @return array|null
 */
function proyeccion_publica_caso($denuncia_id) {
    $denuncia = db_select_one(
        'SELECT id, codigo, estado, tipo_violencia, fecha_hechos, lugar_hechos, created_at, updated_at
           FROM denuncias WHERE id = ? LIMIT 1',
        [$denuncia_id]
    );

    if (!$denuncia) {
        return null;
    }

    // ── RN-07 · Ac. 003 art. 44 ──────────────────────────────────────────
    // Quien presentó la queja sin ser la persona afectada NO accede al
    // expediente: solo a las decisiones generales del proceso. Se le confirma
    // que el trámite existe y en qué estado va, nada más.
    //
    // Falla cerrada: si no consta quién es quien consulta, se aplica el
    // alcance restringido.
    $alcance = alcance_consulta_publica($denuncia_id);
    $denuncia['alcance'] = $alcance;

    if ($alcance !== 'afectada') {
        return [
            'id'         => $denuncia['id'],
            'codigo'     => $denuncia['codigo'],
            'estado'     => $denuncia['estado'],
            'created_at' => $denuncia['created_at'],
            'alcance'    => $alcance,
            'seguimientos' => [],
        ];
    }

    $denuncia['seguimientos'] = db_select(
        'SELECT s.contenido, s.created_at, u.nombre AS profesional
           FROM seguimientos s
           LEFT JOIN usuarios u ON s.usuario_id = u.id
          WHERE s.denuncia_id = ? AND s.privado = 0
          ORDER BY s.created_at DESC',
        [$denuncia_id]
    ) ?: [];

    return $denuncia;
}
