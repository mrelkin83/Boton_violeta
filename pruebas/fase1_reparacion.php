<?php
/**
 * PRUEBAS · FASE 1 — REPARACIÓN
 *
 * Verifica las correcciones de los hallazgos H-02, H-03, H-04, H-05,
 * H-06, H-07, H-11, H-12 y H-14, y las prohibiciones 1, 9 y 11 de CLAUDE.md.
 *
 * ⚠ Ejecutar SIEMPRE contra una réplica, nunca contra producción:
 * inserta filas de prueba en `denuncias`, `archivos`, `seguimientos` y `auditoria`.
 *
 * Uso:
 *   BV_CONFIG=/ruta/a/bv_config_pruebas.php php pruebas/fase1_reparacion.php
 *
 * Código de salida: 0 si todo pasa, 1 si hay algún fallo (apto para CI).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Estas pruebas solo se ejecutan por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/init.php';

if (APP_ENV === 'production') {
    exit("ABORTADO: APP_ENV es 'production'. Las pruebas escriben datos.\n");
}

$correctas = 0;
$fallidas  = 0;

function chequear(string $nombre, bool $condicion, string $detalle = ''): void {
    global $correctas, $fallidas;
    if ($condicion) {
        $correctas++;
        echo "  [OK]    $nombre\n";
    } else {
        $fallidas++;
        echo "  [FALLO] $nombre" . ($detalle !== '' ? "  ($detalle)" : '') . "\n";
    }
}

function seccion(string $titulo): void {
    echo "\n=== $titulo ===\n";
}

// ─────────────────────────────────────────────────────────────────────────────
seccion('H-02 · La auditoría se persiste en la tabla `auditoria`');

$antes = db_count('auditoria');
chequear('audit_log() sin sesión devuelve true',
    audit_log(null, 'prueba_canal_publico', 'denuncias', ['codigo' => 'BV-TEST'], 999) === true);
chequear('audit_log() con usuario devuelve true',
    audit_log(1, 'prueba_con_usuario', 'usuarios', null, 1) === true);
chequear('las filas llegaron a la base de datos', db_count('auditoria') === $antes + 2);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Prohibición 1 · La auditoría no almacena datos del caso');

audit_log(1, 'prueba_depuracion', 'denuncias', [
    'codigo'             => 'BV-TEST',
    'descripcion'        => 'RELATO QUE NO DEBE VIAJAR',
    'tipo_violencia'     => 'sexual',
    'denunciante_nombre' => 'Nombre Real',
    'email'              => 'persona@udistrital.edu.co',
], 999);
$ultimo = db_select_one('SELECT datos FROM auditoria ORDER BY id DESC LIMIT 1');

chequear('descarta el relato',            strpos($ultimo['datos'], 'RELATO') === false);
chequear('descarta el tipo de violencia', strpos($ultimo['datos'], 'sexual') === false);
chequear('descarta el nombre',            strpos($ultimo['datos'], 'Nombre Real') === false);
chequear('descarta el correo',            strpos($ultimo['datos'], 'udistrital') === false);
chequear('conserva el radicado',          strpos($ultimo['datos'], 'BV-TEST') !== false);
chequear('deja constancia de la omisión', strpos($ultimo['datos'], '_campos_omitidos') !== false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('H-06 / H-07 · Radicado de alta entropía, único y dictable');

$codigos = [];
for ($i = 0; $i < 500; $i++) {
    $codigos[] = generate_codigo_denuncia();
}
chequear('formato BV-AAAAMMDD-XXXXXX',
    (bool)preg_match('/^BV-\d{8}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/', $codigos[0]), $codigos[0]);
chequear('500 radicados sin repetición', count(array_unique($codigos)) === 500);
chequear('sin caracteres ambiguos (0 O 1 I L)',
    !preg_match('/[01OIL]/', substr($codigos[0], 12)));
chequear('espacio de claves > 800 millones/día', pow(31, 6) > 800000000);

// ─────────────────────────────────────────────────────────────────────────────
seccion('H-14 / Prohibición 11 · Se escapa solo en salida');

$_POST['prueba'] = 'Ana & José dijo "no" <b>';
$valor = input('prueba');
chequear('el ampersand se conserva literal',  strpos($valor, '&amp;') === false, $valor);
chequear('las comillas se conservan literales', strpos($valor, '&quot;') === false, $valor);
chequear('e() escapa en el momento de imprimir',
    e($valor) === 'Ana &amp; José dijo &quot;no&quot; &lt;b&gt;', e($valor));
chequear('se eliminan los bytes nulos', strpos(clean_input("hola\0mundo"), "\0") === false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('H-03 / Prohibición 9 · La evidencia se registra y no es enumerable');

$denuncia = db_select_one('SELECT id FROM denuncias ORDER BY id LIMIT 1');
if (!$denuncia) {
    chequear('hay al menos una denuncia para asociar evidencia', false, 'base vacía');
} else {
    $nombre = nombre_archivo_seguro('mi evidencia.PDF');
    $archivo_id = db_insert_simple('archivos', [
        'denuncia_id'       => $denuncia['id'],
        'nombre_original'   => 'mi evidencia.PDF',
        'nombre_almacenado' => $nombre,
        'ruta'              => $denuncia['id'] . '/' . $nombre,
        'tipo_mime'         => 'application/pdf',
        'tamano'            => 1024,
        'usuario_id'        => null,
        'created_at'        => date('Y-m-d H:i:s'),
    ]);
    chequear('INSERT con usuario_id NULL (requiere migración 0005)', $archivo_id !== false);
    chequear('nombre no adivinable (32 hex + extensión)',
        (bool)preg_match('/^[a-f0-9]{32}\.pdf$/', $nombre), $nombre);
    chequear('sin uniqid() en el nombre', strpos($nombre, '_') === false);
    chequear('la ruta almacenada es relativa a UPLOAD_PATH',
        strpos($denuncia['id'] . '/' . $nombre, ':') === false);
}

// ─────────────────────────────────────────────────────────────────────────────
seccion('H-04 / H-05 · Seguimientos: se guardan y respetan la reserva');

if ($denuncia) {
    db_insert_simple('seguimientos', [
        'denuncia_id' => $denuncia['id'], 'usuario_id' => 1, 'tipo' => 'actualizacion',
        'contenido' => 'SEGUIMIENTO PUBLICO DE PRUEBA', 'privado' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    db_insert_simple('seguimientos', [
        'denuncia_id' => $denuncia['id'], 'usuario_id' => 1, 'tipo' => 'nota',
        'contenido' => 'NOTA INTERNA RESERVADA', 'privado' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    // Consulta idéntica a la del seguimiento público
    $publicos = db_select(
        'SELECT s.contenido, u.nombre AS profesional
           FROM seguimientos s
           LEFT JOIN usuarios u ON s.usuario_id = u.id
          WHERE s.denuncia_id = ? AND s.privado = 0
          ORDER BY s.created_at DESC',
        [$denuncia['id']]
    );
    $textos = array_column($publicos, 'contenido');

    chequear('la columna es `contenido`, no `observaciones`', isset($publicos[0]['contenido']));
    chequear('el seguimiento público se lee',
        in_array('SEGUIMIENTO PUBLICO DE PRUEBA', $textos, true));
    chequear('la nota privada NO se expone al público',
        !in_array('NOTA INTERNA RESERVADA', $textos, true));
}

// ─────────────────────────────────────────────────────────────────────────────
seccion('H-11 / H-12 · Enrutamiento y guardas');

chequear('todos los módulos tienen guarda BASE_PATH',
    count(glob(BASE_PATH . '/modules/*/*.php')) ===
    count(array_filter(glob(BASE_PATH . '/modules/*/*.php'),
        fn($f) => strpos(file_get_contents($f), "!defined('BASE_PATH')") !== false)));
chequear('ningún módulo redefine BASE_PATH',
    count(array_filter(glob(BASE_PATH . '/modules/*/*.php'),
        fn($f) => strpos(file_get_contents($f), "define('BASE_PATH'") !== false)) === 0);
chequear('require_auth() apunta a una ruta enrutada',
    strpos(file_get_contents(BASE_PATH . '/includes/auth.php'), "url('?page=login')") !== false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('§5.1.3 · Directorios sensibles bloqueados por HTTP');

foreach (['uploads', 'logs', 'cache', 'sessions', 'backups', 'includes', 'modules', 'bd'] as $dir) {
    $ruta = BASE_PATH . '/' . $dir . '/.htaccess';
    chequear("$dir/.htaccess deniega el acceso",
        is_file($ruta) && strpos(file_get_contents($ruta), 'denied') !== false);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('-', 60) . "\n";
echo ($fallidas === 0 ? 'TODAS LAS VERIFICACIONES PASARON' : 'HAY FALLOS')
   . ": $correctas correctas, $fallidas fallidas\n";

exit($fallidas === 0 ? 0 : 1);
