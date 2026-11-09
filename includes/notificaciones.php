<?php
/**
 * NOTIFICACIONES · PATRÓN OUTBOX
 *
 * Fase 4 · CLAUDE.md §12.3 · ADR-06 y ADR-08
 * Implementa RN-09 (notificación sin carga).
 *
 * Dos principios gobiernan este archivo:
 *
 *   1. EL CORREO SOLO TRANSPORTA EL RADICADO. Nunca relato, nombres, tipo de
 *      violencia, lugar ni adjuntos. Enviar el contenido del caso crea copias
 *      no controladas en buzones, teléfonos, respaldos y reenvíos — un archivo
 *      sombra del expediente que choca con la reserva del Ac. 003 art. 47.
 *
 *   2. NINGUNA LLAMADA DE RED BLOQUEA UNA DENUNCIA. Se encola y un cron
 *      despacha. Si Graph tarda u ocho segundos o falla, la persona vería un
 *      error después de haber escrito su relato.
 *
 * La validación de RN-09 es DURA y por lista blanca: lo que no está permitido
 * explícitamente, se rechaza. Una lista negra de palabras prohibidas siempre
 * se queda corta.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// ============================================================================
// RN-09 · LISTA BLANCA DE CONTENIDO
// ============================================================================

/**
 * Únicas claves que pueden viajar en una notificación
 *
 * Añadir una clave aquí es una decisión de diseño con consecuencias legales.
 * Antes de hacerlo: ¿ese dato permitiría a quien lea el buzón reconstruir
 * algo del caso o identificar a alguien? Si la respuesta no es un no rotundo,
 * no se añade.
 */
const NOTIFICACION_CLAVES_PERMITIDAS = [
    'radicado',      // referencia opaca
    'banda_riesgo',  // alto | medio | moderado
    'plazo',         // fecha límite
    'dias_habiles',  // número
    'enlace',        // URL que exige sesión autenticada
    'area',          // psicología | jurídica | trabajo social
    'motivo',        // etiqueta corta de plantilla, no texto libre
    'fecha',         // fecha del encuentro
    'hora',
    'modalidad',     // presencial | virtual | telefónico
];

/** Plantillas admitidas. El texto vive en el código, no en la base de datos */
const PLANTILLAS_NOTIFICACION = [
    'caso_asignado'        => 'Nuevo caso asignado',
    'plazo_por_vencer'     => 'Plazo próximo a vencer',
    'plazo_vencido'        => 'Plazo vencido',
    'medida_por_ejecutar'  => 'Medida pendiente de ejecución',
    'encuentro_agendado'   => 'Encuentro agendado',
    'encuentro_cancelado'  => 'Encuentro cancelado',
    'codigo_seguimiento'   => 'Código de verificación',
    'revision_trimestral'  => 'Revisión trimestral pendiente',
    'recuperar_contrasena' => 'Recuperación de contraseña',
    // v3.1
    'compromiso_asignado'  => 'Compromiso asignado',
    'solicitud_estado'     => 'Solicitud de estado del caso',
];

/**
 * Validar que un payload cumple RN-09
 *
 * @param array $payload
 * @return array ['valido' => bool, 'motivo' => string]
 */
function validar_payload_notificacion(array $payload) {
    foreach (array_keys($payload) as $clave) {
        if (!in_array($clave, NOTIFICACION_CLAVES_PERMITIDAS, true)) {
            return [
                'valido' => false,
                'motivo' => "RN-09: la clave «{$clave}» no está permitida en una notificación. "
                          . 'El correo solo transporta el radicado, el plazo y un enlace autenticado.',
            ];
        }
    }

    // Segunda barrera: aunque la clave sea válida, el VALOR no puede ser un
    // texto largo. Un campo `motivo` de 400 caracteres es un relato disfrazado.
    foreach ($payload as $clave => $valor) {
        if (!is_scalar($valor) && $valor !== null) {
            return ['valido' => false, 'motivo' => "RN-09: el valor de «{$clave}» debe ser escalar."];
        }

        if (is_string($valor) && strlen($valor) > 120) {
            return [
                'valido' => false,
                'motivo' => "RN-09: el valor de «{$clave}» excede 120 caracteres. "
                          . 'Las notificaciones no transportan texto libre.',
            ];
        }
    }

    return ['valido' => true, 'motivo' => ''];
}

// ============================================================================
// ENCOLADO
// ============================================================================

/**
 * Encolar una notificación
 *
 * @param string $tipo correo|teams_evento|teams_cancelacion|sms
 * @param string $destinatario
 * @param string $plantilla Clave de PLANTILLAS_NOTIFICACION
 * @param array $payload Solo claves de la lista blanca
 * @param int|null $denuncia_id
 * @param string|null $secreto Valor de un solo uso (código OTP). Se cifra.
 * @return int|false
 */
function encolar_notificacion($tipo, $destinatario, $plantilla, array $payload,
                              $denuncia_id = null, $secreto = null, array $opciones = []) {

    if (!isset(PLANTILLAS_NOTIFICACION[$plantilla])) {
        log_error('Plantilla de notificación desconocida: ' . $plantilla, 'error');
        return false;
    }

    $validacion = validar_payload_notificacion($payload);

    if (!$validacion['valido']) {
        // Se rechaza y se deja constancia. Que una notificación no salga es
        // un problema; que salga con el relato dentro es un daño irreversible.
        log_error('Notificación rechazada — ' . $validacion['motivo'], 'error');

        audit_log(null, 'notificacion_rechazada_rn09', 'notificaciones_salientes', [
            'plantilla' => $plantilla,
        ], $denuncia_id);

        return false;
    }

    $id = db_insert_simple('notificaciones_salientes', [
        'tipo'            => $tipo,
        'destinatario'    => substr($destinatario, 0, 255),
        'plantilla'       => $plantilla,
        'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
        // El código de un solo uso se cifra: aunque el Outbox es una tabla y
        // no un log, no debe conservar secretos en claro mientras espera turno
        'payload_cifrado' => $secreto !== null ? cifrar_contacto($secreto) : null,
        'denuncia_id'     => $denuncia_id !== null ? (int)$denuncia_id : null,
        'estado'          => 'pendiente',
        'intentos'        => 0,
        'proximo_intento' => date('Y-m-d H:i:s'),
        'created_at'      => date('Y-m-d H:i:s'),
        // RN-15 (migración 0018): cada despacho registra a qué tipo de
        // dirección va y a qué grupo de envío pertenece
        'destinatario_tipo' => in_array($opciones['destinatario_tipo'] ?? '', ['institucional', 'personal', 'sms'], true)
                             ? $opciones['destinatario_tipo'] : ($tipo === 'sms' ? 'sms' : 'institucional'),
        'grupo_envio'       => $opciones['grupo_envio'] ?? null,
    ]);

    if (!$id) {
        log_error('No se pudo encolar la notificación', 'error');
        return false;
    }

    // La auditoría no registra el destinatario (§11: nunca correos)
    audit_log(null, 'notificacion_encolada', 'notificaciones_salientes', [
        'plantilla' => $plantilla,
        'tipo'      => $tipo,
    ], $denuncia_id);

    return (int)$id;
}

// ============================================================================
// COMPOSICIÓN DEL MENSAJE
// ============================================================================

/**
 * Construir asunto y cuerpo a partir de la plantilla
 *
 * Las plantillas son fijas. No existe forma de que un texto escrito por una
 * persona llegue a un correo saliente.
 *
 * @return array ['asunto' => string, 'cuerpo' => string]
 */
function componer_notificacion($plantilla, array $payload, $secreto = null) {
    $radicado = $payload['radicado'] ?? 'sin radicado';
    $enlace   = $payload['enlace'] ?? url('?page=login');

    $pie = "\n\nEste mensaje no contiene información del caso.\n"
         . "Botón Violeta · Universidad Distrital Francisco José de Caldas";

    switch ($plantilla) {

        case 'codigo_seguimiento':
            return [
                'asunto' => 'Código de verificación · Botón Violeta',
                'cuerpo' => "Tu código de verificación es: " . ($secreto ?? '------') . "\n\n"
                          . "Vence en 10 minutos y solo puede usarse una vez.\n"
                          . "Si no solicitaste este código, ignora este mensaje.\n"
                          . "Nadie del equipo te pedirá este código por teléfono." . $pie,
            ];

        case 'caso_asignado':
            return [
                'asunto' => "[BV] Nuevo caso asignado · {$radicado}",
                'cuerpo' => "Se registró un caso que requiere su atención.\n\n"
                          . "Radicado: {$radicado}\n"
                          . 'Prioridad de riesgo: ' . strtoupper($payload['banda_riesgo'] ?? 'por valorar') . "\n"
                          . 'Vence: ' . ($payload['plazo'] ?? 'por definir')
                          . (isset($payload['dias_habiles']) ? " ({$payload['dias_habiles']} días hábiles)" : '') . "\n\n"
                          . "Abrir en el sistema: {$enlace}\n"
                          . "El enlace requiere iniciar sesión." . $pie,
            ];

        case 'plazo_por_vencer':
        case 'plazo_vencido':
            $titulo = $plantilla === 'plazo_vencido' ? 'Plazo VENCIDO' : 'Plazo próximo a vencer';
            return [
                'asunto' => "[BV] {$titulo} · {$radicado}",
                'cuerpo' => "{$titulo}.\n\nRadicado: {$radicado}\n"
                          . 'Fecha límite: ' . ($payload['plazo'] ?? 'no definida') . "\n\n"
                          . "Abrir en el sistema: {$enlace}" . $pie,
            ];

        case 'medida_por_ejecutar':
            return [
                'asunto' => "[BV] Medida pendiente de ejecución · {$radicado}",
                'cuerpo' => "Su unidad tiene una medida pendiente de implementar.\n\n"
                          . "Radicado: {$radicado}\n"
                          . 'Plazo: ' . ($payload['plazo'] ?? 'por definir') . "\n\n"
                          . "Ver la orden y registrar la constancia: {$enlace}" . $pie,
            ];

        case 'encuentro_agendado':
        case 'encuentro_cancelado':
            $verbo = $plantilla === 'encuentro_cancelado' ? 'cancelado' : 'agendado';
            return [
                'asunto' => "[BV] Encuentro {$verbo} · {$radicado}",
                'cuerpo' => "Se ha {$verbo} un encuentro de acompañamiento.\n\n"
                          . "Radicado: {$radicado}\n"
                          . 'Fecha: ' . ($payload['fecha'] ?? '') . ' ' . ($payload['hora'] ?? '') . "\n"
                          . 'Modalidad: ' . ($payload['modalidad'] ?? '') . "\n\n"
                          . "Detalles y enlace de acceso: {$enlace}" . $pie,
            ];

        case 'recuperar_contrasena':
            // El enlace ES el secreto: viaja como `$secreto`, cifrado en la
            // cola y borrado en cuanto el mensaje sale. Nunca en el payload
            // ni en ningún log.
            return [
                'asunto' => 'Restablecer contraseña · Botón Violeta',
                'cuerpo' => "Recibimos una solicitud para restablecer tu contraseña.\n\n"
                          . "Abre este enlace para definir una nueva:\n"
                          . ($secreto ?? $enlace) . "\n\n"
                          . "El enlace vence en una hora y solo puede usarse una vez.\n"
                          . "Si no lo solicitaste, ignora este mensaje: tu contraseña no cambiará."
                          . $pie,
            ];

        case 'revision_trimestral':
            return [
                'asunto' => "[BV] Revisión trimestral pendiente · {$radicado}",
                'cuerpo' => "Corresponde revisar las medidas adoptadas (Acuerdo 002 de 2026, art. 30).\n\n"
                          . "Radicado: {$radicado}\n\nAbrir en el sistema: {$enlace}" . $pie,
            ];

        case 'compromiso_asignado':
            return [
                'asunto' => "[BV] Compromiso asignado · {$radicado}",
                'cuerpo' => "Se registró un compromiso a su cargo.\n\n"
                          . "Radicado: {$radicado}\n"
                          . 'Vence: ' . ($payload['plazo'] ?? 'por definir') . "\n\n"
                          . "Ver el compromiso y registrar la constancia: {$enlace}" . $pie,
            ];

        case 'solicitud_estado':
            return [
                'asunto' => "[BV] Solicitud de estado del caso · {$radicado}",
                'cuerpo' => "En el seguimiento trimestral se solicita a su dependencia informar "
                          . "el estado de la actuación a su cargo.\n\n"
                          . "Radicado: {$radicado}\n"
                          . 'Responder antes de: ' . ($payload['plazo'] ?? 'por definir') . "\n\n"
                          . "Responder desde su bandeja: {$enlace}" . $pie,
            ];
    }

    return [
        'asunto' => "[BV] Notificación · {$radicado}",
        'cuerpo' => "Tiene una notificación pendiente en el sistema.\n\n{$enlace}" . $pie,
    ];
}

// ============================================================================
// DESPACHO
// ============================================================================

/**
 * Intervalos de reintento en minutos (retroceso exponencial)
 */
const BACKOFF_MINUTOS = [1, 2, 4, 8, 16];
const MAX_INTENTOS_NOTIFICACION = 5;

/**
 * Despachar las notificaciones pendientes
 *
 * @param callable|null $transporte function(string $destinatario, string $asunto,
 *                                           string $cuerpo, string $tipo): bool
 *                                  Si es null se usa Graph. Inyectable para pruebas.
 * @param int $limite Máximo de notificaciones por corrida
 * @return array Resumen
 */
function despachar_notificaciones($transporte = null, $limite = 50) {
    $pendientes = db_select(
        "SELECT * FROM notificaciones_salientes
          WHERE estado = 'pendiente' AND proximo_intento <= NOW()
          ORDER BY id LIMIT " . (int)$limite
    ) ?: [];

    $resumen = ['procesadas' => 0, 'enviadas' => 0, 'reintentos' => 0, 'fallidas' => 0];

    foreach ($pendientes as $notificacion) {
        $resumen['procesadas']++;

        $payload = json_decode($notificacion['payload'], true) ?: [];
        $secreto = $notificacion['payload_cifrado'] !== null
            ? descifrar_contacto($notificacion['payload_cifrado'])
            : null;

        $mensaje = componer_notificacion($notificacion['plantilla'], $payload, $secreto);

        try {
            if ($transporte !== null) {
                $enviado = (bool)$transporte(
                    $notificacion['destinatario'],
                    $mensaje['asunto'],
                    $mensaje['cuerpo'],
                    $notificacion['tipo']
                );
            } else {
                $enviado = graph_enviar_correo(
                    $notificacion['destinatario'],
                    $mensaje['asunto'],
                    $mensaje['cuerpo']
                );
            }
        } catch (Throwable $e) {
            $enviado = false;
            log_error('Error al despachar notificación: ' . $e->getMessage(), 'error');
        }

        if ($enviado) {
            // El secreto se borra en cuanto deja de ser necesario
            db_update_simple('notificaciones_salientes', [
                'estado'          => 'enviado',
                'enviado_at'      => date('Y-m-d H:i:s'),
                'payload_cifrado' => null,
                'ultimo_error'    => null,
            ], ['id' => $notificacion['id']]);

            audit_log(null, 'notificacion_enviada', 'notificaciones_salientes', [
                'plantilla' => $notificacion['plantilla'],
            ], $notificacion['denuncia_id']);

            $resumen['enviadas']++;
            continue;
        }

        $intentos = (int)$notificacion['intentos'] + 1;

        if ($intentos >= MAX_INTENTOS_NOTIFICACION) {
            db_update_simple('notificaciones_salientes', [
                'estado'          => 'fallido',
                'intentos'        => $intentos,
                'payload_cifrado' => null, // no se conserva el secreto de un envío muerto
                'ultimo_error'    => 'Agotados los ' . MAX_INTENTOS_NOTIFICACION . ' intentos',
            ], ['id' => $notificacion['id']]);

            log_error('Notificación fallida definitivamente: ' . $notificacion['id'], 'error');

            audit_log(null, 'notificacion_fallida', 'notificaciones_salientes', [
                'plantilla' => $notificacion['plantilla'],
                'intentos'  => $intentos,
            ], $notificacion['denuncia_id']);

            $resumen['fallidas']++;
            continue;
        }

        $espera = BACKOFF_MINUTOS[$intentos - 1] ?? end(BACKOFF_MINUTOS);

        db_update_simple('notificaciones_salientes', [
            'intentos'        => $intentos,
            'proximo_intento' => date('Y-m-d H:i:s', time() + $espera * 60),
            'ultimo_error'    => 'Reintento programado en ' . $espera . ' minutos',
        ], ['id' => $notificacion['id']]);

        $resumen['reintentos']++;
    }

    return $resumen;
}

// ============================================================================
// ENRUTAMIENTO DEL DESTINATARIO
// ============================================================================

/**
 * Resolver a quién notificar un caso
 *
 * No es un correo fijo en config.php: se busca por unidad y por vía procesal,
 * para que un caso llegue a quien corresponde y no a un buzón único que se
 * convierte en cuello de botella.
 *
 * ⚠ La tabla `responsables_notificacion` se entrega VACÍA: su contenido es la
 *   matriz de responsables que debe definir Bienestar Universitario
 *   (CLAUDE.md §13.1 punto 3).
 *
 * @return array Correos destinatarios
 */
function destinatarios_de_caso($denuncia_id) {
    $competencia = obtener_competencia($denuncia_id);
    $via = $competencia['via'] ?? null;
    $unidad = $competencia['unidad_competente_id'] ?? null;

    $filas = db_select(
        'SELECT correo FROM responsables_notificacion
          WHERE activo = 1
            AND (unidad_id = ? OR unidad_id IS NULL)
            AND (via = ? OR via IS NULL)
          ORDER BY unidad_id IS NULL, via IS NULL',
        [$unidad, $via]
    ) ?: [];

    if (empty($filas)) {
        log_error('Sin responsable configurado para la denuncia ' . $denuncia_id, 'warning');
        return [];
    }

    return array_unique(array_column($filas, 'correo'));
}

/**
 * Notificar a los responsables que hay un caso nuevo
 *
 * @return int Notificaciones encoladas
 */
function notificar_caso_nuevo($denuncia_id, $radicado, $banda_riesgo = null, $plazo = null) {
    $destinatarios = destinatarios_de_caso($denuncia_id);
    $encoladas = 0;

    foreach ($destinatarios as $correo) {
        $ok = encolar_notificacion('correo', $correo, 'caso_asignado', [
            'radicado'     => $radicado,
            'banda_riesgo' => $banda_riesgo ?? 'por valorar',
            'plazo'        => $plazo ?? '',
            'enlace'       => url('?page=denuncias'),
        ], $denuncia_id);

        if ($ok) {
            $encoladas++;
        }
    }

    return $encoladas;
}

// ============================================================================
// RN-15 · CANAL DE NOTIFICACIÓN (v3.1 · Fase C · Ac. 003 art. 43 · Ley 1581)
// ============================================================================

/**
 * Direcciones de un usuario del sistema: SIEMPRE institucional + personal
 *
 * RN-15: para el equipo, las unidades y las autoridades, toda notificación
 * va a ambos correos. Ambos son obligatorios al crear la cuenta.
 *
 * @return array [['direccion' => string, 'tipo' => 'institucional'|'personal'], …]
 */
function destinatarios_de_usuario($usuario_id) {
    $usuario = db_select_one(
        'SELECT email, correo_personal_cifrado FROM usuarios WHERE id = ? AND activo = 1 LIMIT 1',
        [(int)$usuario_id]
    );

    if (!$usuario) {
        return [];
    }

    $pares = [];

    if (!empty($usuario['email'])) {
        $pares[] = ['direccion' => $usuario['email'], 'tipo' => 'institucional'];
    }

    $personal = descifrar_contacto($usuario['correo_personal_cifrado'] ?? null);

    if ($personal !== null && $personal !== '') {
        $pares[] = ['direccion' => $personal, 'tipo' => 'personal'];
    }

    return $pares;
}

/**
 * Direcciones de una persona del caso según SU elección de canal
 *
 * RN-15: el sistema NO envía al correo institucional si la persona eligió
 * solo el personal, ni al revés. Una cuenta institucional puede estar
 * compartida o supervisada; forzarla puede exponer a la persona.
 * Para la persona denunciada: notificaciones procesales al institucional
 * (art. 43) y, si existe, copia al personal; siempre sin carga (RN-09).
 *
 * @return array [['direccion' => string, 'tipo' => …], …]
 */
function destinatarios_de_persona($denuncia_id, $papel) {
    $persona = db_select_one(
        'SELECT canal_preferido, correo_personal_cifrado, correo_institucional_cifrado,
                telefono_cifrado
           FROM personas_caso
          WHERE denuncia_id = ? AND papel = ? LIMIT 1',
        [(int)$denuncia_id, $papel]
    );

    if (!$persona) {
        return [];
    }

    $personal      = descifrar_contacto($persona['correo_personal_cifrado']);
    $institucional = descifrar_contacto($persona['correo_institucional_cifrado']);
    $telefono      = descifrar_contacto($persona['telefono_cifrado']);

    // Persona denunciada: régimen del art. 43, no de preferencia
    if ($papel === 'denunciada') {
        $pares = [];
        if ($institucional) {
            $pares[] = ['direccion' => $institucional, 'tipo' => 'institucional'];
        }
        if ($personal) {
            $pares[] = ['direccion' => $personal, 'tipo' => 'personal'];
        }
        return $pares;
    }

    switch ($persona['canal_preferido']) {
        case 'ninguno':
            return [];
        case 'sms':
            return $telefono ? [['direccion' => $telefono, 'tipo' => 'sms']] : [];
        case 'institucional':
            return $institucional ? [['direccion' => $institucional, 'tipo' => 'institucional']] : [];
        case 'ambos':
            $pares = [];
            if ($institucional) {
                $pares[] = ['direccion' => $institucional, 'tipo' => 'institucional'];
            }
            if ($personal) {
                $pares[] = ['direccion' => $personal, 'tipo' => 'personal'];
            }
            return $pares;
        case 'personal':
        default:
            return $personal ? [['direccion' => $personal, 'tipo' => 'personal']] : [];
    }
}

/**
 * Encolar una notificación hacia varios pares (direccion, tipo)
 *
 * Crea una fila por dirección, todas con el mismo `grupo_envio` (UUID), para
 * que el Outbox muestre el estado por dirección (§7.3).
 *
 * @param array $pares Salida de destinatarios_de_usuario/…_de_persona
 * @return int Filas encoladas
 */
function encolar_notificacion_grupo(array $pares, $plantilla, array $payload,
                                    $denuncia_id = null, $secreto = null) {
    if (empty($pares)) {
        return 0;
    }

    $grupo = uuid_v4();
    $encoladas = 0;

    foreach ($pares as $par) {
        $tipo_transporte = ($par['tipo'] === 'sms') ? 'sms' : 'correo';

        $ok = encolar_notificacion($tipo_transporte, $par['direccion'], $plantilla,
                                   $payload, $denuncia_id, $secreto, [
            'grupo_envio'       => $grupo,
            'destinatario_tipo' => $par['tipo'],
        ]);

        if ($ok) {
            $encoladas++;
        }
    }

    return $encoladas;
}
