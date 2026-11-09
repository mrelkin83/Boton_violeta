<?php
/**
 * INSTRUMENTO DE DETECCIÓN Y EVALUACIÓN DE RIESGOS
 *
 * Fase 5 · Ac. 002 art. 23
 *
 *   «la Oficina de Bienestar Universitario diseñará un instrumento de
 *    detección y evaluación que contenga factores que determinen riesgos
 *    altos, medios y moderados», cuyo objeto es «minimizar la subjetividad
 *    en la priorización de medidas inmediatas».
 *
 * ⚠ ESTE ARCHIVO NO CONTIENE NI UN SOLO FACTOR, PESO NI UMBRAL.
 *
 * Los factores, sus pesos y los umbrales de banda son una definición de
 * política institucional con consecuencias directas sobre personas:
 * determinan qué casos se atienden primero. Es la decisión 1 del §13.1 de
 * CLAUDE.md, y corresponde a Bienestar Universitario con el Comité para la
 * Equidad de Géneros. Inventarlos sería inventar el criterio con el que la
 * Universidad decide a quién protege antes.
 *
 * Lo que sí está aquí es la maquinaria: versionado, cálculo, renormalización
 * y el registro del apartamiento motivado.
 *
 * Tres decisiones de diseño que el propio artículo impone:
 *
 *   1. VERSIONADO. Cada valoración guarda el instrumento con el que se hizo.
 *      Cuando cambien los pesos, las valoraciones históricas no se
 *      reinterpretan con la vara nueva.
 *   2. EL PUNTAJE NO DECIDE. El artículo es explícito: «no reemplazará el
 *      concepto profesional». La interfaz permite apartarse de la sugerencia
 *      con motivación obligatoria, y registra ambas cosas.
 *   3. RENORMALIZACIÓN. Si la persona no quiere responder algo, el puntaje se
 *      calcula sobre lo respondido. No se la castiga por su silencio.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Instrumento vigente
 *
 * @return array|null Null mientras la Universidad no haya definido ninguno
 */
function instrumento_riesgo_vigente() {
    $fila = db_select_one('SELECT * FROM instrumentos_riesgo WHERE vigente = 1 ORDER BY id DESC LIMIT 1');
    return $fila ?: null;
}

/**
 * Ítems de un instrumento
 *
 * @return array
 */
function items_instrumento($instrumento_id) {
    return db_select(
        'SELECT * FROM instrumento_items WHERE instrumento_id = ? ORDER BY orden, id',
        [(int)$instrumento_id]
    ) ?: [];
}

/**
 * Calcular puntaje y banda sugerida
 *
 * @param int $instrumento_id
 * @param array $respuestas ['codigo_item' => 0|1] — los ausentes se omiten
 * @return array|null ['puntaje','banda','items_omitidos','items_respondidos']
 */
function calcular_riesgo($instrumento_id, array $respuestas) {
    $instrumento = db_select_one('SELECT * FROM instrumentos_riesgo WHERE id = ? LIMIT 1',
        [(int)$instrumento_id]);

    if (!$instrumento) {
        return null;
    }

    $items = items_instrumento($instrumento_id);

    if (empty($items)) {
        return null;
    }

    $peso_total      = 0.0;
    $peso_respondido = 0.0;
    $peso_obtenido   = 0.0;
    $omitidos        = 0;

    foreach ($items as $item) {
        $peso = (float)$item['peso'];
        $peso_total += $peso;

        if (!array_key_exists($item['codigo'], $respuestas) || $respuestas[$item['codigo']] === null) {
            $omitidos++;
            continue;
        }

        $peso_respondido += $peso;

        if ((int)$respuestas[$item['codigo']] === 1) {
            $peso_obtenido += $peso;
        }
    }

    // Renormalización: el puntaje se calcula sobre lo respondido, no sobre el
    // total. Si la persona omite la mitad del instrumento, su riesgo no baja
    // por el hecho de haber callado.
    $puntaje = $peso_respondido > 0
        ? round(($peso_obtenido / $peso_respondido) * $peso_total, 3)
        : 0.0;

    $banda = 'moderado';

    if ($puntaje >= (float)$instrumento['umbral_alto']) {
        $banda = 'alto';
    } elseif ($puntaje >= (float)$instrumento['umbral_medio']) {
        $banda = 'medio';
    }

    return [
        'puntaje'           => $puntaje,
        'banda'             => $banda,
        'items_omitidos'    => $omitidos,
        'items_respondidos' => count($items) - $omitidos,
    ];
}

/**
 * Registrar una valoración de riesgo
 *
 * ⚠ Si la banda final difiere de la sugerida, la motivación es OBLIGATORIA.
 *   El art. 23 permite apartarse del instrumento —«no reemplazará el concepto
 *   profesional»— pero apartarse sin explicar por qué reintroduce exactamente
 *   la subjetividad que el instrumento venía a reducir.
 *
 * @return array ['exito' => bool, 'valoracion_id' => int|null, 'motivo' => string]
 */
function registrar_valoracion_riesgo($denuncia_id, array $respuestas, $valorada_por,
                                     $banda_final = null, $motivacion_ajuste = null,
                                     $es_revision = false) {

    $instrumento = instrumento_riesgo_vigente();

    if ($instrumento === null) {
        return [
            'exito' => false, 'valoracion_id' => null,
            'motivo' => 'No hay instrumento de riesgo vigente. Debe cargarlo la Oficina de '
                      . 'Bienestar Universitario (Ac. 002 art. 23).',
        ];
    }

    $calculo = calcular_riesgo($instrumento['id'], $respuestas);

    if ($calculo === null) {
        return ['exito' => false, 'valoracion_id' => null, 'motivo' => 'El instrumento no tiene ítems.'];
    }

    $banda_final = $banda_final ?? $calculo['banda'];

    if ($banda_final !== $calculo['banda'] && trim((string)$motivacion_ajuste) === '') {
        return [
            'exito' => false, 'valoracion_id' => null,
            'motivo' => 'Apartarse de la banda sugerida exige motivación escrita (Ac. 002 art. 23).',
        ];
    }

    $id = db_insert_simple('valoraciones_riesgo', [
        'denuncia_id'       => (int)$denuncia_id,
        'instrumento_id'    => (int)$instrumento['id'],
        'respuestas_json'   => json_encode($respuestas, JSON_UNESCAPED_UNICODE),
        'puntaje'           => $calculo['puntaje'],
        'banda_sugerida'    => $calculo['banda'],
        'banda_final'       => $banda_final,
        'motivacion_ajuste' => $banda_final !== $calculo['banda'] ? $motivacion_ajuste : null,
        'items_omitidos'    => $calculo['items_omitidos'],
        'valorada_por'      => (int)$valorada_por,
        'es_revision'       => $es_revision ? 1 : 0,
        'created_at'        => date('Y-m-d H:i:s'),
    ]);

    if (!$id) {
        return ['exito' => false, 'valoracion_id' => null, 'motivo' => 'No se pudo registrar la valoración.'];
    }

    // La auditoría guarda la banda, no las respuestas: cada respuesta describe
    // una circunstancia concreta de la vida de la persona
    audit_log($valorada_por, 'riesgo_valorado', 'valoraciones_riesgo', [
        'banda_riesgo' => $banda_final,
        'ajustada'     => $banda_final !== $calculo['banda'] ? 1 : 0,
        'es_revision'  => $es_revision ? 1 : 0,
    ], (int)$denuncia_id);

    return ['exito' => true, 'valoracion_id' => (int)$id, 'motivo' => ''];
}

/**
 * Banda de riesgo vigente de un expediente
 *
 * @return string|null
 */
function banda_riesgo_vigente($denuncia_id) {
    $fila = db_select_one(
        'SELECT banda_final FROM valoraciones_riesgo
          WHERE denuncia_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
        [(int)$denuncia_id]
    );

    return $fila ? $fila['banda_final'] : null;
}

/**
 * Serie histórica de valoraciones
 *
 * El art. 23 exige poder evidenciar «avances efectivos o retrocesos»: sin
 * serie histórica esa evaluación no es posible.
 *
 * @return array
 */
function historico_riesgo($denuncia_id) {
    return db_select(
        'SELECT puntaje, banda_sugerida, banda_final, es_revision, created_at
           FROM valoraciones_riesgo WHERE denuncia_id = ? ORDER BY created_at',
        [(int)$denuncia_id]
    ) ?: [];
}
