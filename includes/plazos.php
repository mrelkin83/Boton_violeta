<?php
/**
 * MOTOR DE PLAZOS EN DÍAS HÁBILES
 *
 * Fase 5 · CLAUDE.md §10.5
 *
 * La constante `BUSINESS_DAYS_CALCULATION` existía en config.php desde el
 * primer día y nunca se usó. Un plazo vencido sin alerta es, en el marco de un
 * fallo de tutela con seguimiento de la Corte Constitucional, un
 * incumplimiento documentado.
 *
 * Los festivos colombianos SÍ son calculables: la Ley 51 de 1983 («ley
 * Emiliani») define cuáles son fijos, cuáles se trasladan al lunes siguiente y
 * cuáles dependen de la Pascua. No es normativa inventada: es aritmética sobre
 * una ley publicada, y el resultado se puede contrastar con cualquier
 * calendario oficial.
 *
 * El calendario académico, en cambio, NO es calculable: cambia cada semestre y
 * lo define la Universidad. Su tabla se entrega vacía.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Festivos colombianos de un año (Ley 51 de 1983)
 *
 * @param int $anio
 * @return array fecha 'Y-m-d' => nombre
 */
function festivos_colombia($anio) {
    static $cache = [];

    $anio = (int)$anio;

    if (isset($cache[$anio])) {
        return $cache[$anio];
    }

    $festivos = [];

    // ── Fijos: no se trasladan ──────────────────────────────────────────
    $fijos = [
        '01-01' => 'Año Nuevo',
        '05-01' => 'Día del Trabajo',
        '07-20' => 'Independencia de Colombia',
        '08-07' => 'Batalla de Boyacá',
        '12-08' => 'Inmaculada Concepción',
        '12-25' => 'Navidad',
    ];

    foreach ($fijos as $md => $nombre) {
        $festivos["$anio-$md"] = $nombre;
    }

    // ── Trasladables al lunes siguiente (Ley 51 de 1983) ────────────────
    $trasladables = [
        '01-06' => 'Reyes Magos',
        '03-19' => 'San José',
        '06-29' => 'San Pedro y San Pablo',
        '08-15' => 'Asunción de la Virgen',
        '10-12' => 'Día de la Raza',
        '11-01' => 'Todos los Santos',
        '11-11' => 'Independencia de Cartagena',
    ];

    foreach ($trasladables as $md => $nombre) {
        $fecha = trasladar_a_lunes("$anio-$md");
        $festivos[$fecha] = $nombre;
    }

    // ── Dependientes de la Pascua ───────────────────────────────────────
    $pascua = pascua($anio);

    // Jueves y Viernes Santo NO se trasladan
    $festivos[date('Y-m-d', strtotime("$pascua -3 days"))] = 'Jueves Santo';
    $festivos[date('Y-m-d', strtotime("$pascua -2 days"))] = 'Viernes Santo';

    // Estos sí se trasladan al lunes
    $moviles_pascua = [
        43 => 'Ascensión del Señor',
        64 => 'Corpus Christi',
        71 => 'Sagrado Corazón de Jesús',
    ];

    foreach ($moviles_pascua as $dias => $nombre) {
        $fecha = date('Y-m-d', strtotime("$pascua +$dias days"));
        $festivos[trasladar_a_lunes($fecha)] = $nombre;
    }

    ksort($festivos);
    $cache[$anio] = $festivos;

    return $festivos;
}

/**
 * Domingo de Pascua de un año
 *
 * `easter_date()` requiere la extensión calendar, que no siempre está
 * disponible en hosting compartido. Se implementa el algoritmo de Meeus
 * (calendario gregoriano) como respaldo.
 *
 * @param int $anio
 * @return string 'Y-m-d'
 */
function pascua($anio) {
    $anio = (int)$anio;

    if (function_exists('easter_date')) {
        return date('Y-m-d', easter_date($anio));
    }

    $a = $anio % 19;
    $b = intdiv($anio, 100);
    $c = $anio % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

    return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
}

/**
 * Trasladar una fecha al lunes siguiente si no cae en lunes
 *
 * @param string $fecha 'Y-m-d'
 * @return string
 */
function trasladar_a_lunes($fecha) {
    $ts = strtotime($fecha);

    if ((int)date('N', $ts) === 1) {
        return date('Y-m-d', $ts);
    }

    return date('Y-m-d', strtotime($fecha . ' next monday'));
}

/**
 * ¿Es día hábil?
 *
 * No lo son sábados, domingos, festivos de ley ni festivos institucionales
 * cargados en la tabla `festivos`.
 *
 * @param string $fecha 'Y-m-d'
 * @return bool
 */
function es_dia_habil($fecha) {
    $ts = strtotime($fecha);

    if ($ts === false) {
        return false;
    }

    $dia_semana = (int)date('N', $ts);

    if ($dia_semana >= 6) {
        return false;
    }

    $ymd = date('Y-m-d', $ts);

    if (isset(festivos_colombia((int)date('Y', $ts))[$ymd])) {
        return false;
    }

    // Festivos institucionales adicionales (semana de receso, etc.)
    $extra = db_select_one('SELECT fecha FROM festivos WHERE fecha = ? LIMIT 1', [$ymd]);

    return $extra === false || $extra === null;
}

/**
 * Sumar días hábiles a una fecha
 *
 * El día de partida no cuenta: un plazo de «5 días hábiles» desde el lunes
 * vence el lunes siguiente, no el viernes.
 *
 * @param string $fecha 'Y-m-d'
 * @param int $dias
 * @return string 'Y-m-d'
 */
function sumar_dias_habiles($fecha, $dias) {
    $ts = strtotime($fecha);

    if ($ts === false) {
        return date('Y-m-d');
    }

    $dias = (int)$dias;
    $contados = 0;
    $limite = 0; // salvaguarda contra bucles infinitos

    while ($contados < $dias && $limite < 3650) {
        $ts = strtotime('+1 day', $ts);
        $limite++;

        if (es_dia_habil(date('Y-m-d', $ts))) {
            $contados++;
        }
    }

    return date('Y-m-d', $ts);
}

/**
 * Contar días hábiles entre dos fechas (sin incluir la inicial)
 *
 * @return int
 */
function dias_habiles_entre($desde, $hasta) {
    $ts = strtotime($desde);
    $fin = strtotime($hasta);

    if ($ts === false || $fin === false || $ts > $fin) {
        return 0;
    }

    $total = 0;

    while ($ts < $fin) {
        $ts = strtotime('+1 day', $ts);
        if (es_dia_habil(date('Y-m-d', $ts))) {
            $total++;
        }
    }

    return $total;
}

/**
 * Calcular la fecha límite de un plazo del catálogo
 *
 * @param string $codigo_plazo
 * @param string|null $desde 'Y-m-d'; por defecto hoy
 * @return string|null 'Y-m-d'
 */
function calcular_fecha_limite($codigo_plazo, $desde = null) {
    $desde = $desde ?? date('Y-m-d');

    $plazo = db_select_one(
        'SELECT cantidad, unidad FROM catalogo_plazos WHERE codigo = ? AND activo = 1 LIMIT 1',
        [$codigo_plazo]
    );

    if (!$plazo) {
        log_error('Plazo no encontrado en el catálogo: ' . $codigo_plazo, 'error');
        return null;
    }

    switch ($plazo['unidad']) {
        case 'dias_habiles':
            return sumar_dias_habiles($desde, (int)$plazo['cantidad']);

        case 'dias_calendario':
            return date('Y-m-d', strtotime($desde . ' +' . (int)$plazo['cantidad'] . ' days'));

        case 'meses':
            return date('Y-m-d', strtotime($desde . ' +' . (int)$plazo['cantidad'] . ' months'));
    }

    return null;
}

/**
 * ¿Está vencido?
 *
 * @return bool
 */
function esta_vencido($fecha_limite) {
    return strtotime($fecha_limite) < strtotime(date('Y-m-d'));
}

/**
 * Días hábiles restantes hasta la fecha límite
 *
 * Negativo si ya venció.
 *
 * @return int
 */
function dias_restantes($fecha_limite) {
    $hoy = date('Y-m-d');

    if (esta_vencido($fecha_limite)) {
        return -dias_habiles_entre($fecha_limite, $hoy);
    }

    return dias_habiles_entre($hoy, $fecha_limite);
}
