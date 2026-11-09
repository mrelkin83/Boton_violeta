<?php
/**
 * PRUEBAS DE REGLAS DE NEGOCIO · FASE 3
 *
 * CLAUDE.md §7 exige una prueba automatizada por cada regla inviolable.
 * Este archivo cubre las cinco reglas de la Fase 3:
 *
 *   RN-01  Separación entre instrucción y juzgamiento   (Ac. 003 art. 23 par.)
 *   RN-02  Reserva por etapa procesal                    (Ac. 003 art. 47)
 *   RN-07  Alcance del quejoso                           (Ac. 003 art. 44)
 *   RN-08  Aislamiento de la unidad académica            (Ac. 002 art. 25 lit. c)
 *   RN-12  Impedimentos                                  (Ley 1437 art. 11)
 *
 * Más la derivación de competencia (ADR-04).
 *
 * ⚠ Ejecutar SIEMPRE contra una réplica.
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config_pruebas.php php pruebas/reglas/fase3_reglas.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Estas pruebas solo se ejecutan por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/init.php';

if (APP_ENV === 'production') {
    exit("ABORTADO: APP_ENV es 'production'.\n");
}

$correctas = 0;
$fallidas  = 0;

function chequear(string $nombre, bool $cond, string $detalle = ''): void {
    global $correctas, $fallidas;
    if ($cond) { $correctas++; echo "  [OK]    $nombre\n"; }
    else { $fallidas++; echo "  [FALLO] $nombre" . ($detalle !== '' ? "  ($detalle)" : '') . "\n"; }
}
function seccion(string $t): void { echo "\n=== $t ===\n"; }

// ─────────────────────────────────────────────────────────────────────────────
// Estado inicial reproducible
// ─────────────────────────────────────────────────────────────────────────────
db_execute('DELETE FROM impedimentos');
db_execute('DELETE FROM asignaciones');
db_execute('DELETE FROM competencias');
db_execute('DELETE FROM personas_caso');

/** Crea un expediente de prueba en una etapa concreta */
function crear_expediente(string $codigo, string $etapa = 'recepcion'): int {
    db_execute('DELETE FROM denuncias WHERE codigo = ?', [$codigo]);
    return (int)db_insert_simple('denuncias', [
        'codigo'         => $codigo,
        'tipo_violencia' => 'otro',
        'descripcion'    => 'Expediente sintetico para pruebas de reglas de negocio.',
        'fecha_hechos'   => date('Y-m-d'),
        'estado'         => 'nueva',
        'etapa_procesal' => $etapa,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
}

/** Crea un usuario de prueba con un rol dado */
function crear_usuario(string $email, string $rol, $unidad_id = null): int {
    db_execute('DELETE FROM usuarios WHERE email = ?', [$email]);
    return (int)db_insert_simple('usuarios', [
        'nombre'     => 'Usuario ' . $rol,
        'email'      => $email,
        'password'   => 'x',
        'rol'        => $rol,
        'unidad_id'  => $unidad_id,
        'activo'     => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

$exp_reserva  = crear_expediente('BV-PRUEBA-RESERVA', 'indagacion_previa');
$exp_cargos   = crear_expediente('BV-PRUEBA-CARGOS', 'cargos_formulados');

$u_coord      = crear_usuario('coord@pruebas.local', 'coordinacion_edheg');
$u_psico      = crear_usuario('psico@pruebas.local', 'psicologo');
$u_instructor = crear_usuario('instructor@pruebas.local', 'instructor');
$u_decisor    = crear_usuario('decisor@pruebas.local', 'decisor');
$u_unidad     = crear_usuario('unidad@pruebas.local', 'unidad_academica');
$u_admin      = crear_usuario('admin@pruebas.local', 'administrador');
$u_reportes   = crear_usuario('reportes@pruebas.local', 'reportes');

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-01 · La misma persona no puede instruir y fallar');

$r1 = asignar_actuacion($exp_reserva, $u_instructor, 'instruccion', $u_coord);
chequear('se acepta la asignación de instrucción', $r1['exito'] === true, $r1['motivo']);

$r2 = asignar_actuacion($exp_reserva, $u_instructor, 'juzgamiento', $u_coord);
chequear('se RECHAZA que quien instruye también falle', $r2['exito'] === false);
chequear('el rechazo cita el fundamento normativo',
    strpos($r2['motivo'], 'art. 23') !== false, $r2['motivo']);

$r3 = asignar_actuacion($exp_reserva, $u_decisor, 'juzgamiento', $u_coord);
chequear('otra persona sí puede fallar el mismo expediente', $r3['exito'] === true, $r3['motivo']);

$r4 = asignar_actuacion($exp_reserva, $u_decisor, 'instruccion', $u_coord);
chequear('el rechazo opera también en sentido inverso', $r4['exito'] === false);

$rechazos = db_select_one("SELECT COUNT(*) AS t FROM auditoria WHERE accion = 'asignacion_rechazada_rn01'");
chequear('los rechazos quedan auditados', (int)$rechazos['t'] >= 2);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-02 · Reserva por etapa procesal');

chequear('un expediente en indagación previa está bajo reserva',
    expediente_bajo_reserva('indagacion_previa') === true);
chequear('con cargos formulados cesa la reserva',
    expediente_bajo_reserva('cargos_formulados') === false);
chequear('con archivo definitivo cesa la reserva',
    expediente_bajo_reserva('archivo_definitivo') === false);

chequear('la coordinación accede a un expediente reservado',
    puede_ver_expediente($u_coord, 'coordinacion_edheg', $exp_reserva) === true);
chequear('quien está asignado accede',
    puede_ver_expediente($u_instructor, 'instructor', $exp_reserva) === true);
chequear('quien NO está asignado no accede',
    puede_ver_expediente($u_psico, 'psicologo', $exp_reserva) === false);

// El decisor no puede ver el expediente antes de la formulación de cargos
chequear('el decisor NO accede antes de la formulación de cargos',
    puede_ver_expediente($u_decisor, 'decisor', $exp_reserva) === false);

asignar_actuacion($exp_cargos, $u_decisor, 'juzgamiento', $u_coord);
chequear('el decisor SÍ accede una vez formulados los cargos',
    puede_ver_expediente($u_decisor, 'decisor', $exp_cargos) === true);

chequear('el rol administrador NO accede a expedientes',
    puede_ver_expediente($u_admin, 'administrador', $exp_reserva) === false);
chequear('el rol reportes NO accede a expedientes',
    puede_ver_expediente($u_reportes, 'reportes', $exp_reserva) === false);
chequear('un rol inexistente no accede (falla cerrada)',
    puede_ver_expediente($u_coord, 'rol_inventado', $exp_reserva) === false);
chequear('un expediente inexistente no concede acceso',
    puede_ver_expediente($u_coord, 'coordinacion_edheg', 999999) === false);

// H-18: el listado ya no muestra todo a cualquiera
$r_coord  = restriccion_listado_expedientes($u_coord, 'coordinacion_edheg');
$r_psico  = restriccion_listado_expedientes($u_psico, 'psicologo');
$r_unidad = restriccion_listado_expedientes($u_unidad, 'unidad_academica');

$contar = function ($restriccion) {
    $fila = db_select_one("SELECT COUNT(*) AS t FROM denuncias d WHERE {$restriccion['sql']}",
        $restriccion['params']);
    return (int)($fila['t'] ?? -1);
};

chequear('la coordinación lista expedientes', $contar($r_coord) > 0);
chequear('quien no tiene asignaciones no lista ninguno', $contar($r_psico) === 0);
chequear('la unidad académica no lista ninguno (RN-08)', $contar($r_unidad) === 0);
chequear('el listado de quien instruye se limita a lo asignado',
    $contar(restriccion_listado_expedientes($u_instructor, 'instructor')) === 1);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-08 · Aislamiento de la unidad académica');

foreach (['descripcion', 'testigos', 'agresor_nombre', 'denunciante_nombre',
          'tipo_violencia', 'lugar_hechos'] as $campo) {
    chequear("unidad_academica no ve `$campo`",
        puede_ver_campo($u_unidad, 'unidad_academica', $exp_reserva, $campo) === false);
}
chequear('unidad_academica no ve ni el radicado del expediente',
    puede_ver_campo($u_unidad, 'unidad_academica', $exp_reserva, 'codigo') === false);
chequear('sus órdenes de medida están vacías hasta la Fase 5',
    ordenes_medida_para_unidad($u_unidad, 1) === []);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Lista blanca de campos por rol');

chequear('quien instruye ve el relato',
    puede_ver_campo($u_instructor, 'instructor', $exp_reserva, 'descripcion') === true);
chequear('quien instruye ve la identidad de las partes',
    puede_ver_campo($u_instructor, 'instructor', $exp_reserva, 'denunciante_nombre') === true);
chequear('un campo desconocido se deniega (falla cerrada)',
    puede_ver_campo($u_instructor, 'instructor', $exp_reserva, 'campo_que_no_existe') === false);

$fila_completa = db_select_one('SELECT * FROM denuncias WHERE id = ?', [$exp_reserva]);
$filtrada_unidad = filtrar_expediente($fila_completa, $u_unidad, 'unidad_academica', $exp_reserva);
chequear('filtrar_expediente() deja vacío para unidad_academica', $filtrada_unidad === []);

$filtrada_instructor = filtrar_expediente($fila_completa, $u_instructor, 'instructor', $exp_reserva);
chequear('filtrar_expediente() conserva el relato para quien instruye',
    isset($filtrada_instructor['descripcion']));

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-12 · Impedimentos');

chequear('sin declaración no hay impedimento',
    tiene_impedimento($u_instructor, $exp_cargos) === false);

chequear('la declaración se registra',
    declarar_impedimento($u_instructor, $exp_reserva, 'Parentesco con una de las partes') === true);
chequear('el impedimento se detecta',
    tiene_impedimento($u_instructor, $exp_reserva) === true);
chequear('el impedimento bloquea el acceso al expediente',
    puede_ver_expediente($u_instructor, 'instructor', $exp_reserva) === false);
chequear('el impedimento revoca las asignaciones activas',
    esta_asignado($u_instructor, $exp_reserva) === false);
chequear('el expediente desaparece de su listado',
    $contar(restriccion_listado_expedientes($u_instructor, 'instructor')) === 0);

$r5 = asignar_actuacion($exp_reserva, $u_instructor, 'instruccion', $u_coord);
chequear('no se puede reasignar a quien se declaró impedido', $r5['exito'] === false);

chequear('el bloqueo no afecta a otros expedientes',
    tiene_impedimento($u_instructor, $exp_cargos) === false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-07 · Alcance del quejoso');

$exp_afectada = crear_expediente('BV-PRUEBA-AFECTADA');
registrar_persona_caso($exp_afectada, 'denunciante', [
    'nombre' => 'Persona Afectada', 'correo' => 'afectada@pruebas.local',
    'es_mismo_denunciante' => 1,
]);

$exp_quejoso = crear_expediente('BV-PRUEBA-QUEJOSO');
registrar_persona_caso($exp_quejoso, 'denunciante', [
    'nombre' => 'Persona Quejosa', 'correo' => 'quejoso@pruebas.local',
    'es_mismo_denunciante' => 0,
]);
registrar_persona_caso($exp_quejoso, 'afectada', ['es_mismo_denunciante' => 0]);

chequear('se identifica a la persona afectada',
    alcance_consulta_publica($exp_afectada) === 'afectada');
chequear('se identifica al quejoso que no es la persona afectada',
    alcance_consulta_publica($exp_quejoso) === 'quejoso');

$exp_sin_personas = crear_expediente('BV-PRUEBA-SINPERSONAS');
chequear('sin datos se aplica el alcance restringido (falla cerrada)',
    alcance_consulta_publica($exp_sin_personas) === 'quejoso');

$vista_afectada = proyeccion_publica_caso($exp_afectada);
$vista_quejoso  = proyeccion_publica_caso($exp_quejoso);

chequear('la persona afectada ve el tipo de caso', isset($vista_afectada['tipo_violencia']));
chequear('la persona afectada ve el historial', isset($vista_afectada['seguimientos']));
chequear('el quejoso NO ve el tipo de violencia', !isset($vista_quejoso['tipo_violencia']));
chequear('el quejoso NO ve el lugar de los hechos', !isset($vista_quejoso['lugar_hechos']));
chequear('el quejoso NO ve la fecha de los hechos', !isset($vista_quejoso['fecha_hechos']));
chequear('el quejoso SÍ ve el estado del trámite', isset($vista_quejoso['estado']));
chequear('el quejoso SÍ ve la fecha de radicación', isset($vista_quejoso['created_at']));
chequear('ninguna proyección incluye el relato',
    !isset($vista_afectada['descripcion']) && !isset($vista_quejoso['descripcion']));

// ─────────────────────────────────────────────────────────────────────────────
seccion('ADR-04 · Competencia derivada de la persona denunciada');

$casos = [
    'estudiante_pregrado'          => 'disciplinaria_estudiantil',
    'estudiante_posgrado'          => 'disciplinaria_estudiantil',
    'docente_carrera'              => 'disciplinaria_docente',
    'docente_vinculacion_especial' => 'administrativa_especial',
    'administrativo'               => 'disciplinaria_administrativa',
    'trabajador_oficial'           => 'disciplinaria_administrativa',
    'contratista'                  => 'contractual',
    'tercerizado'                  => 'contractual',
    'externo'                      => 'traslado_externo',
    'desconocido'                  => 'sin_competencia',
];

foreach ($casos as $vinculacion => $via_esperada) {
    $exp = crear_expediente('BV-COMP-' . strtoupper(substr($vinculacion, 0, 8)));
    $comp = derivar_competencia($exp, $vinculacion, null);
    chequear("$vinculacion → $via_esperada", ($comp['via'] ?? null) === $via_esperada,
        $comp['via'] ?? 'sin resultado');
}

$exp_inventado = crear_expediente('BV-COMP-INVENT');
$comp_inv = derivar_competencia($exp_inventado, 'vinculacion_que_no_existe', null);
chequear('una vinculación no prevista NO se adivina: cae en sin_competencia',
    ($comp_inv['via'] ?? null) === 'sin_competencia');

$exp_est = crear_expediente('BV-COMP-UNIDAD');
$comp_est = derivar_competencia($exp_est, 'estudiante_pregrado', null);
chequear('toda competencia cita su fundamento normativo',
    !empty($comp_est['fundamento']) && strpos($comp_est['fundamento'], 'art') !== false,
    $comp_est['fundamento'] ?? '');
chequear('la competencia estudiantil sigue al proyecto curricular',
    MATRIZ_COMPETENCIA['estudiante_pregrado']['sigue_unidad'] === true);
chequear('la competencia docente NO sigue al proyecto curricular',
    MATRIZ_COMPETENCIA['docente_carrera']['sigue_unidad'] === false);

chequear('el ajuste manual exige motivación',
    ajustar_competencia($exp_est, ['via' => 'disciplinaria_docente'], $u_coord, '') === false);
chequear('el ajuste con motivación se acepta',
    ajustar_competencia($exp_est, ['via' => 'disciplinaria_docente'], $u_coord, 'Se verificó que la persona es docente') === true);

$ajustada = obtener_competencia($exp_est);
chequear('el ajuste queda marcado como no automático',
    (int)$ajustada['derivada_automatica'] === 0);
chequear('el ajuste conserva la motivación', !empty($ajustada['motivacion_ajuste']));

// ─────────────────────────────────────────────────────────────────────────────
seccion('Cifrado de las personas del caso');

$personas = personas_del_caso($exp_afectada, 'denunciante');
chequear('el nombre se recupera descifrado', ($personas[0]['nombre'] ?? null) === 'Persona Afectada');
chequear('no se exponen los campos cifrados', !isset($personas[0]['nombre_cifrado']));

$en_bd = db_select_one('SELECT nombre_cifrado FROM personas_caso WHERE denuncia_id = ? LIMIT 1',
    [$exp_afectada]);
chequear('en la base de datos el nombre está cifrado',
    strpos((string)$en_bd['nombre_cifrado'], 'Persona Afectada') === false);

chequear('el índice ciego permite localizar casos por contacto',
    in_array($exp_afectada, casos_por_contacto('afectada@pruebas.local', 'correo')));
chequear('un contacto ajeno no localiza el caso',
    !in_array($exp_afectada, casos_por_contacto('nadie@pruebas.local', 'correo')));

// ─────────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
seccion('Limpieza · la suite no debe dejar rastro');

// Las cuentas y expedientes de prueba se eliminan. Dejarlos convierte el
// entorno en un sitio donde nadie distingue lo real de lo sintético.
db_execute("DELETE FROM usuarios WHERE email LIKE '%@pruebas.local'");
db_execute("DELETE FROM denuncias WHERE codigo LIKE 'BV-PRUEBA-%' OR codigo LIKE 'BV-COMP-%'");

chequear('las cuentas de prueba se eliminan',
    (int)db_select_one("SELECT COUNT(*) AS t FROM usuarios WHERE email LIKE '%@pruebas.local'")['t'] === 0);
chequear('los expedientes de prueba se eliminan',
    (int)db_select_one("SELECT COUNT(*) AS t FROM denuncias WHERE codigo LIKE 'BV-PRUEBA-%'")['t'] === 0);

echo "\n" . str_repeat('-', 60) . "\n";
echo ($fallidas === 0 ? 'TODAS LAS REGLAS SE CUMPLEN' : 'HAY REGLAS INCUMPLIDAS')
   . ": $correctas correctas, $fallidas fallidas\n";

exit($fallidas === 0 ? 0 : 1);
