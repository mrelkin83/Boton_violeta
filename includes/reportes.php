<?php
/**
 * REPORTES OBLIGATORIOS CON SUPRESIÓN ESTADÍSTICA
 *
 * Fase 6 · CLAUDE.md §10.6 · Implementa RN-10
 *
 * | Reporte                            | Periodicidad | Destino                  | Fuente            |
 * |------------------------------------|--------------|--------------------------|-------------------|
 * | Quejas tramitadas y sanciones      | Trimestral   | Publicación institucional| Ac. 002 art. 31   |
 * | Acoso sexual: quejas y sanciones   | Semestral    | SIVIGE                   | Ley 2365 art. 11.7|
 * | Implementación de medidas          | Semestral    | Rectoría                 | Ac. 002 art. 48   |
 * | UD Libre de VBG                    | Anual        | CSU y Corte Constitucional| Ac. 002 art. 40  |
 *
 * ⚠ RN-10 · Ac. 002 art. 48.1
 *   «por ningún motivo podrá brindar elementos que permitan identificar a la
 *    víctima o persona denunciante, así como a la persona denunciada»
 *
 *   «Un caso de violencia sexual en el proyecto curricular X en 2026-I»
 *   identifica a la persona aunque no diga su nombre. Por eso el sistema
 *   SUPRIME las celdas con frecuencia menor al umbral —y se NIEGA a
 *   exportarlas—, en lugar de marcarlas con una advertencia y confiar en que
 *   quien las lea tenga cuidado.
 *
 *   La supresión primaria no basta: si en una fila solo se suprime una celda,
 *   el total permite deducirla por resta. De ahí la supresión SECUNDARIA.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/** Marca que sustituye a una celda suprimida */
const CELDA_SUPRIMIDA = '—';

/**
 * Umbral de supresión
 *
 * Recomendado k = 5 (§13.1 punto 8). Configurable, pero bajarlo es una
 * decisión con consecuencias sobre personas identificables.
 *
 * @return int
 */
function umbral_supresion() {
    return defined('UMBRAL_SUPRESION') ? max(2, (int)UMBRAL_SUPRESION) : 5;
}

/**
 * Aplicar supresión primaria y secundaria a una tabla de frecuencias
 *
 * @param array $tabla ['fila' => ['columna' => int, …], …]
 * @param int|null $umbral
 * @return array ['tabla' => array, 'suprimidas' => int]
 */
function suprimir_celdas(array $tabla, $umbral = null) {
    $umbral = $umbral ?? umbral_supresion();

    $suprimidas = [];  // "fila|col" => true

    // ── Supresión primaria: toda celda con 0 < valor < umbral ────────────
    // El cero no se suprime: «ningún caso» no identifica a nadie.
    foreach ($tabla as $fila => $columnas) {
        foreach ($columnas as $col => $valor) {
            if (is_numeric($valor) && $valor > 0 && $valor < $umbral) {
                $suprimidas["$fila|$col"] = true;
            }
        }
    }

    // ── Supresión secundaria ─────────────────────────────────────────────
    // Si una fila o una columna queda con exactamente UNA celda suprimida,
    // esa celda se deduce restando del total. Se suprime la siguiente celda
    // más pequeña hasta que no quede ninguna fila ni columna con una sola.
    $vueltas = 0;

    do {
        $cambio = false;
        $vueltas++;

        // Por filas
        foreach ($tabla as $fila => $columnas) {
            $ocultas = array_filter(array_keys($columnas),
                fn($c) => isset($suprimidas["$fila|$c"]));

            if (count($ocultas) === 1 && count($columnas) > 1) {
                $candidata = celda_menor_no_suprimida($columnas, $fila, $suprimidas, 'fila');
                if ($candidata !== null) {
                    $suprimidas["$fila|$candidata"] = true;
                    $cambio = true;
                }
            }
        }

        // Por columnas
        $nombres_col = [];
        foreach ($tabla as $columnas) {
            foreach (array_keys($columnas) as $c) { $nombres_col[$c] = true; }
        }

        foreach (array_keys($nombres_col) as $col) {
            $ocultas = [];
            $visibles = [];

            foreach ($tabla as $fila => $columnas) {
                if (!array_key_exists($col, $columnas)) { continue; }
                if (isset($suprimidas["$fila|$col"])) { $ocultas[] = $fila; }
                else { $visibles[$fila] = $columnas[$col]; }
            }

            if (count($ocultas) === 1 && count($visibles) > 0) {
                asort($visibles);
                $fila_candidata = array_key_first($visibles);
                $suprimidas["$fila_candidata|$col"] = true;
                $cambio = true;
            }
        }

    } while ($cambio && $vueltas < 20);

    // ── Aplicar ──────────────────────────────────────────────────────────
    $resultado = [];

    foreach ($tabla as $fila => $columnas) {
        foreach ($columnas as $col => $valor) {
            $resultado[$fila][$col] = isset($suprimidas["$fila|$col"])
                ? CELDA_SUPRIMIDA
                : $valor;
        }
    }

    return ['tabla' => $resultado, 'suprimidas' => count($suprimidas)];
}

/**
 * Celda visible de menor valor dentro de una fila
 *
 * @return string|null Nombre de la columna
 */
function celda_menor_no_suprimida(array $columnas, $fila, array $suprimidas, $eje) {
    $candidatas = [];

    foreach ($columnas as $col => $valor) {
        if (!isset($suprimidas["$fila|$col"]) && is_numeric($valor)) {
            $candidatas[$col] = $valor;
        }
    }

    if (empty($candidatas)) {
        return null;
    }

    asort($candidatas);

    return (string)array_key_first($candidatas);
}

/**
 * Verificar que un reporte no conserva celdas por debajo del umbral
 *
 * Se ejecuta ANTES de exportar. Si encuentra una, el reporte no sale.
 *
 * @return array ['seguro' => bool, 'motivo' => string]
 */
function verificar_supresion(array $tabla, $umbral = null) {
    $umbral = $umbral ?? umbral_supresion();

    foreach ($tabla as $fila => $columnas) {
        foreach ($columnas as $col => $valor) {
            if (is_numeric($valor) && $valor > 0 && $valor < $umbral) {
                return [
                    'seguro' => false,
                    'motivo' => "RN-10: la celda [$fila][$col] tiene frecuencia $valor, "
                              . "por debajo del umbral $umbral. El reporte no se exporta.",
                ];
            }
        }
    }

    return ['seguro' => true, 'motivo' => ''];
}

// ============================================================================
// GENERACIÓN DE REPORTES
// ============================================================================

/**
 * Reporte trimestral de quejas tramitadas y sanciones (Ac. 002 art. 31)
 *
 * @return array
 */
function reporte_trimestral($anio, $trimestre) {
    $inicio = date('Y-m-d', strtotime(sprintf('%d-%02d-01', $anio, ($trimestre - 1) * 3 + 1)));
    $fin    = date('Y-m-d', strtotime("$inicio +3 months -1 day"));

    $filas = db_select(
        "SELECT tipo_violencia, estado, COUNT(*) AS total
           FROM denuncias
          WHERE DATE(created_at) BETWEEN ? AND ?
          GROUP BY tipo_violencia, estado",
        [$inicio, $fin]
    ) ?: [];

    $tabla = [];
    foreach ($filas as $f) {
        $tabla[$f['tipo_violencia']][$f['estado']] = (int)$f['total'];
    }

    $supresion = suprimir_celdas($tabla);

    return [
        'tipo'        => 'trimestral',
        'periodo'     => sprintf('%d-T%d', $anio, $trimestre),
        'desde'       => $inicio,
        'hasta'       => $fin,
        'total_casos' => array_sum(array_map('array_sum', $tabla)),
        'tabla'       => $supresion['tabla'],
        'suprimidas'  => $supresion['suprimidas'],
        'fundamento'  => 'Ac. 002/2026 art. 31 · Ley 2365/2024 art. 11.7',
    ];
}

/**
 * Reporte semestral a Rectoría sobre implementación de medidas (art. 48)
 *
 * @return array
 */
function reporte_semestral_medidas($anio, $semestre) {
    $inicio = $semestre === 1 ? "$anio-01-01" : "$anio-07-01";
    $fin    = $semestre === 1 ? "$anio-06-30" : "$anio-12-31";

    $filas = db_select(
        "SELECT c.categoria, m.estado, COUNT(*) AS total
           FROM medidas m JOIN catalogo_medidas c ON c.id = m.catalogo_id
          WHERE DATE(m.fecha_solicitud) BETWEEN ? AND ?
          GROUP BY c.categoria, m.estado",
        [$inicio, $fin]
    ) ?: [];

    $tabla = [];
    foreach ($filas as $f) {
        $tabla[$f['categoria']][$f['estado']] = (int)$f['total'];
    }

    $supresion = suprimir_celdas($tabla);

    return [
        'tipo'       => 'semestral_rectoria',
        'periodo'    => sprintf('%d-S%d', $anio, $semestre),
        'desde'      => $inicio,
        'hasta'      => $fin,
        'tabla'      => $supresion['tabla'],
        'suprimidas' => $supresion['suprimidas'],
        'fundamento' => 'Ac. 002/2026 art. 48',
    ];
}

/**
 * Tiempos de respuesta institucional
 *
 * El art. 40 los exige por nombre. Se calculan sobre agregados: ninguna
 * medición individual sale del sistema.
 *
 * @return array
 */
function tiempos_de_respuesta($desde, $hasta) {
    $fila = db_select_one(
        "SELECT COUNT(*) AS casos,
                ROUND(AVG(DATEDIFF(m.fecha_solicitud, d.created_at)), 1) AS dias_hasta_primera_medida,
                ROUND(AVG(DATEDIFF(m.fecha_implementacion, m.fecha_solicitud)), 1) AS dias_hasta_implementacion
           FROM denuncias d
           JOIN medidas m ON m.denuncia_id = d.id
          WHERE DATE(d.created_at) BETWEEN ? AND ?",
        [$desde, $hasta]
    );

    // Con muy pocos casos, un promedio también identifica
    if (!$fila || (int)$fila['casos'] < umbral_supresion()) {
        return [
            'casos' => (int)($fila['casos'] ?? 0),
            'dias_hasta_primera_medida' => CELDA_SUPRIMIDA,
            'dias_hasta_implementacion' => CELDA_SUPRIMIDA,
            'nota' => 'Suprimido por RN-10: muestra insuficiente para publicar promedios.',
        ];
    }

    return [
        'casos' => (int)$fila['casos'],
        'dias_hasta_primera_medida' => $fila['dias_hasta_primera_medida'],
        'dias_hasta_implementacion' => $fila['dias_hasta_implementacion'],
    ];
}

/**
 * Informe anual «UD Libre de VBG» (Ac. 002 art. 40)
 *
 * Va al Consejo Superior Universitario y se remite a la Corte Constitucional
 * en seguimiento de la T-210 de 2023.
 *
 * Contenido que el artículo exige: avances en medidas restaurativas y de no
 * repetición · casos atendidos, tiempos de respuesta, medidas de protección y
 * acompañamiento · evaluación de impacto · recomendaciones.
 *
 * @return array
 */
function informe_anual_art40($anio) {
    $desde = "$anio-01-01";
    $hasta = "$anio-12-31";

    // Casos atendidos por tipo y estado
    $filas = db_select(
        "SELECT tipo_violencia, estado, COUNT(*) AS total FROM denuncias
          WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY tipo_violencia, estado",
        [$desde, $hasta]
    ) ?: [];

    $casos = [];
    foreach ($filas as $f) {
        $casos[$f['tipo_violencia']][$f['estado']] = (int)$f['total'];
    }
    $sup_casos = suprimir_celdas($casos);

    // Medidas por categoría y estado
    $filas = db_select(
        "SELECT c.categoria, m.estado, COUNT(*) AS total
           FROM medidas m JOIN catalogo_medidas c ON c.id = m.catalogo_id
          WHERE DATE(m.fecha_solicitud) BETWEEN ? AND ?
          GROUP BY c.categoria, m.estado",
        [$desde, $hasta]
    ) ?: [];

    $medidas = [];
    foreach ($filas as $f) {
        $medidas[$f['categoria']][$f['estado']] = (int)$f['total'];
    }
    $sup_medidas = suprimir_celdas($medidas);

    // Bandas de riesgo
    $filas = db_select(
        "SELECT banda_final, COUNT(*) AS total FROM valoraciones_riesgo
          WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY banda_final",
        [$desde, $hasta]
    ) ?: [];

    $riesgo = ['riesgo' => []];
    foreach ($filas as $f) {
        $riesgo['riesgo'][$f['banda_final']] = (int)$f['total'];
    }
    $sup_riesgo = suprimir_celdas($riesgo);

    return [
        'tipo'       => 'anual_art40',
        'periodo'    => (string)$anio,
        'destino'    => 'Consejo Superior Universitario y Corte Constitucional (T-210/2023)',
        'fundamento' => 'Ac. 002/2026 art. 40',
        'secciones'  => [
            'casos_atendidos'      => $sup_casos['tabla'],
            'medidas_adoptadas'    => $sup_medidas['tabla'],
            'valoraciones_riesgo'  => $sup_riesgo['tabla'],
            'tiempos_de_respuesta' => tiempos_de_respuesta($desde, $hasta),
        ],
        'suprimidas' => $sup_casos['suprimidas'] + $sup_medidas['suprimidas'] + $sup_riesgo['suprimidas'],
        'umbral'     => umbral_supresion(),
        'nota_metodologica' =>
            'Las celdas marcadas «' . CELDA_SUPRIMIDA . '» corresponden a frecuencias inferiores al '
          . 'umbral de supresión estadística, suprimidas conforme al art. 48.1 del Acuerdo 002 de 2026 '
          . 'para impedir la identificación de las personas involucradas. Se aplicó supresión secundaria '
          . 'para evitar su reconstrucción por diferencia.',
    ];
}

/**
 * Generar, verificar y persistir un reporte
 *
 * ⚠ Se NIEGA a persistir si queda alguna celda por debajo del umbral. No es
 *   una advertencia: es un rechazo.
 *
 * @return array ['exito' => bool, 'reporte_id' => int|null, 'motivo' => string, 'reporte' => array|null]
 */
function generar_reporte($tipo, $periodo, $usuario_id = null) {
    switch ($tipo) {
        case 'trimestral':
            [$anio, $t] = explode('-T', $periodo);
            $reporte = reporte_trimestral((int)$anio, (int)$t);
            break;

        case 'semestral_rectoria':
            [$anio, $s] = explode('-S', $periodo);
            $reporte = reporte_semestral_medidas((int)$anio, (int)$s);
            break;

        case 'anual_art40':
            $reporte = informe_anual_art40((int)$periodo);
            break;

        case 'sivige_semestral':
            [$anio, $s] = explode('-S', $periodo);
            $reporte = reporte_sivige((int)$anio, (int)$s);
            break;

        case 'estado_gestion':
            $reporte = reporte_estado_gestion((int)$periodo);
            break;

        default:
            return ['exito' => false, 'reporte_id' => null, 'motivo' => 'Tipo de reporte desconocido.', 'reporte' => null];
    }

    // Verificación final antes de exportar
    $tablas = $reporte['secciones'] ?? ['principal' => $reporte['tabla'] ?? []];

    foreach ($tablas as $nombre => $tabla) {
        if (!is_array($tabla)) { continue; }

        // Estructura plana (tiempos de respuesta): no es tabla de frecuencias
        if (!is_array(reset($tabla))) { continue; }

        $check = verificar_supresion($tabla);

        if (!$check['seguro']) {
            log_error('Reporte rechazado por RN-10: ' . $check['motivo'], 'error');

            audit_log($usuario_id, 'reporte_rechazado_rn10', 'reportes_generados', [
                'motivo' => 'umbral',
            ]);

            return ['exito' => false, 'reporte_id' => null, 'motivo' => $check['motivo'], 'reporte' => null];
        }
    }

    $contenido = json_encode($reporte, JSON_UNESCAPED_UNICODE);

    $id = db_insert_simple('reportes_generados', [
        'tipo'              => $tipo,
        'periodo'           => $periodo,
        'generado_por'      => $usuario_id !== null ? (int)$usuario_id : null,
        'celdas_suprimidas' => (int)($reporte['suprimidas'] ?? 0),
        'umbral_aplicado'   => umbral_supresion(),
        'hash_contenido'    => hash('sha256', $contenido),
        'contenido_json'    => $contenido,
        'created_at'        => date('Y-m-d H:i:s'),
    ]);

    if (!$id) {
        return ['exito' => false, 'reporte_id' => null, 'motivo' => 'No se pudo persistir el reporte.', 'reporte' => $reporte];
    }

    audit_log($usuario_id, 'reporte_generado', 'reportes_generados', [
        'motivo' => $tipo,
    ], (int)$id);

    return ['exito' => true, 'reporte_id' => (int)$id, 'motivo' => '', 'reporte' => $reporte];
}

// ============================================================================
// v3.1 · REPORTES ADICIONALES (Fase F · §7.6 y §16.4)
// ============================================================================

/**
 * Reporte semestral SIVIGE · Ley 2365/2024 art. 11.7
 *
 * Quejas y sanciones de acoso sexual, agregadas. Se remite en los últimos
 * 10 días del semestre. RN-10 aplicada en generación.
 *
 * @return array
 */
function reporte_sivige($anio, $semestre) {
    $desde = $semestre === 1 ? "$anio-01-01" : "$anio-07-01";
    $hasta = $semestre === 1 ? "$anio-06-30" : "$anio-12-31";

    $filas = db_select(
        "SELECT tipo_violencia, estado, COUNT(*) AS total FROM denuncias
          WHERE tipo_violencia = 'sexual' AND DATE(created_at) BETWEEN ? AND ?
          GROUP BY tipo_violencia, estado",
        [$desde, $hasta]
    ) ?: [];

    $quejas = [];
    foreach ($filas as $f) {
        $quejas[$f['tipo_violencia']][$f['estado']] = (int)$f['total'];
    }
    $sup_quejas = suprimir_celdas($quejas);

    $filas = db_select(
        "SELECT s.autoridad, COUNT(*) AS total
           FROM sanciones s JOIN denuncias d ON d.id = s.denuncia_id
          WHERE d.tipo_violencia = 'sexual' AND s.en_firme = 1
            AND s.en_firme_at BETWEEN ? AND ?
          GROUP BY s.autoridad",
        [$desde, $hasta]
    ) ?: [];

    $sanciones = [];
    foreach ($filas as $f) {
        $sanciones['sanciones_en_firme'][$f['autoridad']] = (int)$f['total'];
    }
    $sup_sanciones = suprimir_celdas($sanciones);

    return [
        'tipo'       => 'sivige_semestral',
        'periodo'    => "$anio-S$semestre",
        'destino'    => 'SIVIGE (Sistema Integrado de Información de Violencias de Género)',
        'fundamento' => 'Ley 2365/2024 art. 11 num. 7',
        'secciones'  => [
            'quejas_acoso_sexual' => $sup_quejas['tabla'],
            'sanciones_en_firme'  => $sup_sanciones['tabla'],
        ],
        'suprimidas' => $sup_quejas['suprimidas'] + $sup_sanciones['suprimidas'],
        'umbral'     => umbral_supresion(),
        'nota_metodologica' => 'Agregado con supresión estadística (RN-10). Sin identificadores.',
    ];
}

/**
 * Informe de estado de gestión · Res. 142/2026 anexo, indicadores 2.6, 2.7,
 * 3.1–3.4 y 4.3
 *
 * Conteos por etapa de ruta y etapa procesal, remisiones por destino,
 * sanciones ejecutoriadas y el indicador 3.2 (% de actuaciones de oficio
 * sobre quejas anónimas con mérito). Todo agregado, nunca por persona.
 *
 * @return array
 */
function reporte_estado_gestion($anio) {
    $desde = "$anio-01-01";
    $hasta = "$anio-12-31";

    // Casos por etapa de ruta y etapa procesal (ind. 4.3)
    $filas = db_select(
        "SELECT etapa_ruta, etapa_procesal, COUNT(*) AS total FROM denuncias
          WHERE DATE(created_at) BETWEEN ? AND ?
          GROUP BY etapa_ruta, etapa_procesal",
        [$desde, $hasta]
    ) ?: [];

    $etapas = [];
    foreach ($filas as $f) {
        $etapas[$f['etapa_ruta']][$f['etapa_procesal']] = (int)$f['total'];
    }
    $sup_etapas = suprimir_celdas($etapas);

    // Remisiones por destino y ámbito (ind. 2.6, 3.1, 3.3)
    $filas = db_select(
        "SELECT dr.ambito, r.estado, COUNT(*) AS total
           FROM remisiones_externas r JOIN destinos_remision dr ON dr.id = r.destino_id
          WHERE DATE(r.created_at) BETWEEN ? AND ?
          GROUP BY dr.ambito, r.estado",
        [$desde, $hasta]
    ) ?: [];

    $remisiones = [];
    foreach ($filas as $f) {
        $remisiones[$f['ambito']][$f['estado']] = (int)$f['total'];
    }
    $sup_remisiones = suprimir_celdas($remisiones);

    // Sanciones ejecutoriadas por autoridad (ind. 3.4)
    $filas = db_select(
        "SELECT autoridad, COUNT(*) AS total FROM sanciones
          WHERE en_firme = 1 AND en_firme_at BETWEEN ? AND ?
          GROUP BY autoridad",
        [$desde, $hasta]
    ) ?: [];

    $sanciones = [];
    foreach ($filas as $f) {
        $sanciones['ejecutoriadas'][$f['autoridad']] = (int)$f['total'];
    }
    $sup_sanciones = suprimir_celdas($sanciones);

    // Indicador 3.2 · quejas anónimas con mérito y decisión de oficio
    $con_merito = db_select_one(
        "SELECT COUNT(*) AS total FROM reportes_anonimos
          WHERE tiene_pruebas = 1 AND DATE(created_at) BETWEEN ? AND ?",
        [$desde, $hasta]
    );
    $de_oficio = db_select_one(
        "SELECT COUNT(*) AS total FROM reportes_anonimos
          WHERE tiene_pruebas = 1 AND decision = 'convertir_en_expediente_oficio'
            AND DATE(created_at) BETWEEN ? AND ?",
        [$desde, $hasta]
    );

    $total_merito = (int)($con_merito['total'] ?? 0);
    $indicador_32 = $total_merito > 0
        ? round(((int)($de_oficio['total'] ?? 0)) * 100 / $total_merito, 1)
        : null;

    return [
        'tipo'       => 'estado_gestion',
        'periodo'    => (string)$anio,
        'destino'    => 'Rectoría y OCID, con reserva',
        'fundamento' => 'Res. 142/2026 anexo · Plan de Trabajo Obj. 2-4',
        'secciones'  => [
            'casos_por_etapa'        => $sup_etapas['tabla'],
            'remisiones_por_ambito'  => $sup_remisiones['tabla'],
            'sanciones_ejecutoriadas'=> $sup_sanciones['tabla'],
            'indicador_3_2'          => [
                'quejas_anonimas_con_merito' => $total_merito,
                'porcentaje_actuacion_oficio' => $indicador_32 !== null ? $indicador_32 . ' %' : 'sin datos',
            ],
        ],
        'suprimidas' => $sup_etapas['suprimidas'] + $sup_remisiones['suprimidas'] + $sup_sanciones['suprimidas'],
        'umbral'     => umbral_supresion(),
        'nota_metodologica' => 'Conteos agregados por etapa, ámbito y autoridad, con supresión '
                             . 'estadística (RN-10). Ningún dato permite identificar un caso.',
    ];
}
