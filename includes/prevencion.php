<?php
/**
 * PREVENCIÓN · REPORTES ANÓNIMOS, ESCRACHE Y PATRONES
 *
 * Fase 7 · Ac. 002 art. 33 · Ac. 003 art. 5 · ADR-09
 *
 * Dos canales, no uno:
 *
 * |                          | Denuncia formal | Reporte anónimo / escrache |
 * |--------------------------|-----------------|----------------------------|
 * | Contacto                 | Obligatorio     | No se solicita             |
 * | Radicado consultable     | Sí              | No                         |
 * | Abre trámite disciplinario| Sí             | **No**                     |
 * | Qué activa               | Ruta completa   | Medidas preventivas y      |
 * |                          | de atención     | análisis de patrones       |
 *
 * El valor del canal preventivo no es individual sino colectivo: tres reportes
 * anónimos sobre el mismo espacio son una señal que hoy se pierde por completo.
 * El art. 33.4 pide explícitamente «identificar patrones y posibles
 * situaciones de riesgo colectivo», y eso es imposible si el sistema descarta
 * toda información no atribuible.
 *
 * ⚠ PRESUNCIÓN DE INOCENCIA (Ac. 003 art. 7)
 *   Un patrón señala ESPACIOS y CONTEXTOS, nunca personas. No existe función
 *   en este archivo que agrupe señales alrededor de un individuo: eso sería
 *   una lista negra, no prevención.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/** Ocurrencias sobre un mismo espacio que disparan la señal de patrón */
const UMBRAL_PATRON = 3;

/** Ventana de observación, en días */
const VENTANA_PATRON_DIAS = 180;

/**
 * ¿Está habilitado el canal anónimo?
 *
 * Se entrega desactivado. Su apertura es decisión del Comité para la Equidad
 * de Géneros y Diversidades Sexuales (§13.1 punto 4), no del equipo técnico.
 *
 * @return bool
 */
function canal_anonimo_activo() {
    if (function_exists('config_valor')) {
        return (bool)config_valor('CANAL_ANONIMO_ACTIVO', false);
    }
    return defined('CANAL_ANONIMO_ACTIVO') && CANAL_ANONIMO_ACTIVO;
}

/**
 * Registrar un reporte anónimo o un escrache
 *
 * NO genera radicado: entregarlo daría a entender que hay un trámite en curso
 * y que se puede consultar, y ninguna de las dos cosas es cierta. Prometer un
 * seguimiento que no existe es peor que no ofrecerlo.
 *
 * @param string $descripcion
 * @param array $datos tipo, unidad_id, espacio_senalado, fuente
 * @return array ['exito' => bool, 'motivo' => string]
 */
function registrar_reporte_anonimo($descripcion, array $datos = []) {
    if (!canal_anonimo_activo()) {
        return [
            'exito'  => false,
            'motivo' => 'El canal de reporte preventivo aún no está habilitado.',
        ];
    }

    $descripcion = trim((string)$descripcion);

    if (mb_strlen($descripcion) < 20) {
        return ['exito' => false, 'motivo' => 'Cuéntanos un poco más: al menos 20 caracteres.'];
    }

    // Límite por IP: el canal no pide identificación, así que es el único
    // freno posible frente a un uso masivo automatizado
    if (!rate_limit_check('reporte_anonimo:' . get_user_ip(), 5, 60)) {
        return ['exito' => false, 'motivo' => 'Demasiados envíos desde esta conexión. Intenta más tarde.'];
    }

    $tipo = $datos['tipo'] ?? 'anonimo';

    if (!in_array($tipo, ['anonimo', 'escrache', 'denuncia_publica'], true)) {
        $tipo = 'anonimo';
    }

    $id = db_insert_simple('reportes_anonimos', [
        'tipo'             => $tipo,
        'descripcion'      => $descripcion,
        'unidad_id'        => !empty($datos['unidad_id']) ? (int)$datos['unidad_id'] : null,
        'espacio_senalado' => !empty($datos['espacio_senalado'])
            ? substr($datos['espacio_senalado'], 0, 255) : null,
        'fuente'           => !empty($datos['fuente']) ? substr($datos['fuente'], 0, 255) : null,
        'tiene_pruebas'    => !empty($datos['tiene_pruebas']) ? 1 : 0, // RN-21
        'estado'           => 'recibido',
        'created_at'       => date('Y-m-d H:i:s'),
    ]);

    if (!$id) {
        return ['exito' => false, 'motivo' => 'No se pudo registrar el reporte. Intenta nuevamente.'];
    }

    // La auditoría no lleva la descripción ni la IP: el canal es anónimo, y
    // registrar la IP junto al contenido lo dejaría de ser en la práctica
    audit_log(null, 'reporte_anonimo_recibido', 'reportes_anonimos', [
        'motivo' => $tipo,
    ], (int)$id);

    // Sistematización inmediata (art. 33.3 y 33.4)
    analizar_patrones();

    return ['exito' => true, 'motivo' => ''];
}

/**
 * Analizar patrones de riesgo colectivo (art. 33.4)
 *
 * Agrupa reportes anónimos y denuncias formales por ESPACIO y por UNIDAD
 * dentro de una ventana móvil. Cuando un mismo espacio acumula el umbral de
 * señales, se registra un patrón para revisión humana.
 *
 * El patrón no acusa a nadie: dice «este espacio concentra señales» y activa
 * las medidas preventivas del art. 33.1 —veeduría, punto seguro, comunicación
 * institucional—, que se aplican al espacio, no a una persona.
 *
 * @return int Patrones nuevos detectados
 */
function analizar_patrones() {
    $desde = date('Y-m-d', strtotime('-' . VENTANA_PATRON_DIAS . ' days'));
    $hasta = date('Y-m-d');
    $nuevos = 0;

    // ── Por espacio señalado ─────────────────────────────────────────────
    $filas = db_select(
        "SELECT espacio_senalado AS clave, COUNT(*) AS total
           FROM reportes_anonimos
          WHERE espacio_senalado IS NOT NULL AND espacio_senalado <> ''
            AND DATE(created_at) >= ?
          GROUP BY espacio_senalado
         HAVING total >= ?",
        [$desde, UMBRAL_PATRON]
    ) ?: [];

    foreach ($filas as $fila) {
        if (registrar_patron('espacio_recurrente', $fila['clave'], null,
                             (int)$fila['total'], $desde, $hasta)) {
            $nuevos++;
        }
    }

    // ── Por unidad ───────────────────────────────────────────────────────
    $filas = db_select(
        "SELECT u.id, u.nombre, COUNT(*) AS total
           FROM reportes_anonimos r
           JOIN unidades u ON u.id = r.unidad_id
          WHERE DATE(r.created_at) >= ?
          GROUP BY u.id, u.nombre
         HAVING total >= ?",
        [$desde, UMBRAL_PATRON]
    ) ?: [];

    foreach ($filas as $fila) {
        if (registrar_patron('unidad_recurrente', $fila['nombre'], (int)$fila['id'],
                             (int)$fila['total'], $desde, $hasta)) {
            $nuevos++;
        }
    }

    return $nuevos;
}

/**
 * Registrar un patrón, sin duplicar los que ya están en revisión
 *
 * @return bool true si es nuevo
 */
function registrar_patron($tipo, $clave, $unidad_id, $ocurrencias, $desde, $hasta) {
    $existente = db_select_one(
        "SELECT id, ocurrencias FROM patrones_detectados
          WHERE tipo_patron = ? AND clave = ? AND estado IN ('detectado','en_revision')
          ORDER BY id DESC LIMIT 1",
        [$tipo, $clave]
    );

    if ($existente) {
        // Se actualiza el conteo pero no se crea otro: un patrón abierto no
        // debe multiplicarse en la bandeja de quien lo está revisando
        db_update_simple('patrones_detectados', [
            'ocurrencias'   => (int)$ocurrencias,
            'ventana_hasta' => $hasta,
        ], ['id' => $existente['id']]);

        return false;
    }

    $id = db_insert_simple('patrones_detectados', [
        'tipo_patron'   => $tipo,
        'clave'         => substr((string)$clave, 0, 255),
        'unidad_id'     => $unidad_id,
        'ocurrencias'   => (int)$ocurrencias,
        'ventana_desde' => $desde,
        'ventana_hasta' => $hasta,
        'estado'        => 'detectado',
        'created_at'    => date('Y-m-d H:i:s'),
    ]);

    if (!$id) {
        return false;
    }

    audit_log(null, 'patron_detectado', 'patrones_detectados', [
        'motivo' => $tipo,
    ], (int)$id);

    return true;
}

/**
 * Patrones pendientes de revisión
 *
 * @return array
 */
function patrones_pendientes() {
    return db_select(
        "SELECT * FROM patrones_detectados
          WHERE estado IN ('detectado','en_revision')
          ORDER BY ocurrencias DESC, created_at DESC"
    ) ?: [];
}

// ============================================================================
// GUARDADO PARCIAL DEL RELATO  ·  CLAUDE.md §10.1
// ============================================================================

/**
 * Guardar un borrador del formulario
 *
 * «un relato abandonado a la mitad es una denuncia que no existió»
 *
 * El contenido se cifra en reposo y el token solo lo tiene quien escribe. Del
 * borrador se excluyen los datos de contacto: si alguien encuentra el enlace
 * en un dispositivo compartido, no debe encontrar además a quién pertenece.
 *
 * @param array $campos
 * @return string|false Token de retorno
 */
function guardar_borrador(array $campos) {
    unset($campos['csrf_token'], $campos['denunciante_nombre'],
          $campos['denunciante_email'], $campos['denunciante_telefono']);

    $contenido = json_encode($campos, JSON_UNESCAPED_UNICODE);

    if ($contenido === false) {
        return false;
    }

    $cifrado = cifrar_contacto($contenido);

    if ($cifrado === null) {
        return false;
    }

    $token = bin2hex(random_bytes(16));

    $id = db_insert_simple('borradores_denuncia', [
        'token_hash'        => hash('sha256', $token),
        'contenido_cifrado' => $cifrado,
        'expira_at'         => date('Y-m-d H:i:s', time() + 86400),
        'created_at'        => date('Y-m-d H:i:s'),
    ]);

    return $id ? $token : false;
}

/**
 * Recuperar un borrador
 *
 * @param string $token
 * @return array|null
 */
function recuperar_borrador($token) {
    $fila = db_select_one(
        'SELECT contenido_cifrado FROM borradores_denuncia
          WHERE token_hash = ? AND expira_at > NOW() LIMIT 1',
        [hash('sha256', (string)$token)]
    );

    if (!$fila) {
        return null;
    }

    $plano = descifrar_contacto($fila['contenido_cifrado']);

    if ($plano === null) {
        return null;
    }

    $datos = json_decode($plano, true);

    return is_array($datos) ? $datos : null;
}

/**
 * Eliminar borradores vencidos
 *
 * @return int
 */
function limpiar_borradores_vencidos() {
    return (int)db_execute('DELETE FROM borradores_denuncia WHERE expira_at <= NOW()');
}

// ============================================================================
// DIRECTORIO DE INSTITUCIONES DE APOYO  ·  Ac. 002 art. 24.2
// ============================================================================

/**
 * Instituciones de apoyo activas
 *
 * La tabla existía con cinco registros desde el inicio y ninguna pantalla la
 * mostraba (hallazgo H-20).
 *
 * @param string|null $tipo
 * @return array
 */
function instituciones_apoyo($tipo = null) {
    $sql = 'SELECT * FROM instituciones WHERE activo = 1';
    $params = [];

    if ($tipo !== null) {
        $sql .= ' AND tipo = ?';
        $params[] = $tipo;
    }

    return db_select($sql . ' ORDER BY tipo, nombre', $params) ?: [];
}

/**
 * Recursos publicados del portal
 *
 * @param string $seccion
 * @return array
 */
function recursos_portal($seccion) {
    return db_select(
        'SELECT * FROM recursos_portal WHERE seccion = ? AND publicado = 1 ORDER BY orden, id',
        [$seccion]
    ) ?: [];
}

// ============================================================================
// RN-21 · QUEJA ANÓNIMA CON MÉRITO ABRE ACTUACIÓN DE OFICIO (v3.1 §16.5)
// ============================================================================

/**
 * Decidir sobre un reporte anónimo (RN-21 · indicador 3.2)
 *
 * Cuando un reporte trae pruebas o hechos concretos con autor identificable,
 * coordinación registra una decisión motivada:
 *   · convertir_en_expediente_oficio — crea la denuncia de oficio, sin
 *     persona_denunciante, ligada al reporte, con la reserva RN-02 activa
 *     desde el primer momento.
 *   · archivar_sin_merito — con motivación.
 *
 * @return array ['exito' => bool, 'denuncia_id' => int|null, 'error' => string|null]
 */
function decidir_reporte_anonimo($reporte_id, $decision, $motivacion, $usuario_id, $rol) {
    if (!in_array($rol, ['coordinacion_edheg', 'coordinador'], true)) {
        return ['exito' => false, 'denuncia_id' => null,
                'error' => 'Solo coordinación decide sobre los reportes anónimos.'];
    }

    if (!in_array($decision, ['convertir_en_expediente_oficio', 'archivar_sin_merito'], true)) {
        return ['exito' => false, 'denuncia_id' => null, 'error' => 'Decisión no válida.'];
    }

    $motivacion = trim((string)$motivacion);

    if ($motivacion === '') {
        return ['exito' => false, 'denuncia_id' => null,
                'error' => 'RN-21: la decisión exige motivación en ambos sentidos.'];
    }

    $reporte = db_select_one('SELECT * FROM reportes_anonimos WHERE id = ? LIMIT 1', [(int)$reporte_id]);

    if (!$reporte) {
        return ['exito' => false, 'denuncia_id' => null, 'error' => 'Reporte no encontrado.'];
    }

    if ($reporte['decision'] !== null) {
        return ['exito' => false, 'denuncia_id' => null, 'error' => 'El reporte ya tiene decisión registrada.'];
    }

    $denuncia_id = null;

    try {
        db_begin_transaction();

        if ($decision === 'convertir_en_expediente_oficio') {
            $denuncia_id = db_insert_simple('denuncias', [
                'codigo'         => generate_codigo_denuncia(),
                'tipo_violencia' => 'psicologica', // clasificación provisional; la ajusta EDHEG al valorar
                'descripcion'    => '[ACTUACIÓN DE OFICIO — RN-21] Expediente abierto por decisión '
                                  . 'motivada de coordinación a partir de un reporte anónimo con mérito. '
                                  . "\n\n" . $reporte['descripcion'],
                'fecha_hechos'   => date('Y-m-d', strtotime($reporte['created_at'])),
                'lugar_hechos'   => $reporte['espacio_senalado'] ?: 'Por establecer',
                'estado'         => 'nueva',
                'etapa_procesal' => 'recepcion',
                'canal_origen'   => 'anonimo',
                'es_anonima'     => 1,
                'unidad_hechos_id' => $reporte['unidad_id'] ?: null,
                'canal_preferido' => 'ninguno', // no hay persona de contacto (sin denunciante)
                'created_at'     => date('Y-m-d H:i:s'),
            ]);

            if (!$denuncia_id) {
                throw new RuntimeException('No se pudo crear el expediente de oficio');
            }
        }

        $ok = db_update_simple('reportes_anonimos', [
            'decision'            => $decision,
            'decision_motivacion' => $motivacion,
            'decidida_por'        => (int)$usuario_id,
            'decidida_at'         => date('Y-m-d H:i:s'),
            'denuncia_id'         => $denuncia_id,
            'estado'              => $decision === 'archivar_sin_merito' ? 'archivado' : 'en_analisis',
        ], ['id' => (int)$reporte_id]);

        if ($ok === false) {
            throw new RuntimeException('No se pudo registrar la decisión');
        }

        db_commit();
    } catch (Exception $e) {
        db_rollback();
        log_error('Error en decisión RN-21: ' . $e->getMessage(), 'error');
        return ['exito' => false, 'denuncia_id' => null, 'error' => 'No se pudo registrar la decisión.'];
    }

    audit_log($usuario_id, 'reporte_anonimo_decidido', 'reportes_anonimos', [
        'motivo' => $decision,
    ], (int)$reporte_id);

    return ['exito' => true, 'denuncia_id' => $denuncia_id ? (int)$denuncia_id : null, 'error' => null];
}

/**
 * Reportes anónimos con mérito y sin decisión (RN-21)
 *
 * El cron crea un compromiso a coordinación cuando superan el plazo de
 * DIAS_DECISION_REPORTE_ANONIMO días hábiles.
 *
 * @return array
 */
function reportes_anonimos_sin_decision() {
    return db_select(
        "SELECT * FROM reportes_anonimos
          WHERE decision IS NULL AND estado = 'recibido'
          ORDER BY created_at",
        []
    ) ?: [];
}
