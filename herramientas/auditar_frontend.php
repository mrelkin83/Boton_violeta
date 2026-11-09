<?php
/**
 * AUDITORÍA DEL FRONTEND
 *
 * Compara las clases CSS que las vistas USAN contra las que el CSS local
 * DEFINE, y revisa la coherencia del HTML.
 *
 * Nació de un fallo propio: se dio por bueno el CSS local comprobando que el
 * archivo existía y pesaba poco, sin verificar que las páginas se vieran bien.
 * Un CSS que carga y no cubre las clases usadas deja la interfaz rota
 * silenciosamente.
 *
 * Uso:
 *   php herramientas/auditar_frontend.php
 *   php herramientas/auditar_frontend.php --detalle
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

$raiz = dirname(__DIR__);
$detalle = in_array('--detalle', $argv, true);

// ─────────────────────────────────────────────────────────────────────────────
// 1. Clases definidas en el CSS
// ─────────────────────────────────────────────────────────────────────────────
$ruta_css = $raiz . '/assets/css/app.css';

if (!is_file($ruta_css)) {
    exit("No existe assets/css/app.css. Ejecuta herramientas/generar_css.php\n");
}

$css = file_get_contents($ruta_css);

preg_match_all('/\.((?:[a-zA-Z0-9_-]|\\\\.)+)(?=[\s,{:>])/', $css, $m);

$definidas = [];
foreach ($m[1] as $selector) {
    $definidas[stripslashes($selector)] = true;
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Clases usadas en las vistas
// ─────────────────────────────────────────────────────────────────────────────
$archivos = array_merge(
    glob($raiz . '/views/*.php'),
    glob($raiz . '/views/*/*.php'),
    glob($raiz . '/modules/*/*.php')
);

$usadas = [];      // clase => [archivos]
$dinamicas = [];   // clases construidas en PHP

foreach ($archivos as $archivo) {
    $nombre = str_replace($raiz . DIRECTORY_SEPARATOR, '', $archivo);
    $contenido = file_get_contents($archivo);

    // Una vista puede traer su propia hoja de estilo embebida. `imprimir.php`
    // lo hace a propósito: un expediente debe poder imprimirse sin red. Sus
    // clases están definidas aunque no aparezcan en app.css, así que se leen
    // aquí antes de comparar; de lo contrario se reportaban como «se ven mal»
    // once elementos que se ven perfectamente.
    if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $contenido, $estilos)) {
        foreach ($estilos[1] as $bloque) {
            if (preg_match_all('/\.((?:[a-zA-Z0-9_-]|\\\\.)+)(?=[\s,{:>])/', $bloque, $sel)) {
                foreach ($sel[1] as $selector) {
                    $definidas[stripslashes($selector)] = true;
                }
            }
        }
    }

    /*
     * El recorte en pantalla estrecha NO se comprueba aquí.
     *
     * Un hijo de `grid` o `flex` tiene `min-width: auto`: no baja de su
     * contenido mínimo por estrecha que sea la pantalla. Si un ancestro lleva
     * `overflow-hidden`, el resultado es contenido cortado sin barra de
     * desplazamiento — sin ninguna señal. Así se perdían en `views/home.php` a
     * 360 px el título, el buscador de radicado y el botón de acceso.
     *
     * Se intentó detectarlo leyendo las clases de Tailwind y se descartó: la
     * regla dependía de qué clases acompañaran a cuáles, marcaba como
     * sospechoso casi todo archivo con un `flex` y habría enseñado a ignorar
     * la herramienta. Adivinar sobre el marcado no sustituye a medir.
     *
     * La medición de verdad está en `herramientas/medir_movil.ps1`, que pide
     * a un navegador real las coordenadas de cada elemento a 320, 360, 390 y
     * 412 px.
     *
     * Se retira el PHP ANTES de buscar atributos.
     *
     * Un atributo class que intercala una expresión PHP con comillas simples
     * dentro —por ejemplo imprimiendo $estado['clases']— rompía la extracción:
     * esa comilla cerraba el atributo para la expresión regular y el trozo
     * `e($estado[` acababa contado como una clase CSS inexistente.
     *
     * (Este comentario va en bloque y no con //, porque escribir la etiqueta
     * de cierre de PHP dentro de un comentario de línea la cierra de verdad:
     * el analizador vuelve a modo HTML y el archivo deja de compilar.)
     */
    $sin_php = preg_replace('/<\?(?:php|=).*?\?>/s', ' ', $contenido);

    // class="..." literal
    if (preg_match_all('/class=["\']([^"\']*)["\']/', $sin_php, $mm)) {
        foreach ($mm[1] as $limpio) {
            foreach (preg_split('/\s+/', $limpio) as $clase) {
                $clase = trim($clase);
                if ($clase === '' || strpos($clase, '<?') !== false) { continue; }

                // Restos de expresiones PHP que sobreviven al filtrado:
                // `$flash[`, `===`, `??`, `?`… No son clases CSS.
                if (!preg_match('/^-?[a-z]/', $clase)) { continue; }

                $usadas[$clase][] = $nombre;
            }
        }
    }

    // Clases dentro de expresiones PHP: 'bg-red-100 text-red-800'
    if (preg_match_all("/'([a-z0-9:\/\-]+(?:\s+[a-z0-9:\/\-]+)*)'/", $contenido, $mm)) {
        foreach ($mm[1] as $lista) {
            foreach (preg_split('/\s+/', $lista) as $clase) {
                if (preg_match('/^(bg|text|border|hover:bg|hover:text)-[a-z]+-\d{2,3}$/', $clase)) {
                    $usadas[$clase][] = $nombre;
                    $dinamicas[$clase] = true;
                }
            }
        }
    }
}

ksort($usadas);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Comparar
// ─────────────────────────────────────────────────────────────────────────────
$faltantes = [];

// Clases que NO deben producir regla propia: en Tailwind son habilitadores.
// `transform` es el caso que causó el fallo de la barra lateral: emitirla como
// regla la convertía en algo que anulaba a `-translate-x-full`.
$habilitadores = ['transform', 'transform-gpu', 'filter', 'group', 'peer'];

foreach ($usadas as $clase => $archivos_uso) {
    if (isset($definidas[$clase])) { continue; }
    if (in_array($clase, $habilitadores, true)) { continue; }

    // Color de divisor: el reset ya aplica el gris por defecto
    if (preg_match('/^divide-(gray|purple)-\d{2,3}$/', $clase)) { continue; }

    $faltantes[$clase] = array_values(array_unique($archivos_uso));
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. Revisiones de coherencia del HTML
// ─────────────────────────────────────────────────────────────────────────────
$avisos = [];

foreach ($archivos as $archivo) {
    $nombre = str_replace($raiz . DIRECTORY_SEPARATOR, '', $archivo);
    $fuente = file_get_contents($archivo);

    // Las comprobaciones de texto miran SOLO el HTML: los bloques PHP contienen
    // comentarios y claves de columna que no llegan nunca a la pantalla. Sin
    // esta separación, un comentario que dice «nunca escribir agresor» se
    // reporta como si fuera terminología prohibida en la interfaz.
    $contenido = preg_replace('/<\?php.*?(\?>|$)/s', ' ', $fuente);
    $contenido = preg_replace('/<\?=.*?\?>/s', ' ', $contenido);

    // Datos de contacto de relleno
    if (preg_match('/123-456-7890|\+57 300 123 4567|botonvioleta@universidad\.edu\.co/', $contenido)) {
        $avisos[] = [$nombre, 'datos de contacto de relleno (123-456-7890 / correo genérico)'];
    }

    // Nombre institucional incorrecto
    if (strpos($contenido, 'Universidad Pública de Bogotá') !== false) {
        $avisos[] = [$nombre, 'nombre institucional incorrecto («Universidad Pública de Bogotá»)'];
    }

    // Enlaces muertos
    if (preg_match('/<a\s[^>]*href="#"/', $contenido)) {
        $avisos[] = [$nombre, 'enlace muerto href="#"'];
    }

    // Terminología prohibida en TEXTO VISIBLE (§2).
    // Se excluyen las claves de PHP como 'tipo_agresor' => …, que son nombres
    // de columna heredados y no llegan a la pantalla: el `=>` hacía que la
    // comprobación anterior las tomara por texto HTML.
    if (preg_match('/>[^<$\'"=]*\b(Agresor|agresor|Victimario|victimario)\b/', $contenido)) {
        $avisos[] = [$nombre, 'terminología prohibida en la interfaz («agresor»)'];
    }

    // onclick con alert: funcionalidad simulada
    if (preg_match('/onclick="[^"]*alert\(/', $contenido)) {
        $avisos[] = [$nombre, 'botón simulado con alert()'];
    }

    // Botones sin type: envían el formulario por accidente
    if (preg_match_all('/<button(?![^>]*\btype=)[^>]*>/', $contenido, $mm)) {
        $avisos[] = [$nombre, count($mm[0]) . ' <button> sin atributo type'];
    }

    // Imágenes sin alt
    if (preg_match('/<img(?![^>]*\balt=)[^>]*>/', $contenido)) {
        $avisos[] = [$nombre, '<img> sin atributo alt'];
    }

    // Interpolación rota por un carácter multibyte.
    //
    // PHP admite los bytes 0x80-0xFF en los nombres de variable, así que en
    // "«$correo»" el parser se traga los dos bytes de » y busca una variable
    // llamada `correo»`, que no existe. El mensaje sale vacío y nadie se
    // entera. Ha ocurrido tres veces en este proyecto: en el mensaje de RN-09,
    // en una prueba y en el creador de cuentas. Se escribe "«{$correo}»".
    if (preg_match('/["\'][^"\']*[«»·—–][^"\']*\$[a-zA-Z_][a-zA-Z0-9_]*[«»·—–]/', $fuente)) {
        $avisos[] = [$nombre, 'interpolación junto a carácter multibyte sin llaves: use {$variable}'];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Informe
// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo " AUDITORÍA DEL FRONTEND\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

printf("Archivos de vista analizados : %d\n", count($archivos));
printf("Clases distintas usadas      : %d\n", count($usadas));
printf("Selectores definidos en CSS  : %d\n", count($definidas));
printf("Clases usadas SIN regla      : %d", count($faltantes));

$cobertura = count($usadas) > 0
    ? round((count($usadas) - count($faltantes)) / count($usadas) * 100, 1)
    : 0;

printf("   → cobertura %.1f%%\n\n", $cobertura);

if (!empty($faltantes)) {
    echo "─── CLASES SIN REGLA CSS ───────────────────────────────────────\n";
    echo "Cada una es un elemento que se ve mal.\n\n";

    // Agrupar por familia para que el listado sea accionable
    $familias = [];
    foreach ($faltantes as $clase => $donde) {
        $familia = preg_replace('/-?\d+(\.\d+)?$/', '-N', $clase);
        $familia = preg_split('/[:\/]/', $familia)[0];
        $familias[$familia][] = $clase;
    }
    ksort($familias);

    foreach ($familias as $familia => $clases) {
        printf("  %-22s %d: %s\n", $familia, count($clases),
            implode(', ', array_slice($clases, 0, 6))
            . (count($clases) > 6 ? ', …' : ''));
    }

    if ($detalle) {
        echo "\n  Detalle por archivo:\n";
        foreach ($faltantes as $clase => $donde) {
            printf("    %-32s %s\n", $clase, implode(', ', array_slice($donde, 0, 3)));
        }
    }
    echo "\n";
}

if (!empty($avisos)) {
    echo "─── COHERENCIA DEL HTML ────────────────────────────────────────\n\n";
    $por_archivo = [];
    foreach ($avisos as [$archivo, $aviso]) {
        $por_archivo[$archivo][] = $aviso;
    }
    ksort($por_archivo);
    foreach ($por_archivo as $archivo => $lista) {
        echo "  $archivo\n";
        foreach (array_unique($lista) as $a) {
            echo "      · $a\n";
        }
    }
    echo "\n";
}

echo "═══════════════════════════════════════════════════════════════\n";

if (empty($faltantes) && empty($avisos)) {
    echo " Sin hallazgos.\n";
    exit(0);
}

printf(" %d clases sin regla · %d avisos de coherencia\n",
    count($faltantes), count(array_unique(array_map(fn($a) => $a[0] . $a[1], $avisos))));
echo "═══════════════════════════════════════════════════════════════\n\n";

exit(1);
