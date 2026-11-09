<?php
/**
 * AUTORIZACIÓN · VISIBILIDAD POR ROL, UNIDAD, ETAPA Y CAMPO
 *
 * Fase 3 · CLAUDE.md §9.2
 * Implementa RN-01, RN-02, RN-07, RN-08 y RN-12.
 *
 * Este archivo sustituye el modelo anterior, en el que cualquier usuario
 * autenticado veía el listado completo y el detalle de todos los casos
 * (hallazgo H-18).
 *
 * REGLA DE ORO: falla cerrada. Ante cualquier duda, error o estado
 * inesperado, se devuelve `false`. Un expediente de violencia de género que
 * se muestra de más no se puede «des-mostrar».
 *
 * Orden de evaluación (§9.2), en este orden y sin atajos:
 *   1. impedimento declarado          → RN-12
 *   2. acuerdo de confidencialidad     → Ac. 002 art. 25 par.
 *   3. certificación vigente           → RN-11
 *   4. asignación al caso
 *   5. alcance de unidad               → RN-08
 *   6. etapa procesal                  → RN-02
 *   7. lista blanca de campos del rol
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// ============================================================================
// GRUPOS DE CAMPOS
// ============================================================================

/** Identificación del caso: no revela hechos ni personas */
const CAMPOS_IDENTIFICACION = [
    'id', 'codigo', 'estado', 'etapa_procesal', 'created_at', 'updated_at',
];

/** Clasificación del caso */
const CAMPOS_CLASIFICACION = [
    'tipo_violencia', 'fecha_hechos',
];

/** El relato. El dato más sensible del sistema (RN-03) */
const CAMPOS_RELATO = [
    'descripcion', 'testigos',
];

/** Identidad de las partes */
const CAMPOS_PARTES = [
    'denunciante_nombre', 'denunciante_contacto',
    'denunciante_correo_cifrado', 'denunciante_telefono_cifrado',
    'denunciante_correo_indice', 'denunciante_telefono_indice',
    'canal_preferido', 'tipo_agresor', 'agresor_nombre', 'agresor_identificacion',
];

/** Ubicación de los hechos */
const CAMPOS_UBICACION = [
    'lugar_hechos', 'facultad', 'sede', 'edificio', 'salon_area', 'unidad_hechos_id',
];

/**
 * Grupos de campos accesibles por rol
 *
 * `administrador` NO figura: administra configuración, usuarios y catálogos,
 * y no accede a expedientes (§9.1). Es deliberado — el rol con más poder
 * técnico es el que menos necesita ver relatos.
 */
const CAMPOS_POR_ROL = [
    'coordinacion_edheg'  => ['identificacion', 'clasificacion', 'relato', 'partes', 'ubicacion'],
    'coordinador'         => ['identificacion', 'clasificacion', 'relato', 'partes', 'ubicacion'],
    'psicologo'           => ['identificacion', 'clasificacion', 'relato', 'partes', 'ubicacion'],
    'trabajador_social'   => ['identificacion', 'clasificacion', 'relato', 'partes', 'ubicacion'],
    'abogado'             => ['identificacion', 'clasificacion', 'relato', 'partes', 'ubicacion'],
    'instructor'          => ['identificacion', 'clasificacion', 'relato', 'partes', 'ubicacion'],
    'decisor'             => ['identificacion', 'clasificacion', 'relato', 'partes', 'ubicacion'],
    // Sin acceso a expedientes:
    'administrador'       => [],
    'admin'               => [],
    'unidad_academica'    => [],
    'talento_humano'      => [],
    'reportes'            => [],
    // Res. 142/2026 art. 13 par.: la Comisión de Seguimiento no accede a
    // información de víctimas, sujetos procesales ni reportes de los hechos.
    // Equivale a `reportes`: solo agregados con RN-10 aplicada.
    'comision_seguimiento' => [],
];

/** Roles que pueden ver todos los expedientes sin asignación individual */
const ROLES_ALCANCE_GLOBAL = ['coordinacion_edheg', 'coordinador'];

/** Etapas en las que la reserva del art. 47 ya no rige */
const ETAPAS_SIN_RESERVA = ['cargos_formulados', 'juzgamiento', 'archivo_definitivo', 'cerrada'];

// ============================================================================
// RN-12 · IMPEDIMENTOS
// ============================================================================

/**
 * ¿El usuario se declaró impedido para este expediente?
 *
 * El bloqueo es irreversible desde la aplicación: no existe función que
 * elimine un impedimento declarado (Ley 1437 art. 11).
 *
 * @return bool
 */
function tiene_impedimento($usuario_id, $denuncia_id) {
    // Se usa COUNT y no un SELECT de la fila porque `db_select_one()` devuelve
    // `false` tanto ante un error como cuando no hay resultados: con un SELECT
    // normal, «no hay impedimento» y «la consulta falló» serían indistinguibles,
    // y fallar cerrada convertiría lo primero en un bloqueo para todo el equipo.
    // Con COUNT siempre hay fila, así que `false` solo puede significar error.
    $fila = db_select_one(
        'SELECT COUNT(*) AS total FROM impedimentos
          WHERE usuario_id = ? AND denuncia_id = ? AND declarado = 1',
        [(int)$usuario_id, (int)$denuncia_id]
    );

    // Falla cerrada: solo ante un error real de consulta
    if ($fila === false) {
        log_error('No se pudo verificar el impedimento; se deniega el acceso', 'error');
        return true;
    }

    return (int)$fila['total'] > 0;
}

/**
 * Declarar impedimento
 *
 * Notifica a coordinación para reasignación y revoca las asignaciones activas
 * de esa persona sobre ese expediente.
 *
 * @return bool
 */
function declarar_impedimento($usuario_id, $denuncia_id, $causal = null) {
    $existente = db_select_one(
        'SELECT id FROM impedimentos WHERE usuario_id = ? AND denuncia_id = ? LIMIT 1',
        [(int)$usuario_id, (int)$denuncia_id]
    );

    if ($existente) {
        return true; // ya declarado; el bloqueo no se deshace
    }

    $id = db_insert_simple('impedimentos', [
        'usuario_id'  => (int)$usuario_id,
        'denuncia_id' => (int)$denuncia_id,
        'declarado'   => 1,
        'causal'      => $causal !== null ? substr($causal, 0, 255) : null,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    if (!$id) {
        return false;
    }

    db_execute(
        'UPDATE asignaciones SET activa = 0, revocada_at = NOW()
          WHERE usuario_id = ? AND denuncia_id = ? AND activa = 1',
        [(int)$usuario_id, (int)$denuncia_id]
    );

    audit_log($usuario_id, 'impedimento_declarado', 'impedimentos', [
        'con_causal' => $causal !== null,
    ], $denuncia_id);

    return true;
}

// ============================================================================
// REQUISITOS DE IDONEIDAD  (advisory hasta que existan los datos)
// ============================================================================

/**
 * ¿El usuario tiene acuerdo de confidencialidad vigente?
 *
 * Ac. 002 art. 25 par. Se comprueba solo si la tabla existe y la exigencia
 * está activada: encender la exigencia sin haber cargado los acuerdos dejaría
 * al equipo completo sin acceso a los casos que está atendiendo.
 *
 * @return bool
 */
function tiene_acuerdo_confidencialidad($usuario_id) {
    if (!defined('EXIGIR_ACUERDO_CONFIDENCIALIDAD') || !EXIGIR_ACUERDO_CONFIDENCIALIDAD) {
        return true;
    }

    $fila = db_select_one(
        'SELECT id FROM acuerdos_confidencialidad WHERE usuario_id = ? ORDER BY id DESC LIMIT 1',
        [(int)$usuario_id]
    );

    return $fila !== false && $fila !== null;
}

/**
 * ¿El usuario tiene certificación vigente? (RN-11, Ac. 002 arts. 20 y 46)
 *
 * @return bool
 */
function tiene_certificacion_vigente($usuario_id) {
    if (!defined('EXIGIR_CERTIFICACION_VIGENTE') || !EXIGIR_CERTIFICACION_VIGENTE) {
        return true;
    }

    $fila = db_select_one(
        'SELECT id FROM certificaciones_equipo
          WHERE usuario_id = ? AND vence_at >= CURDATE() ORDER BY vence_at DESC LIMIT 1',
        [(int)$usuario_id]
    );

    return $fila !== false && $fila !== null;
}

// ============================================================================
// ASIGNACIONES · RN-01
// ============================================================================

/**
 * ¿El usuario está asignado al expediente?
 *
 * @param string|null $funcion Restringe a una función concreta
 * @return bool
 */
function esta_asignado($usuario_id, $denuncia_id, $funcion = null) {
    // COUNT por el mismo motivo que en tiene_impedimento(): con un SELECT
    // normal no se distingue «no está asignado» de «la consulta falló».
    $sql = 'SELECT COUNT(*) AS total FROM asignaciones
             WHERE usuario_id = ? AND denuncia_id = ? AND activa = 1';
    $params = [(int)$usuario_id, (int)$denuncia_id];

    if ($funcion !== null) {
        $sql .= ' AND funcion = ?';
        $params[] = $funcion;
    }

    $fila = db_select_one($sql, $params);

    // Falla cerrada
    if ($fila === false) {
        return false;
    }

    return (int)$fila['total'] > 0;
}

/**
 * Asignar una actuación a un usuario sobre un expediente
 *
 * ⚠ RN-01 — Ac. 003 art. 23 par.: «En ningún caso la misma autoridad podrá
 * instruir y fallar el proceso.» La verificación ocurre AQUÍ, al crear la
 * asignación, y no al guardar el fallo: para entonces el daño procesal ya
 * está hecho y la actuación es anulable.
 *
 * @return array ['exito' => bool, 'motivo' => string]
 */
function asignar_actuacion($denuncia_id, $usuario_id, $funcion, $asignada_por = null) {
    $funciones_validas = ['instruccion', 'juzgamiento', 'coordinacion',
                          'psicologia', 'trabajo_social', 'juridica'];

    if (!in_array($funcion, $funciones_validas, true)) {
        return ['exito' => false, 'motivo' => 'Función no válida.'];
    }

    // RN-12: no se asigna a quien se declaró impedido
    if (tiene_impedimento($usuario_id, $denuncia_id)) {
        return ['exito' => false, 'motivo' => 'La persona se declaró impedida para este expediente.'];
    }

    // RN-01: incompatibilidad entre instrucción y juzgamiento
    $incompatible = ['instruccion' => 'juzgamiento', 'juzgamiento' => 'instruccion'];

    if (isset($incompatible[$funcion])) {
        $conflicto = db_select_one(
            'SELECT id FROM asignaciones
              WHERE denuncia_id = ? AND usuario_id = ? AND funcion = ? AND activa = 1 LIMIT 1',
            [(int)$denuncia_id, (int)$usuario_id, $incompatible[$funcion]]
        );

        if ($conflicto) {
            audit_log($asignada_por, 'asignacion_rechazada_rn01', 'asignaciones', [
                'usuario_afectado' => (int)$usuario_id,
                'funcion_solicitada' => $funcion,
                'funcion_existente' => $incompatible[$funcion],
            ], $denuncia_id);

            return [
                'exito'  => false,
                'motivo' => 'RN-01: la misma persona no puede instruir y fallar el mismo expediente '
                          . '(Acuerdo 003 de 2026, art. 23 parágrafo).',
            ];
        }
    }

    // RN-11: idoneidad
    if (!tiene_certificacion_vigente($usuario_id)) {
        return ['exito' => false, 'motivo' => 'RN-11: la persona no tiene certificación vigente.'];
    }

    $id = db_insert_simple('asignaciones', [
        'denuncia_id'  => (int)$denuncia_id,
        'usuario_id'   => (int)$usuario_id,
        'funcion'      => $funcion,
        'activa'       => 1,
        'asignada_por' => $asignada_por !== null ? (int)$asignada_por : null,
        'created_at'   => date('Y-m-d H:i:s'),
    ]);

    if (!$id) {
        return ['exito' => false, 'motivo' => 'No se pudo registrar la asignación.'];
    }

    audit_log($asignada_por, 'asignacion_creada', 'asignaciones', [
        'usuario_afectado' => (int)$usuario_id,
        'funcion' => $funcion,
    ], $denuncia_id);

    return ['exito' => true, 'motivo' => ''];
}

// ============================================================================
// RN-02 · RESERVA POR ETAPA PROCESAL
// ============================================================================

/**
 * ¿El expediente está bajo reserva?
 *
 * Ac. 003 art. 47: la actuación es reservada hasta la formulación de cargos
 * o el archivo definitivo.
 *
 * @return bool
 */
function expediente_bajo_reserva($etapa_procesal) {
    return !in_array($etapa_procesal, ETAPAS_SIN_RESERVA, true);
}

// ============================================================================
// FUNCIÓN CENTRAL DE VISIBILIDAD
// ============================================================================

/**
 * ¿Puede este usuario acceder a este expediente?
 *
 * @param int $usuario_id
 * @param string $rol
 * @param int $expediente_id
 * @return bool
 */
function puede_ver_expediente($usuario_id, $rol, $expediente_id) {
    $usuario_id    = (int)$usuario_id;
    $expediente_id = (int)$expediente_id;

    if ($usuario_id <= 0 || $expediente_id <= 0 || !is_string($rol) || $rol === '') {
        return false;
    }

    // 7. El rol debe tener al menos un grupo de campos permitido.
    //    RN-08: `unidad_academica` no accede a expedientes, nunca.
    if (empty(CAMPOS_POR_ROL[$rol] ?? [])) {
        return false;
    }

    // 1. RN-12 · impedimento
    if (tiene_impedimento($usuario_id, $expediente_id)) {
        return false;
    }

    // 2 y 3. Idoneidad
    if (!tiene_acuerdo_confidencialidad($usuario_id)) {
        return false;
    }
    if (!tiene_certificacion_vigente($usuario_id)) {
        return false;
    }

    $denuncia = db_select_one(
        'SELECT etapa_procesal FROM denuncias WHERE id = ? LIMIT 1',
        [$expediente_id]
    );

    // Falla cerrada
    if ($denuncia === false || $denuncia === null) {
        return false;
    }

    // 6. RN-02 · reserva por etapa
    $bajo_reserva = expediente_bajo_reserva($denuncia['etapa_procesal']);

    // El rol `decisor` no accede antes de la formulación de cargos (§9.1)
    if ($rol === 'decisor' && $bajo_reserva) {
        return false;
    }

    // 4. Asignación · la coordinación tiene alcance global
    if (in_array($rol, ROLES_ALCANCE_GLOBAL, true)) {
        return true;
    }

    return esta_asignado($usuario_id, $expediente_id);
}

/**
 * ¿Puede este usuario ver este campo concreto del expediente?
 *
 * Es la función que CLAUDE.md §9.2 exige. Se apoya en puede_ver_expediente()
 * y añade la lista blanca de campos del rol.
 *
 * @return bool
 */
function puede_ver_campo($usuario_id, $rol, $expediente_id, $campo) {
    if (!puede_ver_expediente($usuario_id, $rol, $expediente_id)) {
        return false;
    }

    $grupos = CAMPOS_POR_ROL[$rol] ?? [];

    $mapa = [
        'identificacion' => CAMPOS_IDENTIFICACION,
        'clasificacion'  => CAMPOS_CLASIFICACION,
        'relato'         => CAMPOS_RELATO,
        'partes'         => CAMPOS_PARTES,
        'ubicacion'      => CAMPOS_UBICACION,
    ];

    foreach ($grupos as $grupo) {
        if (in_array($campo, $mapa[$grupo] ?? [], true)) {
            return true;
        }
    }

    // Campo desconocido: falla cerrada
    return false;
}

/**
 * Filtrar una fila de expediente dejando solo los campos visibles
 *
 * Se usa antes de pasar datos a una vista: así la plantilla no puede imprimir
 * por descuido un campo que el rol no debería ver.
 *
 * @return array
 */
function filtrar_expediente(array $fila, $usuario_id, $rol, $expediente_id) {
    $visible = [];

    foreach ($fila as $campo => $valor) {
        if (puede_ver_campo($usuario_id, $rol, $expediente_id, $campo)) {
            $visible[$campo] = $valor;
        }
    }

    return $visible;
}

/**
 * Condición SQL que restringe un listado a los expedientes visibles
 *
 * Aplicar la visibilidad fila por fila después de consultar es correcto pero
 * lento y frágil: basta olvidar un filtro para que un conteo revele cuántos
 * casos existen. Esta función devuelve la restricción para incrustarla en la
 * consulta, con sus parámetros.
 *
 * @return array ['sql' => string, 'params' => array]
 */
function restriccion_listado_expedientes($usuario_id, $rol) {
    $usuario_id = (int)$usuario_id;

    // Sin acceso a expedientes: condición imposible
    if (empty(CAMPOS_POR_ROL[$rol] ?? [])) {
        return ['sql' => '1 = 0', 'params' => []];
    }

    // Nunca se listan expedientes con impedimento declarado
    $sin_impedimento = 'd.id NOT IN (SELECT denuncia_id FROM impedimentos
                                      WHERE usuario_id = ? AND declarado = 1)';

    if (in_array($rol, ROLES_ALCANCE_GLOBAL, true)) {
        return ['sql' => $sin_impedimento, 'params' => [$usuario_id]];
    }

    $asignados = 'd.id IN (SELECT denuncia_id FROM asignaciones
                            WHERE usuario_id = ? AND activa = 1)';

    if ($rol === 'decisor') {
        // RN-02: el decisor no ve expedientes bajo reserva
        $etapas = "'" . implode("','", ETAPAS_SIN_RESERVA) . "'";
        return [
            'sql'    => "$asignados AND d.etapa_procesal IN ($etapas) AND $sin_impedimento",
            'params' => [$usuario_id, $usuario_id],
        ];
    }

    return [
        'sql'    => "$asignados AND $sin_impedimento",
        'params' => [$usuario_id, $usuario_id],
    ];
}

// ============================================================================
// RN-08 · AISLAMIENTO DE LA UNIDAD ACADÉMICA
// ============================================================================

/**
 * Órdenes de medida visibles para un rol de unidad académica
 *
 * ADR-05: la orden de medida es una proyección del expediente, no el
 * expediente. El módulo `ordenes_medida` y la tabla `medidas` pertenecen a la
 * Fase 5; hasta entonces esta función devuelve una lista vacía.
 *
 * Lo que esta función NO devolverá nunca, ni cuando la Fase 5 la complete:
 * relato, tipo de violencia, identidad de la persona denunciada, evidencia ni
 * valoración de riesgo (RN-08).
 *
 * @return array
 */
function ordenes_medida_para_unidad($usuario_id, $unidad_id) {
    $unidad_id = (int)$unidad_id;

    if ($unidad_id <= 0) {
        return [];
    }

    // Proyección ESTRICTA (ADR-05 · v3.1 §7.7): radicado opaco, medida,
    // plazo y estado del compromiso. Ni relato, ni tipo de violencia, ni
    // identidad de la persona denunciada, ni evidencia, ni riesgo.
    $filas = db_select(
        "SELECT c.id AS compromiso_id, c.descripcion, c.fecha_limite, c.estado,
                c.constancia_texto, c.respuesta_texto, c.origen, c.respondido_at,
                d.codigo AS radicado
           FROM compromisos c
           JOIN denuncias d ON d.id = c.denuncia_id
          WHERE c.responsable_unidad_id = ?
          ORDER BY c.estado IN ('pendiente','en_curso','prorrogado','incumplido') DESC,
                   c.fecha_limite",
        [$unidad_id]
    ) ?: [];

    audit_log($usuario_id, 'ordenes_medida_consultadas', 'compromisos', [
        'motivo' => 'unidad_' . $unidad_id,
    ]);

    return $filas;
}

/**
 * Alcance de la consulta pública según el papel de quien consulta
 *
 * RN-07 · Ac. 003 art. 44: quien presenta la queja sin ser la persona
 * afectada solo conoce las decisiones generales del proceso.
 *
 * @param int $denuncia_id
 * @return string 'afectada' | 'quejoso'
 */
function alcance_consulta_publica($denuncia_id) {
    $persona = db_select_one(
        "SELECT es_mismo_denunciante FROM personas_caso
          WHERE denuncia_id = ? AND papel = 'denunciante' LIMIT 1",
        [(int)$denuncia_id]
    );

    // Falla cerrada: sin información, se aplica el alcance más restringido
    if ($persona === false || $persona === null) {
        return 'quejoso';
    }

    return (int)$persona['es_mismo_denunciante'] === 1 ? 'afectada' : 'quejoso';
}
