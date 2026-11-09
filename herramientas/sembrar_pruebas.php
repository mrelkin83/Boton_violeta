<?php
/**
 * SEMBRAR DATOS DE PRUEBA (apto para el servidor)
 *
 * A diferencia de instalar_local.php, este script NO elimina ni recrea nada:
 * solo INSERTA cuentas y casos sintéticos, todos marcados «[PRUEBA]», y sabe
 * retirarlos exactamente con --limpiar. Puede correrse en un entorno con
 * datos reales sin tocarlos.
 *
 * Qué siembra:
 *   · 1 sede + 1 facultad + 1 proyecto curricular [PRUEBA]
 *   · 1 cuenta por rol (contraseñas generadas, mostradas UNA sola vez)
 *   · Turnos de atención de lunes a viernes (para probar RN-18)
 *   · 8 casos sintéticos que cubren: RN-14 pendiente, medida con
 *     consentimiento y compromiso, encuentro inicial, sanción en firme,
 *     desistimiento, retaliación, y un reporte anónimo con mérito (RN-21)
 *
 * Uso:
 *   php herramientas/sembrar_pruebas.php --confirmar
 *   php herramientas/sembrar_pruebas.php --limpiar
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__));

ob_start();
require_once BASE_PATH . '/init.php';
ob_end_clean();

const MARCA = '[PRUEBA]';
const CORREO_PATRON = 'prueba.%@udistrital.edu.co';

// ─────────────────────────────────────────────────────────────────────────────
// LIMPIEZA
// ─────────────────────────────────────────────────────────────────────────────
if (in_array('--limpiar', $argv, true)) {
    $denuncias = db_select("SELECT id FROM denuncias WHERE descripcion LIKE ?", [MARCA . '%']) ?: [];

    foreach ($denuncias as $d) {
        // retaliaciones y sanciones no siempre tienen CASCADE
        db_execute('DELETE FROM retaliaciones WHERE denuncia_id = ?', [$d['id']]);
        db_execute('DELETE FROM sanciones WHERE denuncia_id = ?', [$d['id']]);
        db_execute('DELETE FROM denuncias WHERE id = ?', [$d['id']]);
    }

    db_execute("DELETE FROM reportes_anonimos WHERE descripcion LIKE ?", [MARCA . '%']);

    $cuentas = db_select("SELECT id FROM usuarios WHERE nombre LIKE ? AND email LIKE ?",
                         [MARCA . '%', CORREO_PATRON]) ?: [];
    foreach ($cuentas as $c) {
        db_execute('DELETE FROM turnos_atencion WHERE usuario_id = ?', [$c['id']]);
        db_execute('DELETE FROM certificaciones_equipo WHERE usuario_id = ?', [$c['id']]);
        db_execute('DELETE FROM acuerdos_confidencialidad WHERE usuario_id = ?', [$c['id']]);
        db_execute('DELETE FROM asignaciones WHERE usuario_id = ?', [$c['id']]);
        db_execute('DELETE FROM usuarios WHERE id = ?', [$c['id']]);
    }

    // Unidades de prueba: primero soltar cualquier referencia restante
    $unidades = db_select("SELECT id FROM unidades WHERE nombre LIKE ?", [MARCA . '%']) ?: [];
    foreach ($unidades as $u) {
        db_execute('UPDATE usuarios SET unidad_id = NULL WHERE unidad_id = ?', [$u['id']]);
        db_execute('DELETE FROM turnos_atencion WHERE unidad_id = ?', [$u['id']]);
    }
    // Hijos antes que padres (FK padre_id)
    foreach (['proyecto_curricular', 'facultad', 'sede'] as $tipo) {
        db_execute("DELETE FROM unidades WHERE nombre LIKE ? AND tipo = ?", [MARCA . '%', $tipo]);
    }

    echo "Datos de prueba retirados: " . count($denuncias) . " casos, "
       . count($cuentas) . " cuentas, " . count($unidades) . " unidades.\n";
    exit(0);
}

if (!in_array('--confirmar', $argv, true)) {
    echo "\nSembrador de datos de PRUEBA · Botón Violeta\n";
    echo str_repeat('-', 50) . "\n";
    echo "No borra nada: solo inserta datos marcados «[PRUEBA]».\n\n";
    echo "  php herramientas/sembrar_pruebas.php --confirmar   siembra\n";
    echo "  php herramientas/sembrar_pruebas.php --limpiar     retira lo sembrado\n\n";
    exit(0);
}

if (db_exists('usuarios', ['email' => 'prueba.coordinacion@udistrital.edu.co'])) {
    exit("Los datos de prueba ya existen. Use --limpiar primero si quiere resembrar.\n");
}

/**
 * Contraseña legible y fuerte, sin caracteres ambiguos
 */
function contrasena_prueba(): string {
    $alfabeto = '23456789ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
    $grupos = [];
    for ($g = 0; $g < 3; $g++) {
        $grupo = '';
        for ($i = 0; $i < 5; $i++) {
            $grupo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        $grupos[] = $grupo;
    }
    return implode('-', $grupos);
}

echo "\n=== Sembrando datos de prueba ===\n";

// ── 1. Unidades ──────────────────────────────────────────────────────────────
$sede = db_insert_simple('unidades', [
    'tipo' => 'sede', 'codigo' => 'PR-SEDE', 'nombre' => MARCA . ' Sede Central', 'activa' => 1,
]);
$facultad = db_insert_simple('unidades', [
    'tipo' => 'facultad', 'padre_id' => $sede, 'codigo' => 'PR-FAC',
    'nombre' => MARCA . ' Facultad de Ingeniería', 'activa' => 1,
]);
$proyecto = db_insert_simple('unidades', [
    'tipo' => 'proyecto_curricular', 'padre_id' => $facultad, 'codigo' => 'PR-SIS',
    'nombre' => MARCA . ' Ingeniería de Sistemas', 'activa' => 1,
]);

echo "[1/5] Unidades: sede #$sede, facultad #$facultad, proyecto #$proyecto\n";

// ── 2. Cuentas por rol ───────────────────────────────────────────────────────
$roles = [
    'coordinacion' => ['coordinacion_edheg',   'Coordinación EDHEG',   null],
    'psicologia'   => ['psicologo',            'Psicología',           null],
    'social'       => ['trabajador_social',    'Trabajo Social',       null],
    'juridica'     => ['abogado',              'Jurídica',             null],
    'instructor'   => ['instructor',           'Instrucción',          null],
    'decisor'      => ['decisor',              'Juzgamiento',          null],
    'unidad'       => ['unidad_academica',     'Unidad Académica',     $proyecto],
    'talento'      => ['talento_humano',       'Talento Humano',       null],
    'reportes'     => ['reportes',             'Reportes',             null],
    'comision'     => ['comision_seguimiento', 'Comisión Seguimiento', null],
];

$credenciales = [];
$ids = [];

foreach ($roles as $clave => [$rol, $nombre, $unidad_id]) {
    $correo = "prueba.$clave@udistrital.edu.co";
    $contrasena = contrasena_prueba();

    $id = create_user([
        'nombre'          => MARCA . " $nombre",
        'email'           => $correo,
        'correo_personal' => "prueba.$clave@correopersonal.test",
        'password'        => $contrasena,
        'rol'             => $rol,
        'unidad_id'       => $unidad_id,
        'activo'          => 1,
    ]);

    if (!$id) { exit("ABORTADO: no se pudo crear la cuenta $correo\n"); }

    $ids[$clave] = (int)$id;
    $credenciales[] = [$correo, $rol, $contrasena];

    // Idoneidad completa: certificación vigente + acuerdo de confidencialidad,
    // para que las reglas RN-11 y del art. 25 par. no bloqueen la prueba
    // aunque los interruptores estén encendidos.
    db_insert_simple('certificaciones_equipo', [
        'usuario_id' => $id, 'nombre_curso' => MARCA . ' Formación VBG',
        'emitida_at' => date('Y-m-d'), 'vence_at' => date('Y-m-d', strtotime('+2 years')),
    ]);
    db_insert_simple('acuerdos_confidencialidad', [
        'usuario_id' => $id, 'version' => 'v1-prueba',
        'texto_hash' => hash('sha256', 'acuerdo de prueba'),
        'aceptado_at' => date('Y-m-d H:i:s'), 'ip' => '127.0.0.1',
    ]);
}

echo "[2/5] Cuentas: " . count($credenciales) . " roles\n";

// ── 3. Turnos (RN-18) ────────────────────────────────────────────────────────
for ($dia = 1; $dia <= 5; $dia++) {
    db_insert_simple('turnos_atencion', [
        'unidad_id' => $sede, 'usuario_id' => $ids['psicologia'],
        'dia_semana' => $dia, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'activo' => 1,
    ]);
}

echo "[3/5] Turnos: psicología, lunes a viernes 8-12 en la sede de prueba\n";

// ── 4. Casos sintéticos ──────────────────────────────────────────────────────
function caso_prueba(string $texto, array $extra = []): int {
    $id = db_insert_simple('denuncias', array_merge([
        'codigo'         => generate_codigo_denuncia(),
        'tipo_violencia' => 'psicologica',
        'descripcion'    => MARCA . ' ' . $texto
                          . ' Este caso es sintético y no corresponde a ninguna persona real.',
        'fecha_hechos'   => date('Y-m-d', strtotime('-' . random_int(3, 60) . ' days')),
        'lugar_hechos'   => MARCA . ' Espacio de pruebas',
        'estado'         => 'nueva',
        'etapa_procesal' => 'recepcion',
        'canal_origen'   => 'boton_violeta',
        'es_anonima'     => 0,
        'canal_preferido'=> 'personal',
        'denunciante_nombre' => MARCA . ' Persona Denunciante',
        'denunciante_correo_cifrado' => cifrar_contacto('denunciante@correopersonal.test'),
        'denunciante_correo_indice'  => indice_ciego('denunciante@correopersonal.test', 'correo'),
        'created_at'     => date('Y-m-d H:i:s'),
    ], $extra));
    if (!$id) { exit("ABORTADO: no se pudo crear un caso de prueba.\n"); }
    return (int)$id;
}

$coordinacion = $ids['coordinacion'];
$casos = [];

// Caso 1 · flujo completo del art. 25: medida + consentimiento + compromiso
$c = caso_prueba('Caso 1: flujo completo del art. 25.',
    ['tipo_violencia' => 'sexual', 'unidad_hechos_id' => $proyecto, 'unidad_atencion_id' => $sede]);
registrar_persona_caso($c, 'afectada', [
    'nombre' => MARCA . ' Afectada 1', 'correo' => 'afectada1@correopersonal.test',
    'correo_institucional' => 'afectada1@udistrital.edu.co',
    'canal_preferido' => 'personal', 'es_mismo_denunciante' => 1,
]);
registrar_persona_caso($c, 'denunciada', ['tipo_vinculacion' => 'estudiante_pregrado', 'unidad_id' => $proyecto]);
derivar_competencia($c, 'estudiante_pregrado', $proyecto);
asignar_actuacion($c, $ids['psicologia'], 'psicologia', $coordinacion);
$cat = db_select_one("SELECT id FROM catalogo_medidas WHERE activo = 1 AND requiere_consentimiento = 1 LIMIT 1");
if ($cat) {
    $consent = registrar_consentimiento($c, 'medida_especifica',
        MARCA . ' Alcance explicado a la persona.', true, 'presencial', $coordinacion);
    $m = solicitar_medida([
        'denuncia_id' => $c, 'catalogo_id' => (int)$cat['id'],
        'solicitada_por' => 'victima', 'consentimiento_id' => $consent,
        'unidad_ejecutora_id' => $proyecto,
    ], $coordinacion);
    if ($m['exito']) {
        cambiar_estado_medida($m['medida_id'], 'notificada', $coordinacion);
        cambiar_estado_medida($m['medida_id'], 'en_ejecucion', $coordinacion);
    }
}
$casos[] = $c;

// Caso 2 · vía docente con RN-14 PENDIENTE (para ver el bloqueo)
$c = caso_prueba('Caso 2: vía docente, decisión sobre Procuraduría pendiente (RN-14).',
    ['tipo_violencia' => 'laboral', 'unidad_hechos_id' => $proyecto, 'unidad_atencion_id' => $sede]);
registrar_persona_caso($c, 'afectada', ['canal_preferido' => 'ambos', 'es_mismo_denunciante' => 1,
    'correo' => 'afectada2@correopersonal.test', 'correo_institucional' => 'afectada2@udistrital.edu.co']);
registrar_persona_caso($c, 'denunciada', ['tipo_vinculacion' => 'docente_carrera', 'unidad_id' => $proyecto]);
derivar_competencia($c, 'docente_carrera', $proyecto);
asignar_actuacion($c, $ids['juridica'], 'juridica', $coordinacion);
$casos[] = $c;

// Caso 3 · encuentro inicial agendado automáticamente (RN-18)
$c = caso_prueba('Caso 3: encuentro inicial automático (RN-18).',
    ['unidad_hechos_id' => $sede, 'unidad_atencion_id' => $sede]);
registrar_persona_caso($c, 'afectada', ['canal_preferido' => 'personal', 'es_mismo_denunciante' => 1,
    'nombre' => MARCA . ' Afectada 3', 'correo' => 'afectada3@correopersonal.test']);
registrar_persona_caso($c, 'denunciada', ['tipo_vinculacion' => 'administrativo']);
derivar_competencia($c, 'administrativo', null);
agendar_encuentro_inicial($c);
$casos[] = $c;

// Caso 4 · sanción en firme (habilita restaurativos RN-16)
$c = caso_prueba('Caso 4: con sanción en firme registrada.',
    ['etapa_procesal' => 'juzgamiento', 'estado' => 'en_proceso']);
registrar_persona_caso($c, 'denunciada', ['tipo_vinculacion' => 'estudiante_pregrado', 'unidad_id' => $proyecto]);
derivar_competencia($c, 'estudiante_pregrado', $proyecto);
$s = registrar_sancion(['denuncia_id' => $c, 'autoridad' => 'Consejo de Facultad',
    'tipo' => 'Matrícula condicional', 'acto_fecha' => date('Y-m-d')], $coordinacion, 'coordinacion_edheg');
if ($s['exito']) { marcar_sancion_firme($s['sancion_id'], date('Y-m-d'), $coordinacion, 'coordinacion_edheg'); }
$casos[] = $c;

// Caso 5 · quejoso (RN-07: quien denuncia no es la persona afectada)
$c = caso_prueba('Caso 5: reportado por un tercero (RN-07).');
registrar_persona_caso($c, 'denunciante', ['nombre' => MARCA . ' Quejoso',
    'correo' => 'quejoso@correopersonal.test', 'canal_preferido' => 'personal', 'es_mismo_denunciante' => 0]);
registrar_persona_caso($c, 'afectada', ['es_mismo_denunciante' => 0]);
registrar_persona_caso($c, 'denunciada', ['tipo_vinculacion' => 'contratista']);
derivar_competencia($c, 'contratista', null);
$casos[] = $c;

// Caso 6 · retaliación reportada (Ac. 003 art. 18)
$c = caso_prueba('Caso 6: con retaliación reportada.');
registrar_persona_caso($c, 'afectada', ['canal_preferido' => 'personal', 'es_mismo_denunciante' => 1]);
db_insert_simple('retaliaciones', [
    'denuncia_id' => $c, 'descripcion' => MARCA . ' Presunta represalia académica tras la queja.',
    'reportada_por' => 'victima', 'estado' => 'reportada', 'created_at' => date('Y-m-d H:i:s'),
]);
$casos[] = $c;

// Caso 7 · persona afectada menor de edad (RN-20)
$c = caso_prueba('Caso 7: persona afectada menor de edad (RN-20).');
registrar_persona_caso($c, 'afectada', [
    'es_menor_de_edad' => 1, 'contacto_emergencia' => MARCA . ' Acudiente 3000000000',
    'canal_preferido' => 'personal', 'es_mismo_denunciante' => 1,
    'correo' => 'afectada7@correopersonal.test',
]);
registrar_persona_caso($c, 'denunciada', ['tipo_vinculacion' => 'docente_vinculacion_especial']);
derivar_competencia($c, 'docente_vinculacion_especial', null);
$casos[] = $c;

// Caso 8 · desistimiento registrado (art. 24)
$c = caso_prueba('Caso 8: con desistimiento de la víctima (art. 24).');
registrar_persona_caso($c, 'afectada', ['canal_preferido' => 'personal', 'es_mismo_denunciante' => 1]);
registrar_consentimiento($c, 'desistimiento',
    MARCA . ' La persona manifiesta su voluntad de no continuar.', true, 'presencial', $coordinacion);
$casos[] = $c;

// Reporte anónimo con mérito, SIN decidir (RN-21, para probar la bandeja)
db_insert_simple('reportes_anonimos', [
    'tipo' => 'anonimo',
    'descripcion' => MARCA . ' Reporte con señalamiento concreto y evidencia adjunta declarada.',
    'espacio_senalado' => MARCA . ' Edificio de pruebas',
    'tiene_pruebas' => 1, 'estado' => 'recibido', 'created_at' => date('Y-m-d H:i:s'),
]);

echo "[4/5] Casos: " . count($casos) . " + 1 reporte anónimo con mérito\n";

// ── 5. Credenciales ──────────────────────────────────────────────────────────
echo "[5/5] Listo.\n\n";
echo str_repeat('=', 66) . "\n";
echo "CREDENCIALES DE PRUEBA · se muestran UNA sola vez\n";
echo str_repeat('=', 66) . "\n";
printf("%-42s %-22s %s\n", 'CORREO', 'ROL', 'CONTRASEÑA');
foreach ($credenciales as [$correo, $rol, $contrasena]) {
    printf("%-42s %-22s %s\n", $correo, $rol, $contrasena);
}
echo str_repeat('-', 66) . "\n";
echo "Radicados de los casos:\n";
foreach ($casos as $id) {
    $fila = db_select_one('SELECT codigo FROM denuncias WHERE id = ?', [$id]);
    echo "  · " . ($fila['codigo'] ?? "#$id") . "\n";
}
echo "\nPara retirarlo todo: php herramientas/sembrar_pruebas.php --limpiar\n\n";
