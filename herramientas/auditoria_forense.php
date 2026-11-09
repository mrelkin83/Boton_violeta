<?php
/**
 * AUDITORÍA FORENSE DEL PROYECTO
 *
 * Recorre cada archivo, función, ruta, formulario y consulta buscando
 * defectos que la ejecución normal no revela.
 *
 * Comprueba, en orden de gravedad:
 *
 *   A. Integridad estructural   · rutas rotas, includes inexistentes,
 *                                 funciones invocadas pero no definidas,
 *                                 funciones definidas dos veces
 *   B. Seguridad                · POST sin CSRF, módulos privados sin guarda
 *                                 de sesión, SQL construido por concatenación,
 *                                 salidas sin escapar
 *   C. Coherencia con la base   · columnas y tablas referenciadas que no
 *                                 existen en el esquema real
 *   D. Calidad                  · código muerto, TODO pendientes, funciones
 *                                 nunca invocadas, interpolación rota
 *
 * Uso:
 *   BV_CONFIG=/ruta/config.php php herramientas/auditoria_forense.php
 *   … --seccion=B        para una sola sección
 *   … --detalle          para ver cada hallazgo con su archivo y línea
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__));

ob_start();
require_once BASE_PATH . '/init.php';
ob_end_clean();

$detalle  = in_array('--detalle', $argv, true);
$seccion  = null;
foreach ($argv as $a) {
    if (strpos($a, '--seccion=') === 0) { $seccion = strtoupper(substr($a, 10)); }
}

$hallazgos = [];   // [gravedad, seccion, archivo, linea, descripcion]

function anotar($gravedad, $seccion, $archivo, $linea, $descripcion) {
    global $hallazgos;
    $hallazgos[] = compact('gravedad', 'seccion', 'archivo', 'linea', 'descripcion');
}

/**
 * Camino relativo, con separadores normalizados
 *
 * Los caminos llegan mezclados: glob() devuelve «/» y BASE_PATH usa «\» en
 * Windows. Sin normalizar, el prefijo no coincide, la ruta no se acorta y los
 * filtros de «esto es una prueba, no código productivo» dejan de aplicarse:
 * la auditoría empieza a reportar los helpers de las suites como defectos.
 */
function ruta_corta($ruta) {
    $ruta = str_replace('\\', '/', $ruta);
    $base = str_replace('\\', '/', BASE_PATH) . '/';
    return str_replace($base, '', $ruta);
}

/** ¿Es código que se ejecuta en producción, y no una prueba o herramienta? */
function es_codigo_productivo($ruta) {
    $corto = ruta_corta($ruta);
    return strpos($corto, 'pruebas/') !== 0 && strpos($corto, 'herramientas/') !== 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// Inventario de archivos
// ─────────────────────────────────────────────────────────────────────────────
// El recorrido es recursivo y por exclusión, no por una lista de carpetas.
//
// Antes había una lista fija y funcionó hasta que apareció `modules/perfil`:
// la carpeta no estaba escrita, así que el módulo entero quedó fuera de la
// auditoría y el informe siguió diciendo «0 hallazgos» con toda naturalidad.
// Un auditor que solo mira donde alguien se acordó de mandarlo mirar da
// tranquilidad falsa, que es peor que no dar ninguna.
$excluidos = ['vendor', 'node_modules', 'cache', 'logs', 'uploads', 'storage', '.git', 'bd'];

$php = [];
$cola = [BASE_PATH];

while ($cola) {
    $dir = array_pop($cola);

    foreach (scandir($dir) ?: [] as $entrada) {
        if ($entrada === '.' || $entrada === '..') { continue; }

        $ruta = $dir . DIRECTORY_SEPARATOR . $entrada;

        if (is_dir($ruta)) {
            if (!in_array($entrada, $excluidos, true)) { $cola[] = $ruta; }
            continue;
        }

        // Se normaliza a «/» al guardarlo: el resto del análisis indexa por
        // ruta y en Windows scandir() devuelve «\». Mezclarlos hace que una
        // búsqueda por clave falle en silencio, que es como el enrutador pasó
        // de tener 15 rutas a tener 0 sin que nada avisara.
        if (substr($entrada, -4) === '.php') { $php[] = str_replace('\\', '/', $ruta); }
    }
}

sort($php);

$fuente = [];
foreach ($php as $f) { $fuente[$f] = file_get_contents($f); }

// ═════════════════════════════════════════════════════════════════════════════
// A · INTEGRIDAD ESTRUCTURAL
// ═════════════════════════════════════════════════════════════════════════════

// A1 · Rutas del enrutador que apuntan a archivos inexistentes
$enrutador = $fuente[str_replace('\\', '/', BASE_PATH) . '/index_nativo.php'] ?? '';

if ($enrutador === '') {
    // Sin esto, un fallo al localizar el enrutador se leía como «0 rutas, todo
    // en orden». La ausencia de datos no es una comprobación superada.
    anotar('CRITICO', 'A', 'index_nativo.php', 0,
        'No se pudo leer el enrutador: las rutas quedaron SIN auditar');
}
preg_match_all("/'([a-z0-9\-]+)'\s*=>\s*'([^']+\.php)'/", $enrutador, $rutas, PREG_SET_ORDER);

foreach ($rutas as $r) {
    if (!is_file(BASE_PATH . '/' . $r[2])) {
        anotar('CRITICO', 'A', 'index_nativo.php', 0,
            "La ruta ?page={$r[1]} apunta a {$r[2]}, que NO existe");
    }
}

// A2 · include/require de archivos inexistentes
foreach ($fuente as $archivo => $codigo) {
    // El literal debe CERRAR la expresión (`;` o `)` justo detrás). Sin esa
    // exigencia, `require BASE_PATH . '/' . $modulo;` se leía como un include
    // de la ruta «/», que evidentemente no existe, y se reportaba como fallo
    // crítico. Cuando la ruta se completa con una variable, aquí no hay nada
    // que verificar estáticamente.
    if (preg_match_all('/(?:require|include)(?:_once)?\s+BASE_PATH\s*\.\s*[\'"]([^\'"]+)[\'"]\s*[;)]/', $codigo, $m, PREG_SET_ORDER)) {
        foreach ($m as $inc) {
            if (!is_file(BASE_PATH . $inc[1])) {
                anotar('CRITICO', 'A', ruta_corta($archivo), 0,
                    "Incluye {$inc[1]}, que no existe");
            }
        }
    }
    if (preg_match_all('/(?:require|include)(?:_once)?\s+INCLUDES_PATH\s*\.\s*[\'"]([^\'"]+)[\'"]\s*[;)]/', $codigo, $m, PREG_SET_ORDER)) {
        foreach ($m as $inc) {
            if (!is_file(BASE_PATH . '/includes' . $inc[1])) {
                anotar('CRITICO', 'A', ruta_corta($archivo), 0,
                    "Incluye includes{$inc[1]}, que no existe");
            }
        }
    }
}

/**
 * Devolver el código sin comentarios, conservando los saltos de línea
 *
 * Hacía falta porque el instrumento se estaba leyendo a sí mismo: los
 * comentarios que documentan un defecto ya reparado —«el botón era un
 * alert('Próximamente')»— se contaban como el defecto. Un auditor que no
 * distingue el código de la prosa sobre el código no sirve.
 *
 * Se usa el tokenizador y no una expresión regular porque `//` dentro de una
 * cadena («'http://…'») no abre un comentario, y la regex no lo sabe.
 */
function sin_comentarios($codigo) {
    $tokens = @token_get_all($codigo);

    if ($tokens === false) {
        return $codigo;
    }

    $limpio = '';

    foreach ($tokens as $t) {
        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
            // Se sustituye por sus saltos de línea para no desplazar el resto
            $limpio .= str_repeat("\n", substr_count($t[1], "\n"));
            continue;
        }

        $limpio .= is_array($t) ? $t[1] : $t;
    }

    // Los comentarios HTML viven en el texto literal, fuera del alcance del
    // tokenizador de PHP
    return preg_replace('/<!--.*?-->/s', ' ', $limpio);
}

/**
 * Funciones cuyo valor de retorno es un fragmento SQL construido con marcadores
 *
 * Lista corta y explícita. Añadir algo aquí es una decisión consciente: se está
 * afirmando que esa función jamás devuelve un dato de entrada dentro del SQL.
 */
const FRAGMENTOS_SQL = [
    'restriccion_listado_expedientes',
];

/**
 * ¿Se puede demostrar que esta variable contiene un fragmento SQL seguro?
 *
 * Se aceptan exactamente dos orígenes, y hay que encontrarlos en el archivo:
 *
 *   1. Viene de una función de FRAGMENTOS_SQL.
 *   2. Se arma con implode() sobre un arreglo cuyas piezas son todas literales
 *      sin interpolación —el patrón `$where_conditions[] = 'x = ?'`—, más los
 *      valores viajando aparte como parámetros.
 *
 * Cualquier otro origen —o ninguno— se reporta. La duda no exime.
 */
function fragmento_sql_demostrable($var, $codigo) {
    // Origen 1: asignada desde una función que produce fragmentos
    foreach (FRAGMENTOS_SQL as $constructor) {
        if (preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*' . preg_quote($constructor, '/') . '\s*\(/', $codigo)) {
            return true;
        }
    }

    // Origen 2: implode() sobre un arreglo de condiciones literales
    if (preg_match('/\$' . preg_quote($var, '/') . '\s*=[^;]*implode\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*\$([a-z_][a-z0-9_]*)/i',
                   $codigo, $m)) {
        $arreglo = $m[1];

        // Cada pieza que entra al arreglo debe ser un literal sin variables
        if (preg_match_all('/\$' . preg_quote($arreglo, '/') . '\[\]\s*=\s*([^;]+);/', $codigo, $piezas)) {
            foreach ($piezas[1] as $pieza) {
                if (strpos($pieza, '$') !== false) {
                    return false;
                }
            }
            return true;
        }
    }

    return false;
}

/**
 * Analizar un archivo con el tokenizador de PHP
 *
 * Las expresiones regulares no sirven aquí: encuentran «NOW()» dentro de una
 * consulta SQL y «riesgo(» dentro de un comentario en español, y los reportan
 * como funciones inexistentes. El tokenizador distingue código de texto.
 *
 * @return array ['definidas' => [nombre => linea], 'llamadas' => [nombre => linea]]
 */
function analizar_php($codigo) {
    $tokens = @token_get_all($codigo);

    if ($tokens === false) {
        return ['definidas' => [], 'llamadas' => []];
    }

    $definidas = [];
    $llamadas  = [];
    $total     = count($tokens);

    for ($i = 0; $i < $total; $i++) {
        $t = $tokens[$i];

        if (!is_array($t)) { continue; }

        // Definición: T_FUNCTION seguido de T_STRING
        if ($t[0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $total && $j < $i + 4; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $definidas[strtolower($tokens[$j][1])] = $tokens[$j][2];
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { continue; }
                break; // función anónima o flecha
            }
            continue;
        }

        if ($t[0] !== T_STRING) { continue; }

        // Descartar accesos a método o propiedad estática
        $anterior = null;
        for ($k = $i - 1; $k >= 0; $k--) {
            if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { continue; }
            $anterior = $tokens[$k];
            break;
        }
        if (is_array($anterior)
            && in_array($anterior[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION,
                                       T_NEW, T_CONST], true)) {
            continue;
        }

        // Siguiente token significativo debe ser «(»
        $siguiente = null;
        for ($k = $i + 1; $k < $total; $k++) {
            if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { continue; }
            $siguiente = $tokens[$k];
            break;
        }
        if ($siguiente !== '(') { continue; }

        $llamadas[strtolower($t[1])] = $t[2];
    }

    return ['definidas' => $definidas, 'llamadas' => $llamadas];
}

$analisis = [];
foreach ($fuente as $archivo => $codigo) {
    $analisis[$archivo] = analizar_php($codigo);
}

// A3 · Funciones definidas más de una vez
$definidas = [];
foreach ($analisis as $archivo => $datos) {
    foreach ($datos['definidas'] as $nombre => $linea) {
        $definidas[$nombre][] = ruta_corta($archivo) . ':' . $linea;
    }
}
foreach ($definidas as $nombre => $lugares) {
    // Las pruebas y herramientas definen helpers homónimos a propósito
    $productivos = array_filter($lugares, fn($l) =>
        strpos($l, 'pruebas/') !== 0 && strpos($l, 'herramientas/') !== 0);
    if (count($productivos) > 1) {
        anotar('CRITICO', 'A', implode(' y ', $productivos), 0,
            "La función {$nombre}() está definida más de una vez");
    }
}

// A4 · Funciones invocadas en código productivo que no existen
$conocidas = array_map('strtolower', get_defined_functions()['internal']);
$conocidas = array_merge($conocidas, array_keys($definidas));

// Construcciones del lenguaje que el tokenizador puede devolver como T_STRING
$lenguaje = ['isset', 'unset', 'empty', 'list', 'echo', 'print', 'exit', 'die',
             'include', 'require', 'include_once', 'require_once', 'array',
             'match', 'fn', 'static', 'parent', 'self'];

$productivos = array_filter($php, 'es_codigo_productivo');

foreach ($productivos as $archivo) {
    foreach ($analisis[$archivo]['llamadas'] as $nombre => $linea) {
        if (in_array($nombre, $lenguaje, true)) { continue; }
        if (in_array($nombre, $conocidas, true)) { continue; }

        anotar('CRITICO', 'A', ruta_corta($archivo), $linea,
            "Invoca {$nombre}(), que no está definida en ninguna parte");
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// B · SEGURIDAD
// ═════════════════════════════════════════════════════════════════════════════

foreach ($productivos as $archivo) {
    $codigo = $fuente[$archivo];
    $corto  = ruta_corta($archivo);

    // B1 · Módulos que procesan POST sin verificar CSRF
    $es_modulo = strpos($corto, 'modules') === 0 || strpos($corto, 'views') === 0;
    $procesa_post = preg_match("/REQUEST_METHOD.*===?\s*'POST'/", $codigo);

    if ($es_modulo && $procesa_post && strpos($codigo, 'csrf_protect()') === false) {
        anotar('CRITICO', 'B', $corto, 0, 'Procesa POST sin llamar a csrf_protect()');
    }

    // B2 · Formularios POST sin campo CSRF
    if (preg_match_all('/<form[^>]*method=["\']POST["\'][^>]*>(.*?)<\/form>/is', $codigo, $m, PREG_SET_ORDER)) {
        foreach ($m as $formulario) {
            if (strpos($formulario[1], 'csrf_field()') === false
                && strpos($formulario[1], 'csrf_token') === false) {
                anotar('CRITICO', 'B', $corto, 0, 'Formulario POST sin token CSRF');
            }
        }
    }

    // B3 · Módulos privados sin control de acceso
    $privados = ['modules/dashboard', 'modules/usuarios'];
    foreach ($privados as $prefijo) {
        if (strpos($corto, $prefijo) === 0
            && strpos($codigo, 'require_auth()') === false
            && strpos($codigo, 'require_any_role') === false
            && strpos($codigo, 'require_role') === false) {
            anotar('CRITICO', 'B', $corto, 0, 'Módulo privado sin require_auth()');
        }
    }

    // B4 · SQL con variables interpoladas
    //
    // La versión anterior eximía por NOMBRE: cualquier variable llamada `$sql`,
    // `$query` o `$where_sql` pasaba sin más. Es la clase de excepción que
    // acaba tapando el defecto que debía encontrar — basta con nombrar así una
    // variable contaminada. Ahora la exención hay que ganarla: se rastrea de
    // dónde sale el fragmento y solo se acepta si su origen es demostrable.
    if (preg_match_all('/(?:db_select|db_select_one|db_execute|db_insert)\s*\(\s*"([^"]*\$[a-z_][^"]*)"/i',
                       $codigo, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $consulta) {
            $linea = substr_count(substr($codigo, 0, $consulta[1]), "\n") + 1;

            // Nombres base de todo lo interpolado: `$x` y `{$x['k']}` → `x`
            preg_match_all('/\$\{?([a-z_][a-z0-9_]*)/i', $consulta[0], $vars);
            $interpoladas = array_unique($vars[1]);

            $sospechosas = [];

            foreach ($interpoladas as $var) {
                if (!fragmento_sql_demostrable($var, $codigo)) {
                    $sospechosas[] = '$' . $var;
                }
            }

            if (!empty($sospechosas)) {
                anotar('CRITICO', 'B', $corto, $linea,
                    'Consulta SQL con ' . implode(', ', $sospechosas) . ' de origen no demostrado: '
                    . substr(trim($consulta[0]), 0, 50));
            }
        }
    }

    /*
     * B4b · Bloque PHP cerrado por accidente
     *
     * Nació de un fallo real y caro: un comentario de línea que contenía la
     * etiqueta de cierre de PHP. Escrita dentro de un `//`, esa etiqueta cierra
     * el bloque de verdad. A partir de ahí el resto del archivo se imprime como
     * texto en la página: el código fuente a la vista, las variables sin
     * definir y los avisos de PHP con la ruta absoluta del servidor.
     *
     * Lo que hace peligroso este defecto es todo lo que NO lo detecta:
     *   · `php -l` lo acepta — sigue siendo PHP sintácticamente válido;
     *   · la página responde 200 y con contenido de sobra;
     *   · no hay error fatal, solo avisos;
     *   · una prueba que busque un texto concreto en la salida lo encuentra.
     *
     * Se detecta el síntoma y no la causa: si un tramo de HTML literal contiene
     * código PHP reconocible, algo cerró el bloque antes de tiempo. Da igual si
     * fue un comentario, una comilla o un descuido.
     */
    $tokens = @token_get_all($codigo);

    if ($tokens !== false) {
        foreach ($tokens as $t) {
            if (!is_array($t) || $t[0] !== T_INLINE_HTML) { continue; }

            // Firmas que no aparecen en HTML pero sí en PHP suelto
            if (preg_match('/=>\s*[\'"\[]|\$[a-z_]\w*\s*=\s*[\'"\[]|\);\s*$/mi', $t[1])) {
                anotar('CRITICO', 'B', $corto, $t[2],
                    'Código PHP se está imprimiendo como texto: el bloque se cerró antes de tiempo — '
                    . trim(preg_replace('/\s+/', ' ', substr($t[1], 0, 60))));
                break;
            }
        }
    }

    // B5 · Salidas sin escapar de variables de base de datos
    if (preg_match_all('/<\?=\s*\$([a-z_]+)\[[\'"]([a-z_]+)[\'"]\]\s*\?>/i', $codigo, $m, PREG_SET_ORDER)) {
        foreach ($m as $salida) {
            $campos_seguros = ['id', 'total', 'activo', 'privado', 'ocurrencias'];
            if (in_array($salida[2], $campos_seguros, true)) { continue; }
            anotar('ALTO', 'B', $corto, 0,
                "Imprime \${$salida[1]}['{$salida[2]}'] sin e()");
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// C · COHERENCIA CON EL ESQUEMA REAL
// ═════════════════════════════════════════════════════════════════════════════

$esquema = [];
foreach (db_select('SHOW TABLES') ?: [] as $fila) {
    $tabla = reset($fila);
    $columnas = [];
    foreach (db_select("SHOW COLUMNS FROM `$tabla`") ?: [] as $col) {
        $columnas[] = $col['Field'];
    }
    $esquema[$tabla] = $columnas;
}

// C1 · Tablas referenciadas que no existen
foreach ($productivos as $archivo) {
    $codigo = $fuente[$archivo];
    $corto  = ruta_corta($archivo);

    // Se retiran comentarios antes de buscar tablas: «Main content from
    // view» en un comentario HTML se leía como una consulta a la tabla
    // `view`. La auditoría debe mirar código, no prosa.
    $limpio = sin_comentarios($codigo);

    if (preg_match_all('/(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-z_]{3,})`?/i', $limpio, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $ref) {
            $tabla = strtolower($ref[0]);
            if (in_array($tabla, ['select', 'dual', 'set', 'where', 'values', 'table',
                                  'information_schema', 'columns'], true)) { continue; }
            if (!isset($esquema[$tabla])) {
                // El desplazamiento proviene de $limpio, así que la línea se
                // cuenta sobre $limpio. `sin_comentarios()` conserva los saltos
                // de línea, de modo que la numeración coincide con el original.
                $linea = substr_count(substr($limpio, 0, $ref[1]), "\n") + 1;
                anotar('CRITICO', 'C', $corto, $linea,
                    "Consulta la tabla `{$tabla}`, que no existe en el esquema");
            }
        }
    }
}

// C2 · Columnas usadas en db_insert_simple/db_update_simple que no existen
foreach ($productivos as $archivo) {
    $codigo = $fuente[$archivo];
    $corto  = ruta_corta($archivo);

    if (preg_match_all("/db_(?:insert|update)_simple\s*\(\s*'([a-z_]+)'\s*,\s*\[(.*?)\]\s*[,)]/s",
                       $codigo, $m, PREG_SET_ORDER)) {
        foreach ($m as $uso) {
            $tabla = $uso[1];
            if (!isset($esquema[$tabla])) { continue; }

            if (preg_match_all("/'([a-z_]+)'\s*=>/", $uso[2], $claves)) {
                foreach ($claves[1] as $columna) {
                    if (!in_array($columna, $esquema[$tabla], true)) {
                        anotar('CRITICO', 'C', $corto, 0,
                            "Escribe `{$tabla}`.`{$columna}`, columna que no existe");
                    }
                }
            }
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// D · CALIDAD
// ═════════════════════════════════════════════════════════════════════════════

foreach ($productivos as $archivo) {
    $codigo = $fuente[$archivo];
    $corto  = ruta_corta($archivo);

    // Todas las comprobaciones de esta sección buscan CÓDIGO. Un comentario que
    // describe un problema no es el problema.
    $limpio = sin_comentarios($codigo);

    // D1 se comprueba aparte, sobre TODOS los archivos — ver más abajo

    // D2 · Funcionalidad simulada
    if (preg_match_all('/alert\([\'"]([^\'"]*[Pp]róximamente[^\'"]*)[\'"]\)/', $limpio, $m)) {
        foreach ($m[1] as $texto) {
            anotar('ALTO', 'D', $corto, 0, "Botón simulado: alert('$texto')");
        }
    }

    // D3 · Marcadores pendientes
    //
    // La comparación es sensible a mayúsculas y exige palabra completa: sin eso,
    // «todo el equipo» y «todo el sistema» —español corriente— se contaban como
    // deuda técnica. Y `XXX` queda fuera de la lista porque en este proyecto
    // aparece enmascarando teléfonos y documentos, no marcando pendientes.
    if (preg_match_all('/\b(TODO|FIXME|HACK|PENDIENTE)\b[:\s]([^\n]{0,60})/', $codigo, $m, PREG_SET_ORDER)) {
        foreach ($m as $pendiente) {
            anotar('MEDIO', 'D', $corto, 0, trim($pendiente[0]));
        }
    }

    // D4 · Variables de sesión leídas sin comprobar existencia
    if (preg_match_all('/\$_SESSION\[[\'"]([a-z_]+)[\'"]\]/', $limpio, $m)) {
        foreach (array_unique($m[1]) as $clave) {
            $patron = '/(isset|empty|\?\?)[^\n]*\$_SESSION\[[\'"]' . $clave . '/';
            if (!preg_match($patron, $limpio)
                && !preg_match('/\$_SESSION\[[\'"]' . $clave . '[\'"]\]\s*=/', $limpio)) {
                anotar('MEDIO', 'D', $corto, 0,
                    "Lee \$_SESSION['{$clave}'] sin comprobar si existe");
            }
        }
    }
}

/*
 * D1 · Interpolación rota por carácter multibyte
 *
 * PHP admite bytes 0x80–0xFF en los nombres de variable, así que en «$clave» el
 * cierre » se traga dentro del nombre: la variable no existe, el mensaje sale
 * incompleto y solo queda un aviso de «variable no definida».
 *
 * Se revisan TODOS los archivos, también `pruebas/` y `herramientas/`. Cuando
 * esta comprobación excluía el código de pruebas, el defecto reapareció ahí
 * cuatro veces: un mensaje roto en una prueba es una prueba que miente sobre lo
 * que comprobó, y en un caso pasó en verde sin comparar nada.
 */
foreach ($fuente as $archivo => $codigo) {
    $limpio = sin_comentarios($codigo);

    /*
     * La firma exacta es un » pegado al final del nombre de la variable, dentro
     * de una cadena con comillas dobles y en la misma línea.
     *
     * La versión anterior permitía cualquier distancia entre las comillas y los
     * signos, y la coincidencia cruzaba líneas: dentro de un heredoc acabó
     * enlazando un « de un comentario con un $v de treinta líneas más abajo y
     * lo denunciaba como defecto. Un detector que señala código correcto
     * termina enseñando a ignorarlo.
     */
    if (preg_match_all('/"[^"\n]*\$[a-z_][a-z0-9_]*»/iu', $limpio, $m)) {
        foreach ($m[0] as $trozo) {
            anotar('ALTO', 'D', ruta_corta($archivo), 0,
                'Interpolación junto a « » sin llaves, sale vacía: '
                . trim(preg_replace('/\s+/', ' ', substr($trozo, 0, 55))));
        }
    }
}

// D5 · Funciones definidas y nunca invocadas
$todo_el_codigo = implode("\n", $fuente);
foreach ($definidas as $nombre => $lugares) {
    if (count($lugares) > 1) { continue; }
    if (strpos($lugares[0], 'pruebas/') === 0 || strpos($lugares[0], 'herramientas/') === 0) { continue; }

    $usos = preg_match_all('/\b' . preg_quote($nombre, '/') . '\s*\(/i', $todo_el_codigo);
    if ($usos <= 1) {
        anotar('BAJO', 'D', $lugares[0], 0, "La función {$nombre}() nunca se invoca");
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// INFORME
// ═════════════════════════════════════════════════════════════════════════════
$orden = ['CRITICO' => 0, 'ALTO' => 1, 'MEDIO' => 2, 'BAJO' => 3];
usort($hallazgos, fn($a, $b) => [$orden[$a['gravedad']], $a['seccion'], $a['archivo']]
                            <=> [$orden[$b['gravedad']], $b['seccion'], $b['archivo']]);

$secciones = ['A' => 'INTEGRIDAD ESTRUCTURAL', 'B' => 'SEGURIDAD',
              'C' => 'COHERENCIA CON EL ESQUEMA', 'D' => 'CALIDAD'];

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo " AUDITORÍA FORENSE · Botón Violeta\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";
printf("Archivos PHP analizados : %d\n", count($php));
printf("Funciones definidas     : %d\n", count($definidas));
printf("Tablas en el esquema    : %d\n", count($esquema));
printf("Rutas del enrutador     : %d\n\n", count($rutas));

$conteo = ['CRITICO' => 0, 'ALTO' => 0, 'MEDIO' => 0, 'BAJO' => 0];
foreach ($hallazgos as $h) { $conteo[$h['gravedad']]++; }

printf("CRÍTICOS %d · ALTOS %d · MEDIOS %d · BAJOS %d\n\n",
    $conteo['CRITICO'], $conteo['ALTO'], $conteo['MEDIO'], $conteo['BAJO']);

$seccion_actual = null;
foreach ($hallazgos as $h) {
    if ($seccion !== null && $h['seccion'] !== $seccion) { continue; }

    if ($h['seccion'] !== $seccion_actual) {
        $seccion_actual = $h['seccion'];
        echo "\n─── " . $seccion_actual . ' · ' . $secciones[$seccion_actual] . " " . str_repeat('─', 30) . "\n\n";
    }

    printf("  [%-7s] %s%s\n", $h['gravedad'], $h['descripcion'],
        $detalle ? "\n              " . $h['archivo'] . ($h['linea'] ? ':' . $h['linea'] : '') : '');

    if (!$detalle) {
        printf("              %s%s\n", $h['archivo'], $h['linea'] ? ':' . $h['linea'] : '');
    }
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo ' Total: ' . count($hallazgos) . " hallazgos\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

exit($conteo['CRITICO'] > 0 ? 1 : 0);
