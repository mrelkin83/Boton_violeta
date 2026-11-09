<?php
/**
 * DERIVACIÓN DE COMPETENCIA
 *
 * Fase 3 · ADR-04 · CLAUDE.md §4.2
 *
 * La competencia disciplinaria **sigue a la persona denunciada**: a su tipo de
 * vinculación y, cuando es estudiante, a su proyecto curricular. NO sigue al
 * lugar de los hechos ni a la facultad de la persona afectada.
 *
 * Antes, `facultad` y `sede` eran texto libre que describía *dónde ocurrió*.
 * El modelo confundía dos cosas distintas: dónde pasó y quién investiga.
 *
 * ⚠ Esta tabla NO se inventa. Cada fila cita el artículo que la sustenta
 * (CLAUDE.md §1: «Nunca inventes normativa»). Si aparece un supuesto que no
 * está aquí, la vía es `sin_competencia` y el caso se escala a coordinación
 * para decisión humana motivada — nunca se adivina.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Matriz de competencia por tipo de vinculación de la persona denunciada
 *
 * Fuente: Acuerdos CSU 002 y 003 de 2026, Estatuto Estudiantil (Ac. 027/1993),
 * régimen disciplinario docente (Ac. 011/2002) y Ley 1952 de 2019.
 */
const MATRIZ_COMPETENCIA = [
    'estudiante_pregrado' => [
        'via'        => 'disciplinaria_estudiantil',
        'instruye'   => 'Docente investigador designado por el Consejo de Carrera del proyecto curricular de la persona denunciada',
        'falla'      => 'Consejo de Carrera (amonestación) / Consejo de Facultad (matrícula condicional o suspensión)',
        'segunda'    => 'Consejo de Facultad / Consejo Académico / Consejo Superior Universitario',
        'fundamento' => 'Ac. 027/1993 art. 86 · Ac. 003/2026 Tít. II',
        'sigue_unidad' => true,
    ],
    'estudiante_posgrado' => [
        'via'        => 'disciplinaria_estudiantil',
        'instruye'   => 'Docente investigador designado por el Consejo de Carrera del proyecto curricular de la persona denunciada',
        'falla'      => 'Consejo de Carrera / Consejo de Facultad',
        'segunda'    => 'Consejo de Facultad / Consejo Académico / Consejo Superior Universitario',
        'fundamento' => 'Ac. 027/1993 art. 86 · Ac. 003/2026 Tít. II',
        'sigue_unidad' => true,
    ],
    'docente_carrera' => [
        'via'        => 'disciplinaria_docente',
        // La OCID es la antigua OAAD: renombrada por el manual de funciones,
        // posterior al Protocolo (precisión de Elkin, 2/09/2026)
        'instruye'   => 'Oficina de Control Interno Disciplinario (OCID)',
        'falla'      => 'Autoridad nominadora',
        'segunda'    => 'Rectoría',
        'fundamento' => 'Ac. 011/2002 · Ac. 003/2026 Tít. III',
        'sigue_unidad' => false,
    ],
    'docente_vinculacion_especial' => [
        'via'        => 'administrativa_especial',
        'instruye'   => 'Procedimiento administrativo de la dependencia contratante',
        'falla'      => 'Decanatura / Vicerrectoría',
        'segunda'    => 'Rectoría',
        'fundamento' => 'Ac. 003/2026',
        'sigue_unidad' => false,
    ],
    'administrativo' => [
        'via'        => 'disciplinaria_administrativa',
        'instruye'   => 'Oficina de Control Interno Disciplinario (OCID)',
        'falla'      => 'Autoridad disciplinaria competente',
        'segunda'    => 'Rectoría',
        'fundamento' => 'Ley 1952/2019 · Ac. 003/2026',
        'sigue_unidad' => false,
    ],
    'trabajador_oficial' => [
        'via'        => 'disciplinaria_administrativa',
        'instruye'   => 'Oficina de Control Interno Disciplinario (OCID)',
        'falla'      => 'Autoridad disciplinaria competente',
        'segunda'    => 'Rectoría',
        'fundamento' => 'Ley 1952/2019 · Ac. 003/2026',
        'sigue_unidad' => false,
    ],
    'contratista' => [
        'via'        => 'contractual',
        'instruye'   => 'Supervisión del contrato en coordinación con la entidad contratista',
        'falla'      => null,
        'segunda'    => null,
        'fundamento' => 'Ac. 003/2026 art. 10',
        'sigue_unidad' => false,
    ],
    'tercerizado' => [
        'via'        => 'contractual',
        'instruye'   => 'Traslado a la entidad contratista empleadora',
        'falla'      => null,
        'segunda'    => null,
        'fundamento' => 'Ac. 003/2026 art. 10',
        'sigue_unidad' => false,
    ],
    'externo' => [
        'via'        => 'traslado_externo',
        'instruye'   => 'Sin potestad disciplinaria universitaria: traslado a autoridad competente',
        'falla'      => null,
        'segunda'    => null,
        'fundamento' => 'Ac. 003/2026 arts. 20-22',
        'sigue_unidad' => false,
    ],
    'desconocido' => [
        'via'        => 'sin_competencia',
        'instruye'   => 'Por determinar: requiere identificación de la persona denunciada',
        'falla'      => null,
        'segunda'    => null,
        'fundamento' => 'Ac. 002/2026 art. 33 (vía preventiva)',
        'sigue_unidad' => false,
    ],
];

/**
 * Derivar y registrar la competencia de un expediente
 *
 * Se ejecuta al radicar. La coordinación puede corregirla después con
 * motivación obligatoria, y esa corrección queda auditada.
 *
 * @param int $denuncia_id
 * @param string|null $tipo_vinculacion Vinculación de la persona denunciada
 * @param int|null $unidad_denunciada_id Proyecto curricular o dependencia
 * @return array|false Competencia registrada, o false si falla
 */
function derivar_competencia($denuncia_id, $tipo_vinculacion, $unidad_denunciada_id = null) {
    $clave = $tipo_vinculacion;

    if (!isset(MATRIZ_COMPETENCIA[$clave])) {
        // Ante un supuesto no previsto NO se adivina: se marca sin competencia
        // y se deja constancia para que una persona lo resuelva.
        $clave = 'desconocido';
        log_error('Vinculación sin competencia prevista: ' . var_export($tipo_vinculacion, true), 'warning');
    }

    $regla = MATRIZ_COMPETENCIA[$clave];

    // La competencia estudiantil sigue al proyecto curricular de la persona
    // denunciada. En los demás supuestos la unidad no determina la autoridad.
    $unidad_competente = $regla['sigue_unidad'] ? $unidad_denunciada_id : null;

    $datos = [
        'denuncia_id'          => (int)$denuncia_id,
        'unidad_competente_id' => $unidad_competente !== null ? (int)$unidad_competente : null,
        'autoridad_instruye'   => $regla['instruye'],
        'autoridad_falla'      => $regla['falla'],
        'autoridad_segunda'    => $regla['segunda'],
        'via'                  => $regla['via'],
        'fundamento'           => $regla['fundamento'],
        'derivada_automatica'  => 1,
        'created_at'           => date('Y-m-d H:i:s'),
    ];

    $existente = db_select_one('SELECT id FROM competencias WHERE denuncia_id = ? LIMIT 1', [(int)$denuncia_id]);

    if ($existente) {
        db_update_simple('competencias', $datos, ['denuncia_id' => (int)$denuncia_id]);
    } elseif (!db_insert_simple('competencias', $datos)) {
        log_error('No se pudo registrar la competencia de la denuncia ' . $denuncia_id, 'error');
        return false;
    }

    audit_log(null, 'competencia_derivada', 'competencias', [
        'via' => $regla['via'],
        'fundamento' => $regla['fundamento'],
    ], (int)$denuncia_id);

    return $datos;
}

/**
 * Ajustar manualmente la competencia derivada
 *
 * La derivación automática es un punto de partida, no una sentencia. Ac. 003
 * art. 23 exige que la competencia sea correcta; cuando la máquina se
 * equivoca, una persona la corrige y explica por qué.
 *
 * @return bool
 */
function ajustar_competencia($denuncia_id, $datos_nuevos, $usuario_id, $motivacion) {
    if (trim((string)$motivacion) === '') {
        return false; // la motivación no es opcional
    }

    $datos_nuevos['derivada_automatica'] = 0;
    $datos_nuevos['ajustada_por']        = (int)$usuario_id;
    $datos_nuevos['motivacion_ajuste']   = $motivacion;

    $resultado = db_update_simple('competencias', $datos_nuevos, ['denuncia_id' => (int)$denuncia_id]);

    if ($resultado === false) {
        return false;
    }

    audit_log($usuario_id, 'competencia_ajustada', 'competencias', [
        'via' => $datos_nuevos['via'] ?? null,
    ], (int)$denuncia_id);

    return true;
}

/**
 * Obtener la competencia de un expediente
 *
 * @return array|null
 */
function obtener_competencia($denuncia_id) {
    $fila = db_select_one('SELECT * FROM competencias WHERE denuncia_id = ? LIMIT 1', [(int)$denuncia_id]);
    return $fila ?: null;
}

/**
 * Etiquetas legibles de los tipos de vinculación
 *
 * @return array
 */
function tipos_vinculacion() {
    return [
        'estudiante_pregrado'          => 'Estudiante de pregrado',
        'estudiante_posgrado'          => 'Estudiante de posgrado',
        'docente_carrera'              => 'Docente de carrera',
        'docente_vinculacion_especial' => 'Docente de vinculación especial',
        'administrativo'               => 'Personal administrativo',
        'trabajador_oficial'           => 'Trabajador oficial',
        'contratista'                  => 'Contratista',
        'tercerizado'                  => 'Personal tercerizado',
        'externo'                      => 'Persona externa a la Universidad',
        'desconocido'                  => 'No lo sé / prefiero no decirlo',
    ];
}
