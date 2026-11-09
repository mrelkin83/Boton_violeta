<?php
/**
 * GENERADOR DE CSS LOCAL
 *
 * Cierra el hallazgo H-21 · CLAUDE.md §5 («TailwindCSS compilado a CSS
 * estático local. Prohibido CDN en producción») y prohibición 8.
 *
 * ¿Por qué un generador propio y no `npx tailwindcss`?
 *
 *   CLAUDE.md §5 prohíbe NPM. Compilar Tailwind exige Node, y añadirlo
 *   introduce una cadena de herramientas —y de dependencias transitivas— que
 *   el proyecto lleva siete fases evitando. Este script recorre las vistas,
 *   extrae las clases realmente usadas y emite solo esas reglas.
 *
 *   El resultado es un CSS de unos pocos kilobytes en vez de los ~3 MB del
 *   CDN, sin build tools, sin red y sin dependencias.
 *
 * Limitación honesta: cubre el subconjunto de utilidades que este proyecto
 * usa, no Tailwind entero. Si alguien añade una clase nueva a una vista, debe
 * volver a ejecutar este script; si la utilidad no está contemplada, hay que
 * añadir su regla aquí. La suite de pruebas comprueba que no queden clases
 * usadas sin generar.
 *
 * Uso:
 *   php herramientas/generar_css.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

$raiz = dirname(__DIR__);

// ─────────────────────────────────────────────────────────────────────────────
// Paleta · los valores son los de Tailwind, verificados en contraste (WCAG AA)
// ─────────────────────────────────────────────────────────────────────────────
$colores = [
    'white'   => '#ffffff',
    'black'   => '#000000',
    'transparent' => 'transparent',
    'gray-50' => '#f9fafb', 'gray-100' => '#f3f4f6', 'gray-200' => '#e5e7eb',
    'gray-300' => '#d1d5db', 'gray-400' => '#9ca3af', 'gray-500' => '#6b7280',
    'gray-600' => '#4b5563', 'gray-700' => '#374151', 'gray-800' => '#1f2937',
    'gray-900' => '#111827',
    'purple-50' => '#faf5ff', 'purple-100' => '#f3e8ff', 'purple-200' => '#e9d5ff',
    'purple-300' => '#d8b4fe', 'purple-400' => '#c084fc', 'purple-500' => '#a855f7',
    'purple-600' => '#9333ea', 'purple-700' => '#7e22ce', 'purple-800' => '#6b21a8',
    'purple-900' => '#581c87',
    'red-50' => '#fef2f2', 'red-100' => '#fee2e2', 'red-200' => '#fecaca',
    'red-300' => '#fca5a5',
    'red-400' => '#f87171', 'red-500' => '#ef4444', 'red-600' => '#dc2626',
    'red-700' => '#b91c1c', 'red-800' => '#991b1b', 'red-900' => '#7f1d1d',
    // El tono 700 faltaba en verde y en ningún otro color. Se usa para texto
    // sobre fondo claro —donde el 600 no llega a 4.5:1 de contraste— y sin él
    // el respaldo local dejaba ese texto sin color declarado.
    'green-50' => '#f0fdf4', 'green-100' => '#dcfce7', 'green-200' => '#bbf7d0',
    'green-300' => '#86efac',
    'green-400' => '#4ade80', 'green-500' => '#22c55e', 'green-600' => '#16a34a',
    'green-700' => '#15803d', 'green-800' => '#166534', 'green-900' => '#14532d',
    'yellow-50' => '#fefce8', 'yellow-100' => '#fef9c3', 'yellow-200' => '#fef08a',
    'yellow-300' => '#fde047',
    'yellow-400' => '#facc15', 'yellow-500' => '#eab308', 'yellow-600' => '#ca8a04',
    'yellow-700' => '#a16207', 'yellow-800' => '#854d0e', 'yellow-900' => '#713f12',
    'blue-50' => '#eff6ff', 'blue-100' => '#dbeafe', 'blue-200' => '#bfdbfe',
    'blue-400' => '#60a5fa', 'blue-500' => '#3b82f6', 'blue-600' => '#2563eb',
    'blue-700' => '#1d4ed8', 'blue-800' => '#1e40af', 'blue-900' => '#1e3a8a',
];

$espaciado = [
    '0' => '0', '0.5' => '0.125rem', '1' => '0.25rem', '1.5' => '0.375rem',
    '2' => '0.5rem', '2.5' => '0.625rem', '3' => '0.75rem', '4' => '1rem',
    '5' => '1.25rem', '6' => '1.5rem', '8' => '2rem', '10' => '2.5rem',
    '12' => '3rem', '16' => '4rem', '20' => '5rem', '24' => '6rem',
    '28' => '7rem', '32' => '8rem', '40' => '10rem', '64' => '16rem', 'px' => '1px',
];

$tamanos_texto = [
    'xs' => ['0.75rem', '1rem'], 'sm' => ['0.875rem', '1.25rem'],
    'base' => ['1rem', '1.5rem'], 'lg' => ['1.125rem', '1.75rem'],
    'xl' => ['1.25rem', '1.75rem'], '2xl' => ['1.5rem', '2rem'],
    '3xl' => ['1.875rem', '2.25rem'], '4xl' => ['2.25rem', '2.5rem'],
    '5xl' => ['3rem', '1'], '6xl' => ['3.75rem', '1'],
    '7xl' => ['4.5rem', '1'], '8xl' => ['6rem', '1'],
];

/**
 * Clases que en Tailwind NO producen estilo propio
 *
 * `transform` habilita las utilidades de transformación pero no transforma
 * nada. Emitirla como `transform:translate(0,0)` la convierte en una regla que
 * ANULA a `-translate-x-full` cuando se emite después por orden alfabético.
 * Ese fue el fallo que dejaba la barra lateral tapando el contenido en móvil.
 */
const CLASES_SIN_EFECTO = ['transform', 'transform-gpu', 'filter', 'group', 'peer'];

/**
 * Utilidades que deben emitirse AL FINAL
 *
 * `duration-*` y `ease-*` modifican propiedades que la forma abreviada de
 * `transition` reescribe. Si se emiten antes, no tienen efecto.
 */
const UTILIDADES_TARDIAS = '/^(duration-|ease-|delay-)/';

// ─────────────────────────────────────────────────────────────────────────────
// 1. Recolectar las clases realmente usadas
// ─────────────────────────────────────────────────────────────────────────────
$archivos = array_merge(
    glob($raiz . '/views/*.php'), glob($raiz . '/views/*/*.php'),
    glob($raiz . '/modules/*/*.php'), [$raiz . '/includes/functions.php']
);

$usadas = [];

foreach ($archivos as $archivo) {
    $contenido = file_get_contents($archivo);

    /*
     * El PHP se retira antes de leer los atributos, por el mismo motivo que en
     * `auditar_frontend.php`: una comilla simple dentro de una expresión
     * incrustada cierra el atributo para la expresión regular y deja un trozo
     * como `e($estado[` haciéndose pasar por clase CSS. Aparecía en la lista de
     * «clases que no supe generar», donde no había nada que generar.
     */
    $contenido_html = preg_replace('/<\?(?:php|=).*?\?>/s', ' ', $contenido);

    /*
     * Clases que la propia vista ya define en un <style> embebido.
     *
     * `modules/denuncias/imprimir.php` lleva su CSS dentro a propósito: un
     * expediente tiene que poder imprimirse sin red y sin depender de este
     * archivo. Sus clases —cabecera, radicado, reserva, marca…— no son
     * utilidades que este generador deba producir, y aparecían en la lista de
     * «no supe generar» sugiriendo un trabajo pendiente que no existe.
     *
     * Se descartan una a una y no saltando el archivo entero: una vista puede
     * combinar utilidades con un puñado de estilos propios, y saltarla dejaría
     * sus utilidades sin generar. Se probó y la cobertura cayó del 100 % al
     * 97,9 %.
     */
    $propias = [];

    if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $contenido, $estilos)) {
        foreach ($estilos[1] as $bloque) {
            if (preg_match_all('/\.([a-zA-Z0-9_-]+)(?=[\s,{:>])/', $bloque, $sel)) {
                foreach ($sel[1] as $s) { $propias[$s] = true; }
            }
        }
    }

    if (preg_match_all('/class=["\']([^"\']+)["\']/', $contenido_html, $m)) {
        foreach ($m[1] as $lista) {
            foreach (preg_split('/\s+/', $lista) as $clase) {
                $clase = trim($clase);
                if ($clase !== '' && strpos($clase, '<?') === false && !isset($propias[$clase])) {
                    $usadas[$clase] = true;
                }
            }
        }
    }
    // Clases que se construyen dentro de expresiones PHP
    if (preg_match_all("/'((?:bg|text|border)-[a-z]+-\d{2,3}[^']*)'/", $contenido, $m)) {
        foreach ($m[1] as $lista) {
            foreach (preg_split('/\s+/', $lista) as $clase) {
                if (trim($clase) !== '') { $usadas[trim($clase)] = true; }
            }
        }
    }
}

$usadas = array_keys($usadas);
sort($usadas);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Traducir cada clase a su regla CSS
// ─────────────────────────────────────────────────────────────────────────────
/** Escapa el selector: `md:flex` → `.md\:flex` */
function sel($clase) {
    return '.' . preg_replace('/([:.\/\[\]])/', '\\\\$1', $clase);
}

function regla($clase, $colores, $espaciado, $tamanos_texto) {
    $c = $clase;

    // Habilitadores sin estilo propio: se reconocen para no marcarlos como
    // faltantes, pero no emiten ninguna declaración
    if (in_array($c, CLASES_SIN_EFECTO, true)) {
        return '/*sin-efecto*/';
    }

    // Prefijos de estado y de tamaño se resuelven fuera de esta función
    $estaticas = [
        'block' => 'display:block', 'inline-block' => 'display:inline-block',
        'inline' => 'display:inline', 'flex' => 'display:flex',
        'inline-flex' => 'display:inline-flex', 'grid' => 'display:grid',
        'hidden' => 'display:none', 'table' => 'display:table',
        'flex-1' => 'flex:1 1 0%', 'flex-col' => 'flex-direction:column',
        'flex-row' => 'flex-direction:row', 'flex-wrap' => 'flex-wrap:wrap',
        'flex-shrink-0' => 'flex-shrink:0', 'items-center' => 'align-items:center',
        'items-start' => 'align-items:flex-start', 'items-end' => 'align-items:flex-end',
        'justify-center' => 'justify-content:center', 'justify-between' => 'justify-content:space-between',
        'justify-end' => 'justify-content:flex-end',
        'text-left' => 'text-align:left', 'text-center' => 'text-align:center',
        'text-right' => 'text-align:right',
        'font-normal' => 'font-weight:400', 'font-medium' => 'font-weight:500',
        'font-semibold' => 'font-weight:600', 'font-bold' => 'font-weight:700',
        'font-mono' => 'font-family:ui-monospace,SFMono-Regular,Menlo,monospace',
        'uppercase' => 'text-transform:uppercase', 'underline' => 'text-decoration-line:underline',
        'whitespace-pre-line' => 'white-space:pre-line',
        'relative' => 'position:relative', 'absolute' => 'position:absolute',
        'fixed' => 'position:fixed', 'sticky' => 'position:sticky',
        'inset-0' => 'top:0;right:0;bottom:0;left:0',
        'inset-y-0' => 'top:0;bottom:0', 'left-0' => 'left:0', 'right-4' => 'right:1rem',
        'bottom-4' => 'bottom:1rem', 'top-0' => 'top:0',
        'overflow-hidden' => 'overflow:hidden', 'overflow-x-auto' => 'overflow-x:auto',
        // v3.1 · pantallas del expediente y la consola
        'overflow-y-auto' => 'overflow-y:auto',
        'whitespace-nowrap' => 'white-space:nowrap',
        'whitespace-pre-wrap' => 'white-space:pre-wrap',
        'h-fit' => 'height:fit-content',
        'max-h-96' => 'max-height:24rem',
        'border-b-2' => 'border-bottom-width:2px;border-bottom-style:solid',
        'w-full' => 'width:100%', 'h-full' => 'height:100%',
        'min-h-screen' => 'min-height:100vh', 'max-w-none' => 'max-width:none',
        'mx-auto' => 'margin-left:auto;margin-right:auto',
        'cursor-pointer' => 'cursor:pointer', 'transition' => 'transition-property:color,background-color,border-color,opacity,box-shadow,transform;transition-duration:150ms;transition-timing-function:cubic-bezier(.4,0,.2,1)',
        'rounded' => 'border-radius:.25rem', 'rounded-lg' => 'border-radius:.5rem',
        'rounded-xl' => 'border-radius:.75rem', 'rounded-2xl' => 'border-radius:1rem',
        'rounded-full' => 'border-radius:9999px',
        'border' => 'border-width:1px;border-style:solid',
        'border-2' => 'border-width:2px;border-style:solid',
        'border-t' => 'border-top-width:1px;border-top-style:solid',
        'border-b' => 'border-bottom-width:1px;border-bottom-style:solid',
        'border-l-4' => 'border-left-width:4px;border-left-style:solid',
        'shadow' => 'box-shadow:0 1px 3px rgba(0,0,0,.1),0 1px 2px rgba(0,0,0,.06)',
        'shadow-sm' => 'box-shadow:0 1px 2px rgba(0,0,0,.05)',
        'shadow-lg' => 'box-shadow:0 10px 15px rgba(0,0,0,.1),0 4px 6px rgba(0,0,0,.05)',
        'shadow-xl' => 'box-shadow:0 20px 25px rgba(0,0,0,.1),0 10px 10px rgba(0,0,0,.04)',
        'shadow-2xl' => 'box-shadow:0 25px 50px rgba(0,0,0,.25)',
        'list-disc' => 'list-style-type:disc', 'list-inside' => 'list-style-position:inside',
        'divide-y' => '', 'space-y-1' => '', 'space-y-2' => '', 'space-y-3' => '',
        'space-y-4' => '', 'space-y-5' => '', 'space-y-6' => '', 'space-y-8' => '',
        'tracking-wide' => 'letter-spacing:.025em', 'tracking-wider' => 'letter-spacing:.05em',

        // Las tres que evitan el recorte en pantalla estrecha.
        //
        // `min-w-0` permite que un hijo de grid o flex baje de su contenido
        // mínimo; sin él, y con un ancestro que recorta, el contenido se pierde
        // sin dejar barra de desplazamiento. Las otras dos parten palabras
        // largas —un correo institucional de 32 caracteres, por ejemplo—.
        'min-w-0'     => 'min-width:0',
        'break-words' => 'overflow-wrap:break-word',
        'break-all'   => 'word-break:break-all',
        'prose' => 'max-width:65ch;line-height:1.75',
        'z-30' => 'z-index:30', 'z-40' => 'z-index:40', 'z-50' => 'z-index:50',
        'opacity-90' => 'opacity:.9',
        'backdrop-blur-sm' => 'backdrop-filter:blur(4px)',
        'container' => 'width:100%;margin-left:auto;margin-right:auto;max-width:1280px',
                'outline-none' => 'outline:2px solid transparent;outline-offset:2px',
        'ease-in-out' => 'transition-timing-function:cubic-bezier(.4,0,.2,1)',
        'transition-transform' => 'transition-property:transform;transition-duration:150ms;transition-timing-function:cubic-bezier(.4,0,.2,1)',
        'transition-colors' => 'transition-property:color,background-color,border-color;transition-duration:150ms',
        'w-auto' => 'width:auto', 'h-auto' => 'height:auto',
        'ml-auto' => 'margin-left:auto', 'mr-auto' => 'margin-right:auto',
        'tracking-[0.5em]' => 'letter-spacing:.5em',
        'shadow-none' => 'box-shadow:none',
        'divide-gray-200' => '/*color de divisor por defecto*/',
        'shadow-purple-500/50' => 'box-shadow:0 20px 25px rgba(168,85,247,.5)',
        'saltar-contenido' => '/*propia*/',
        'ease-out' => 'transition-timing-function:cubic-bezier(0,0,.2,1)',
        'ease-in' => 'transition-timing-function:cubic-bezier(.4,0,1,1)',
        'scale-105' => 'transform:scale(1.05)',
        'translate-x-0' => 'transform:translateX(0)',
        '-translate-x-full' => 'transform:translateX(-100%)',
        'animate-pulse' => 'animation:bv-pulse 2s cubic-bezier(.4,0,.6,1) infinite',
        'bg-opacity-50' => '--tw-bg-opacity:.5',
        'bg-white/20' => 'background-color:rgba(255,255,255,.2)',
        'bg-white/80' => 'background-color:rgba(255,255,255,.8)',
        'sr-only' => 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;'
                   . 'overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0',
    ];

    // duration-N
    if (preg_match('/^duration-(\d+)$/', $c, $m)) {
        return 'transition-duration:' . $m[1] . 'ms';
    }

    // Anillo de foco · WCAG 2.4.7: el foco debe ser perceptible
    if (preg_match('/^ring-(\d)$/', $c, $m)) {
        return 'box-shadow:0 0 0 ' . $m[1] . 'px currentColor';
    }
    if (preg_match('/^ring-(.+)$/', $c, $m) && isset($colores[$m[1]])) {
        return 'box-shadow:0 0 0 3px ' . $colores[$m[1]];
    }

    if (isset($estaticas[$c])) {
        return $estaticas[$c] === '' ? null : $estaticas[$c];
    }

    // bg-color / text-color / border-color
    if (preg_match('/^(bg|text|border)-(.+)$/', $c, $m)) {
        [$todo, $prop, $valor] = $m;

        if (isset($colores[$valor])) {
            $mapa = ['bg' => 'background-color', 'text' => 'color', 'border' => 'border-color'];
            return $mapa[$prop] . ':' . $colores[$valor];
        }

        if ($prop === 'text' && isset($tamanos_texto[$valor])) {
            return 'font-size:' . $tamanos_texto[$valor][0] . ';line-height:' . $tamanos_texto[$valor][1];
        }
    }

    // Espaciado: p, px, py, pt, pb, m, mx, my, mt, mb, gap
    if (preg_match('/^(p|px|py|pt|pb|pl|pr|m|mx|my|mt|mb|ml|mr|gap)-(.+)$/', $c, $m)) {
        [$todo, $prop, $valor] = $m;

        if (!isset($espaciado[$valor])) { return null; }
        $v = $espaciado[$valor];

        $mapa = [
            'p' => "padding:$v", 'px' => "padding-left:$v;padding-right:$v",
            'py' => "padding-top:$v;padding-bottom:$v", 'pt' => "padding-top:$v",
            'pb' => "padding-bottom:$v", 'pl' => "padding-left:$v", 'pr' => "padding-right:$v",
            'm' => "margin:$v", 'mx' => "margin-left:$v;margin-right:$v",
            'my' => "margin-top:$v;margin-bottom:$v", 'mt' => "margin-top:$v",
            'mb' => "margin-bottom:$v", 'ml' => "margin-left:$v", 'mr' => "margin-right:$v",
            'gap' => "gap:$v",
        ];
        return $mapa[$prop] ?? null;
    }

    // Tamaños w-N / h-N
    if (preg_match('/^([wh])-(.+)$/', $c, $m)) {
        [$todo, $prop, $valor] = $m;
        if (!isset($espaciado[$valor])) { return null; }
        return ($prop === 'w' ? 'width:' : 'height:') . $espaciado[$valor];
    }

    // max-w-*
    $anchos = ['md' => '28rem', 'lg' => '32rem', 'xl' => '36rem', '2xl' => '42rem',
               '3xl' => '48rem', '4xl' => '56rem', '5xl' => '64rem', '6xl' => '72rem',
               '7xl' => '80rem'];
    if (preg_match('/^max-w-(.+)$/', $c, $m) && isset($anchos[$m[1]])) {
        return 'max-width:' . $anchos[$m[1]];
    }

    // Cuadrículas
    if (preg_match('/^grid-cols-(\d+)$/', $c, $m)) {
        return 'display:grid;grid-template-columns:repeat(' . $m[1] . ',minmax(0,1fr))';
    }
    if (preg_match('/^col-span-(\d+)$/', $c, $m)) {
        return 'grid-column:span ' . $m[1] . '/span ' . $m[1];
    }

    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. Emitir
// ─────────────────────────────────────────────────────────────────────────────
$css     = [];
$tardias = [];
$hover   = [];
$focus = [];
$media = ['sm' => [], 'md' => [], 'lg' => []];
$sin_generar = [];

foreach ($usadas as $clase) {
    $base = $clase;
    $destino = 'base';
    $breakpoint = null;

    if (preg_match('/^(sm|md|lg):(.+)$/', $base, $m)) {
        $breakpoint = $m[1];
        $base = $m[2];
    }
    if (preg_match('/^(hover|focus|print):(.+)$/', $base, $m)) {
        $destino = $m[1];
        $base = $m[2];
    }

    $declaracion = regla($base, $colores, $espaciado, $tamanos_texto);

    // Habilitador reconocido: no emite regla, pero tampoco es un hueco
    if ($declaracion === '/*sin-efecto*/') {
        continue;
    }

    if ($declaracion === null) {
        // Utilidades sin equivalente directo: se ignoran silenciosamente si
        // son de las que el proyecto no necesita en el CSS final
        // Se descartan del aviso: utilidades resueltas más abajo (gradientes,
        // espaciado entre hijos) y los fragmentos de PHP que el extractor
        // recoge inevitablemente de las clases construidas en runtime.
        $ruido = '/^(space-|divide-|print:|group|peer|x-|from-|to-|\$|===|\?|\[|bg-gradient)/';

        if (!preg_match($ruido, $base)) {
            $sin_generar[] = $clase;
        }
        continue;
    }

    $selector = sel($clase);
    $bloque = "$selector{{$declaracion}}";

    if ($breakpoint !== null) {
        $media[$breakpoint][] = $bloque;
    } elseif ($destino === 'hover') {
        $hover[] = str_replace('{', ':hover{', $bloque);
    } elseif ($destino === 'focus') {
        $focus[] = str_replace('{', ':focus{', $bloque);
    } elseif (preg_match(UTILIDADES_TARDIAS, $base)) {
        // Deben ir después de `transition`, que reescribe sus propiedades
        $tardias[] = $bloque;
    } else {
        $css[] = $bloque;
    }
}

$puntos = ['sm' => '640px', 'md' => '768px', 'lg' => '1024px'];

$salida  = "/* Botón Violeta · CSS local generado por herramientas/generar_css.php\n";
$salida .= "   NO EDITAR A MANO. Vuelva a ejecutar el generador tras cambiar las vistas.\n";
$salida .= "   Cierra el hallazgo H-21: sin CDN, sin NPM, sin dependencias. */\n\n";
$salida .= "*,::before,::after{box-sizing:border-box;border-width:0;border-style:solid;border-color:#e5e7eb}\n";
$salida .= "html{-webkit-text-size-adjust:100%;font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;line-height:1.5}\n";
$salida .= "body{margin:0;line-height:inherit}\n";
$salida .= "h1,h2,h3,h4{font-size:inherit;font-weight:inherit;margin:0}\n";
$salida .= "p,dl,dd,ul,ol,figure{margin:0}ul,ol{list-style:none;padding:0}\n";
$salida .= "a{color:inherit;text-decoration:inherit}\n";
$salida .= "button,input,select,textarea{font-family:inherit;font-size:100%;margin:0;padding:0;color:inherit}\n";
$salida .= "button{background:none;cursor:pointer}\nsvg{display:block;vertical-align:middle}\n";
$salida .= "table{border-collapse:collapse}\n";
$salida .= "[hidden]{display:none}\n\n";

$salida .= "/* Utilidades */\n" . implode("\n", $css) . "\n\n";

// Estas reescriben propiedades que la forma abreviada de `transition` fija.
// Si se emitieran antes, `duration-300` no tendría ningún efecto.
$salida .= "/* Modificadores de transición · deben ir DESPUÉS de .transition */\n"
         . implode("\n", $tardias) . "\n\n";

// Espaciado vertical entre hijos
$salida .= "/* Espaciado entre elementos */\n";
foreach (['1' => '.25rem', '2' => '.5rem', '3' => '.75rem', '4' => '1rem',
          '5' => '1.25rem', '6' => '1.5rem', '8' => '2rem'] as $n => $v) {
    $salida .= ".space-y-$n>*+*{margin-top:$v}\n";
    $salida .= ".space-x-$n>*+*{margin-left:$v}\n";
}
$salida .= ".divide-y>*+*{border-top-width:1px}\n\n";

$salida .= "/* Estados */\n" . implode("\n", $hover) . "\n" . implode("\n", $focus) . "\n\n";

foreach ($puntos as $bp => $ancho) {
    if (empty($media[$bp])) { continue; }
    $salida .= "@media (min-width:$ancho){\n" . implode("\n", $media[$bp]) . "\n}\n\n";
}

$salida .= "/* Accesibilidad y utilidades propias */\n";
$salida .= ":focus-visible{outline:3px solid #7e22ce;outline-offset:2px}\n";
$salida .= ".saltar-contenido{position:absolute;left:-9999px;top:0;z-index:100}\n";
$salida .= ".saltar-contenido:focus{left:0;padding:1rem;background:#fff;color:#6b21a8;font-weight:600}\n";
$salida .= "@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;transition-duration:.01ms!important}}\n";
$salida .= "@keyframes bv-pulse{0%,100%{opacity:1}50%{opacity:.5}}\n";
$salida .= "@media print{.print\\:hidden{display:none}}\n";
$salida .= ".bg-gradient-to-r{background-image:linear-gradient(to right,var(--tw-from),var(--tw-to))}\n";
$salida .= ".bg-gradient-to-br{background-image:linear-gradient(to bottom right,var(--tw-from),var(--tw-to))}\n";
foreach ($colores as $nombre => $hex) {
    $salida .= ".from-" . $nombre . "{--tw-from:$hex}\n.to-" . $nombre . "{--tw-to:$hex}\n";
}

if (!is_dir($raiz . '/assets/css')) {
    mkdir($raiz . '/assets/css', 0755, true);
}

file_put_contents($raiz . '/assets/css/app.css', $salida);

echo "Clases encontradas en las vistas: " . count($usadas) . "\n";
echo "Reglas generadas:                " . (count($css) + count($hover) + count($focus)
    + array_sum(array_map('count', $media))) . "\n";
echo "CSS escrito en assets/css/app.css (" . round(strlen($salida) / 1024, 1) . " KB)\n";

if (!empty($sin_generar)) {
    echo "\n⚠ Clases usadas SIN regla generada (" . count($sin_generar) . "):\n";
    foreach (array_slice(array_unique($sin_generar), 0, 30) as $c) {
        echo "   $c\n";
    }
}
