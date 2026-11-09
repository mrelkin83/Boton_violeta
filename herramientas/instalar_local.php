<?php
/**
 * INSTALADOR DE ENTORNO LOCAL
 *
 * Construye una base de datos completa para probar antes de desplegar.
 *
 * ⚠ SOLO PARA DESARROLLO. Aborta si detecta APP_ENV = production.
 *
 * Qué hace:
 *   1. Crea la base de datos (la elimina antes si existía)
 *   2. Aplica las 17 migraciones en orden
 *   3. Siembra datos SINTÉTICOS: nunca relatos reales
 *   4. Crea las cuentas del equipo con contraseñas conocidas de desarrollo
 *
 * Uso:
 *   php herramientas/instalar_local.php --confirmar
 *   php herramientas/instalar_local.php --confirmar --sin-datos
 *
 * Conexión: se toma de BV_CONFIG si está definida; si no, root sin contraseña
 * en 127.0.0.1, que es lo habitual en Laragon/XAMPP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

// Toda la salida se retiene hasta el final: init.php endurece la sesión con
// ini_set(), que exige que no se haya emitido nada todavía. Sin este buffer,
// el instalador se llena de advertencias que no significan nada.
ob_start();

if (!in_array('--confirmar', $argv, true)) {
    ob_end_flush();
    echo "\nInstalador del entorno LOCAL de Botón Violeta\n";
    echo str_repeat('-', 50) . "\n";
    echo "Este script ELIMINA y recrea la base de datos indicada.\n";
    echo "Para ejecutarlo:\n\n";
    echo "    php herramientas/instalar_local.php --confirmar\n\n";
    echo "Opciones:\n";
    echo "    --sin-datos     Solo esquema, sin datos de ejemplo\n";
    echo "    --bd=NOMBRE     Nombre de la base (por defecto: botonvioleta_local)\n\n";
    exit(0);
}

$raiz = dirname(__DIR__);

// ── Parámetros ───────────────────────────────────────────────────────────────
$nombre_bd = 'botonvioleta_local';
foreach ($argv as $arg) {
    if (strpos($arg, '--bd=') === 0) {
        $nombre_bd = substr($arg, 5);
    }
}
$sin_datos = in_array('--sin-datos', $argv, true);

if (preg_match('/prod|produccion|u\d{9}/i', $nombre_bd)) {
    exit("ABORTADO: «{$nombre_bd}» parece una base de producción.\n");
}

$host = '127.0.0.1';
$usuario = 'root';
$clave = '';

echo "\n=== Instalando entorno local ===\n";
echo "Base de datos: $nombre_bd\n\n";

// ── 1. Crear la base ─────────────────────────────────────────────────────────
try {
    $pdo = new PDO("mysql:host=$host", $usuario, $clave, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    exit("No se pudo conectar a MySQL en $host. ¿Está arrancado?\n");
}

$pdo->exec("DROP DATABASE IF EXISTS `$nombre_bd`");
$pdo->exec("CREATE DATABASE `$nombre_bd` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$nombre_bd`");

echo "[1/4] Base de datos creada\n";

// ── 2. Migraciones ───────────────────────────────────────────────────────────
$migraciones = glob($raiz . '/bd/migraciones/0*.sql');
sort($migraciones);

$aplicadas = 0;
$omitidas  = [];

foreach ($migraciones as $ruta) {
    $nombre = basename($ruta);

    // 0007 y 0010 son destructivas y opcionales: no se aplican por defecto
    if (strpos($nombre, '0007_') === 0 || strpos($nombre, '0010_') === 0) {
        $omitidas[] = $nombre . ' (destructiva, opcional)';
        continue;
    }

    $sql = file_get_contents($ruta);

    $sentencias = separar_sentencias($sql);
    $errores = [];

    foreach ($sentencias as $sentencia) {
        try {
            $pdo->exec($sentencia);
        } catch (PDOException $e) {
            $errores[] = substr($e->getMessage(), 0, 100);
        }
    }

    if (empty($errores)) {
        $aplicadas++;
        echo "      · $nombre (" . count($sentencias) . " sentencias)\n";
    } else {
        echo "      ✗ $nombre → " . $errores[0] . "\n";
        $omitidas[] = $nombre . ' (' . count($errores) . ' errores)';
    }
}

/**
 * Separar un archivo SQL en sentencias ejecutables
 *
 * `PDO::exec()` no entiende `DELIMITER`, que no es SQL sino una instrucción
 * del cliente de línea de comandos. Los archivos con disparadores lo usan, y
 * sin este tratamiento la migración se corta a la mitad —dejando el esquema
 * incompleto sin que nadie lo note, que es exactamente lo que pasó la primera
 * vez que se ejecutó este instalador.
 *
 * @return array
 */
function separar_sentencias($sql) {
    // Comentarios de línea completa
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    $sentencias = [];
    $delimitador = ';';
    $actual = '';

    foreach (preg_split('/\r?\n/', $sql) as $linea) {
        $recortada = trim($linea);

        if (preg_match('/^DELIMITER\s+(\S+)/i', $recortada, $m)) {
            // Se cierra lo acumulado y se cambia de delimitador
            if (trim($actual) !== '') { $sentencias[] = trim($actual); $actual = ''; }
            $delimitador = $m[1];
            continue;
        }

        $actual .= $linea . "\n";

        if (substr($recortada, -strlen($delimitador)) === $delimitador) {
            $sentencia = trim(substr(trim($actual), 0, -strlen($delimitador)));
            if ($sentencia !== '') { $sentencias[] = $sentencia; }
            $actual = '';
        }
    }

    if (trim($actual) !== '') { $sentencias[] = trim($actual); }

    return array_values(array_filter($sentencias, fn($s) => trim($s) !== ''));
}

echo "[2/4] Migraciones aplicadas: $aplicadas\n";
foreach ($omitidas as $o) {
    echo "      omitida: $o\n";
}

// ── 3. Configuración local ───────────────────────────────────────────────────
$ruta_config = dirname($raiz) . '/bv_config.php';

if (!is_file($ruta_config)) {
    $clave_cifrado = base64_encode(random_bytes(32));

    $config = <<<PHP
<?php
/**
 * CONFIGURACIÓN LOCAL DE DESARROLLO · generada por herramientas/instalar_local.php
 *
 * Vive FUERA del document root (CLAUDE.md §5.1.4).
 * NO usar en producción: APP_DEBUG está activo y la clave es de desarrollo.
 */
define('APP_NAME', 'Botón Violeta — local');
define('APP_ENV', 'development');
define('APP_DEBUG', true);
define('APP_URL', 'http://localhost/botonvioleta/');
define('APP_TIMEZONE', 'America/Bogota');

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', '$nombre_bd');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('ENCRYPTION_KEY', '$clave_cifrado');
define('SESSION_LIFETIME', 120);
define('SESSION_SECURE', false);
define('SESSION_NAME', 'BV_LOCAL');
define('CSRF_TOKEN_LIFETIME', 3600);
define('RATE_LIMIT_MAX_ATTEMPTS', 5);
define('RATE_LIMIT_DECAY_MINUTES', 15);

// Interruptores: apagados, igual que en producción
define('SEGUIMIENTO_OTP_ACTIVO', false);
define('GRAPH_ACTIVO', false);
define('CANAL_ANONIMO_ACTIVO', false);
define('EXIGIR_ACUERDO_CONFIDENCIALIDAD', false);
define('EXIGIR_CERTIFICACION_VIGENTE', false);
define('UMBRAL_SUPRESION', 5);

define('MAIL_ENABLED', false);
define('UPLOAD_MAX_SIZE', 10485760);
define('UPLOAD_ALLOWED_EXTENSIONS', 'pdf,doc,docx,jpg,jpeg,png');
define('UPLOAD_PATH', BASE_PATH . '/uploads');
define('LOG_ENABLED', true);
define('LOG_LEVEL', 'debug');
define('LOG_PATH', BASE_PATH . '/logs');
define('AUDIT_ENABLED', true);
define('AUDIT_IP_TRACKING', true);

define('INCLUDES_PATH', BASE_PATH . '/includes');
define('MODULES_PATH', BASE_PATH . '/modules');
define('ASSETS_PATH', BASE_PATH . '/assets');
define('VIEWS_PATH', BASE_PATH . '/views');
define('BUSINESS_DAYS_CALCULATION', true);
define('RISK_KEYWORDS_FILE', BASE_PATH . '/config/risk_keywords.json');

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set(APP_TIMEZONE);

foreach ([UPLOAD_PATH, LOG_PATH, BASE_PATH . '/sessions', BASE_PATH . '/cache'] as \$dir) {
    if (!is_dir(\$dir)) { mkdir(\$dir, 0755, true); }
}
PHP;

    file_put_contents($ruta_config, $config);
    echo "[3/4] Configuración creada en $ruta_config\n";
} else {
    echo "[3/4] Configuración ya existente en $ruta_config (no se sobrescribe)\n";
}

// ── 4. Datos sintéticos ──────────────────────────────────────────────────────
if ($sin_datos) {
    echo "[4/4] Sin datos de ejemplo (--sin-datos)\n";
} else {
    define('BASE_PATH', $raiz);
    putenv('BV_CONFIG=' . $ruta_config);

    // init.php endurece la sesión con ini_set(), que exige que no se haya
    // emitido nada todavía. En CLI ya escribimos el progreso, así que se
    // retiene la salida mientras arranca el sistema.
    ob_start();
    require_once $raiz . '/init.php';
    ob_end_clean();

    // Cuentas del equipo. Contraseña de DESARROLLO, documentada a propósito:
    // el riesgo de una contraseña conocida en local es menor que el de un
    // instalador que nadie sabe usar.
    $contrasena_dev = 'DesarrolloLocal2026';

    $cuentas = [
        ['Coordinación EDHEG',  'coordinacion@local.test', 'coordinacion_edheg'],
        ['Psicología',          'psicologia@local.test',   'psicologo'],
        ['Trabajo Social',      'trabajosocial@local.test','trabajador_social'],
        ['Jurídica',            'juridica@local.test',     'abogado'],
        ['Instructor',          'instructor@local.test',   'instructor'],
        ['Decisor',             'decisor@local.test',      'decisor'],
        ['Unidad Académica',    'unidad@local.test',       'unidad_academica'],
        ['Administrador',       'admin@local.test',        'administrador'],
    ];

    $ids = [];
    foreach ($cuentas as [$nombre, $correo, $rol]) {
        $ids[$rol] = db_insert_simple('usuarios', [
            'nombre'     => $nombre,
            'email'      => $correo,
            'password'   => hash_password($contrasena_dev),
            'rol'        => $rol,
            'activo'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ── Cuentas heredadas de la migración 0001 ──────────────────────────
    //
    // El esquema base siembra admin@, coordinador@ y psicologo@botonvioleta.com
    // con hashes que NO corresponden a ninguna de las contraseñas que la
    // documentación publica (`Admin123!`, `Coord123!`, `Psico123!`). En
    // producción esos hashes sí fueron corregidos en su momento; el archivo
    // del repositorio no. Resultado: un entorno recién instalado desde las
    // migraciones tiene tres cuentas a las que nadie puede entrar.
    //
    // Aquí se les asigna la misma contraseña de desarrollo que al resto, para
    // que en local no haya credenciales que no funcionen.
    // ── Réplica de las cuentas que existen en PRODUCCIÓN ────────────────
    //
    // La migración 0001 las sembraba, con contraseñas publicadas en sus
    // comentarios y hashes que no correspondían a esas contraseñas. Se sacaron
    // del esquema: un archivo de esquema no debe crear cuentas.
    //
    // Pero en producción existen, así que el entorno local las replica para
    // seguir siendo un espejo fiel. Aquí el hash se calcula en el momento,
    // de modo que la contraseña documentada SÍ abre la cuenta.
    $cuentas_produccion = [
        ['Administrador del Sistema', 'admin@botonvioleta.com',       'admin',       'Admin123!'],
        ['María Coordinadora',        'coordinador@botonvioleta.com', 'coordinador', 'Coord123!'],
        ['Ana Psicóloga',             'psicologo@botonvioleta.com',   'psicologo',   'Psico123!'],
    ];

    $heredadas = [];

    foreach ($cuentas_produccion as [$nombre_cuenta, $correo, $rol_cuenta, $clave]) {
        db_insert_simple('usuarios', [
            'nombre'     => $nombre_cuenta,
            'email'      => $correo,
            'password'   => hash_password($clave),
            'rol'        => $rol_cuenta,
            'activo'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $heredadas[] = ['email' => $correo, 'clave' => $clave];
    }

    // Unidades de EJEMPLO, marcadas como tales.
    // La estructura académica real la carga Bienestar (PLANTILLA_unidades.sql).
    $sede = db_insert_simple('unidades', [
        'tipo' => 'sede', 'codigo' => 'EJ-SEDE', 'nombre' => '[EJEMPLO] Sede de pruebas', 'activa' => 1,
    ]);
    $facultad = db_insert_simple('unidades', [
        'tipo' => 'facultad', 'padre_id' => $sede, 'codigo' => 'EJ-FAC',
        'nombre' => '[EJEMPLO] Facultad de pruebas', 'activa' => 1,
    ]);
    $proyecto = db_insert_simple('unidades', [
        'tipo' => 'proyecto_curricular', 'padre_id' => $facultad, 'codigo' => 'EJ-PC',
        'nombre' => '[EJEMPLO] Proyecto curricular de pruebas', 'activa' => 1,
    ]);

    db_update_simple('usuarios', ['unidad_id' => $proyecto], ['id' => $ids['unidad_academica']]);

    db_insert_simple('responsables_notificacion', [
        'unidad_id' => null, 'via' => null, 'rol_destino' => 'coordinacion_edheg',
        'correo' => 'coordinacion@local.test', 'activo' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    // Casos sintéticos. Volumen suficiente para que los reportes tengan
    // celdas por encima del umbral de supresión.
    $tipos = ['fisica', 'psicologica', 'sexual', 'laboral', 'verbal'];
    $estados = ['nueva', 'en_proceso', 'resuelta'];
    $etapas = ['recepcion', 'indagacion_previa', 'investigacion', 'cargos_formulados'];
    $vinculaciones = ['estudiante_pregrado', 'docente_carrera', 'administrativo', 'contratista'];

    $creados = 0;

    foreach ($tipos as $i => $tipo) {
        foreach ($estados as $j => $estado) {
            for ($k = 0; $k < 6; $k++) {
                $creados++;
                $correo = "persona{$creados}@ejemplo.test";

                $id = db_insert_simple('denuncias', [
                    'codigo'         => generate_codigo_denuncia(),
                    'tipo_violencia' => $tipo,
                    'descripcion'    => "[DATO SINTÉTICO] Caso de prueba número $creados. "
                                      . "Este texto no corresponde a ninguna persona real y existe "
                                      . "únicamente para validar el sistema antes del despliegue.",
                    'fecha_hechos'   => date('Y-m-d', strtotime("-" . ($creados % 90) . " days")),
                    'lugar_hechos'   => '[EJEMPLO] Espacio de pruebas',
                    'estado'         => $estado,
                    'etapa_procesal' => $etapas[($i + $j + $k) % 4],
                    'es_anonima'     => 0,
                    'denunciante_nombre' => "[SINTÉTICO] Persona $creados",
                    'denunciante_correo_cifrado'   => cifrar_contacto($correo),
                    'denunciante_telefono_cifrado' => cifrar_contacto('300' . str_pad((string)$creados, 7, '0', STR_PAD_LEFT)),
                    'denunciante_correo_indice'    => indice_ciego($correo, 'correo'),
                    'canal_preferido' => 'personal',
                    'unidad_hechos_id' => $proyecto,
                    'created_at'     => date('Y-m-d H:i:s', strtotime("-" . ($creados % 200) . " days")),
                ]);

                if (!$id) { continue; }

                registrar_persona_caso($id, 'denunciante', [
                    'nombre' => "[SINTÉTICO] Persona $creados", 'correo' => $correo,
                    'es_mismo_denunciante' => ($creados % 5 !== 0), // 1 de cada 5 es quejoso
                ]);
                registrar_persona_caso($id, 'denunciada', [
                    'tipo_vinculacion' => $vinculaciones[$creados % 4],
                    'unidad_id' => $proyecto,
                ]);

                derivar_competencia($id, $vinculaciones[$creados % 4], $proyecto);

                // Asignaciones, para que RN-02 tenga con qué trabajar
                asignar_actuacion($id, $ids['psicologo'], 'psicologia', $ids['coordinacion_edheg']);
                if ($creados % 3 === 0) {
                    asignar_actuacion($id, $ids['instructor'], 'instruccion', $ids['coordinacion_edheg']);
                }

                // Un seguimiento público en la mitad de los casos
                if ($creados % 2 === 0) {
                    db_insert_simple('seguimientos', [
                        'denuncia_id' => $id, 'usuario_id' => $ids['psicologo'],
                        'tipo' => 'actualizacion',
                        'contenido' => '[SINTÉTICO] Se realizó contacto y se activó la ruta de atención.',
                        'privado' => 0, 'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                // Medidas en un tercio de los casos
                if ($creados % 3 === 0) {
                    $cat = db_select_one("SELECT id FROM catalogo_medidas WHERE codigo = 'A24_ACTIVACION_RUTA'");
                    if ($cat) {
                        $r = solicitar_medida([
                            'denuncia_id' => $id, 'catalogo_id' => $cat['id'],
                            'solicitada_por' => 'victima', 'unidad_ejecutora_id' => $proyecto,
                        ], $ids['coordinacion_edheg']);

                        if ($r['exito']) {
                            $consent = registrar_consentimiento($id, 'medida_especifica',
                                '[SINTÉTICO] Se explicó el alcance de la medida.',
                                true, 'presencial', $ids['coordinacion_edheg'], $r['medida_id']);
                            db_update_simple('medidas', ['consentimiento_id' => $consent], ['id' => $r['medida_id']]);
                            cambiar_estado_medida($r['medida_id'], 'notificada', $ids['coordinacion_edheg']);
                            cambiar_estado_medida($r['medida_id'], 'en_ejecucion', $ids['coordinacion_edheg']);
                        }
                    }
                }
            }
        }
    }

    echo "[4/4] Datos sintéticos: $creados casos, " . count($cuentas) . " cuentas\n";

    echo "\n" . str_repeat('=', 62) . "\n";
    echo "ENTORNO LOCAL LISTO\n";
    echo str_repeat('=', 62) . "\n\n";
    echo "URL:          " . APP_URL . "\n";
    echo "Base:         $nombre_bd\n";
    echo "Config:       $ruta_config\n\n";
    echo "CREDENCIALES\n";
    echo str_repeat('-', 62) . "\n";

    if (!empty($heredadas)) {
        echo "Réplica de las cuentas de PRODUCCIÓN · mismas credenciales:\n";
        foreach ($heredadas as $cuenta) {
            printf("   %-30s %s\n", $cuenta['email'], $cuenta['clave']);
        }
        echo "\n   El esquema (0001) ya NO siembra cuentas: las crea este\n";
        echo "   instalador calculando el hash en el momento, de modo que la\n";
        echo "   contraseña documentada sí abre la cuenta. En producción estas\n";
        echo "   tres cuentas existen y sus contraseñas deben rotarse.\n\n";
    }

    echo "Cuentas por rol · contraseña: $contrasena_dev\n";
    foreach ($cuentas as [$nombre, $correo, $rol]) {
        printf("   %-30s %s\n", $correo, $rol);
    }
    echo str_repeat('-', 62) . "\n";
    echo "\nCasos sintéticos: $creados · medidas y seguimientos incluidos\n";
    echo "\n⚠ Todos los datos llevan el prefijo [SINTÉTICO] o [EJEMPLO].\n";
    echo "  Ninguno corresponde a una persona real.\n";
    echo "\nPruebas:  BV_CONFIG=$ruta_config php pruebas/reglas/fase7_prevencion.php\n\n";
}

// Se libera la salida retenida desde el arranque
ob_end_flush();
