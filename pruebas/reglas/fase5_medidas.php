<?php
/**
 * PRUEBAS DE REGLAS DE NEGOCIO · FASE 5
 *
 *   RN-05  Prohibición de contacto        (Ac. 002 art. 26 par. lit. h)
 *   RN-06  Consentimiento previo          (Ac. 002 arts. 22, 25 lit. a, 26)
 *
 * Más: máquina de estados del art. 25, catálogo de medidas, motor de plazos en
 * días hábiles con festivos colombianos, e instrumento de riesgo versionado.
 *
 * Definition of Done del roadmap:
 *   «Las 7 medidas del art. 24 y todas las de los arts. 26-29 registrables
 *    de extremo a extremo»
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config_pruebas.php php pruebas/reglas/fase5_medidas.php
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

function chequear(string $n, bool $c, string $d = ''): void {
    global $correctas, $fallidas;
    if ($c) { $correctas++; echo "  [OK]    $n\n"; }
    else { $fallidas++; echo "  [FALLO] $n" . ($d !== '' ? "  ($d)" : '') . "\n"; }
}
function seccion(string $t): void { echo "\n=== $t ===\n"; }

db_execute('DELETE FROM medida_transiciones');
db_execute('DELETE FROM medidas');
db_execute('DELETE FROM consentimientos');
db_execute('DELETE FROM valoraciones_riesgo');
db_execute('DELETE FROM instrumento_items');
db_execute('DELETE FROM instrumentos_riesgo');
db_execute('DELETE FROM festivos');

$denuncia = db_select_one('SELECT id, codigo FROM denuncias ORDER BY id LIMIT 1');
$usuario  = db_select_one('SELECT id FROM usuarios LIMIT 1');

if (!$denuncia || !$usuario) {
    exit("ABORTADO: la réplica necesita al menos una denuncia y un usuario.\n");
}

// ─────────────────────────────────────────────────────────────────────────────
seccion('Motor de plazos · festivos colombianos (Ley 51 de 1983)');

$f2026 = festivos_colombia(2026);

chequear('Año Nuevo es festivo', isset($f2026['2026-01-01']));
chequear('Día del Trabajo es festivo', isset($f2026['2026-05-01']));
chequear('Independencia (20 de julio) es festivo', isset($f2026['2026-07-20']));
chequear('Batalla de Boyacá (7 de agosto) es festivo', isset($f2026['2026-08-07']));
chequear('Navidad es festivo', isset($f2026['2026-12-25']));
chequear('hay 18 festivos en el año', count($f2026) === 18, (string)count($f2026));

// Los trasladables SIEMPRE caen en lunes
$trasladables = ['Reyes Magos', 'San José', 'San Pedro y San Pablo', 'Asunción de la Virgen',
                 'Día de la Raza', 'Todos los Santos', 'Independencia de Cartagena',
                 'Ascensión del Señor', 'Corpus Christi', 'Sagrado Corazón de Jesús'];
$todos_lunes = true;
foreach ($f2026 as $fecha => $nombre) {
    if (in_array($nombre, $trasladables, true) && (int)date('N', strtotime($fecha)) !== 1) {
        $todos_lunes = false;
    }
}
chequear('los festivos trasladables caen en lunes', $todos_lunes);

// Semana Santa: Jueves y Viernes Santo NO se trasladan
$jueves = array_search('Jueves Santo', $f2026, true);
$viernes = array_search('Viernes Santo', $f2026, true);
chequear('Jueves Santo cae en jueves', (int)date('N', strtotime($jueves)) === 4);
chequear('Viernes Santo cae en viernes', (int)date('N', strtotime($viernes)) === 5);

// Contraste con una fecha conocida: Pascua 2026 fue el 5 de abril
chequear('la Pascua de 2026 se calcula bien', pascua(2026) === '2026-04-05', pascua(2026));
chequear('la Pascua de 2025 se calcula bien', pascua(2025) === '2025-04-20', pascua(2025));

seccion('Motor de plazos · días hábiles');

chequear('un sábado no es hábil', es_dia_habil('2026-07-25') === false);
chequear('un domingo no es hábil', es_dia_habil('2026-07-26') === false);
chequear('un lunes común sí es hábil', es_dia_habil('2026-07-27') === true);
chequear('el 20 de julio no es hábil', es_dia_habil('2026-07-20') === false);

// Del viernes, 1 día hábil es el lunes
chequear('viernes + 1 día hábil = lunes',
    sumar_dias_habiles('2026-07-24', 1) === '2026-07-27', sumar_dias_habiles('2026-07-24', 1));
// 5 días hábiles desde el lunes 27 → lunes 3 de agosto
chequear('lunes + 5 días hábiles = lunes siguiente',
    sumar_dias_habiles('2026-07-27', 5) === '2026-08-03', sumar_dias_habiles('2026-07-27', 5));
// Un festivo intermedio corre el plazo
chequear('un festivo intermedio corre la fecha límite',
    sumar_dias_habiles('2026-08-06', 1) === '2026-08-10', sumar_dias_habiles('2026-08-06', 1));

chequear('los días hábiles entre lunes y viernes son 4',
    dias_habiles_entre('2026-07-27', '2026-07-31') === 4,
    (string)dias_habiles_entre('2026-07-27', '2026-07-31'));

// Festivo institucional cargado a mano
db_insert_simple('festivos', ['fecha' => '2026-09-15', 'nombre' => 'Receso institucional',
                              'origen' => 'institucional']);
chequear('un festivo institucional se respeta', es_dia_habil('2026-09-15') === false);

seccion('Catálogo de plazos normativos');

$plazos = db_select('SELECT * FROM catalogo_plazos ORDER BY codigo') ?: [];
chequear('hay 12 plazos sembrados', count($plazos) === 12, (string)count($plazos));
chequear('todos citan su fundamento',
    count(array_filter($plazos, fn($p) => trim($p['fundamento']) !== '')) === count($plazos));
chequear('el direccionamiento de la OAAD es de 5 días hábiles',
    (int)db_select_one("SELECT cantidad FROM catalogo_plazos WHERE codigo='DIRECCIONAMIENTO_OAAD'")['cantidad'] === 5);
chequear('la indagación previa es de 6 meses',
    db_select_one("SELECT unidad FROM catalogo_plazos WHERE codigo='INDAGACION_PREVIA'")['unidad'] === 'meses');
chequear('calcular_fecha_limite() usa días hábiles',
    calcular_fecha_limite('DIRECCIONAMIENTO_OAAD', '2026-07-27') === '2026-08-03',
    (string)calcular_fecha_limite('DIRECCIONAMIENTO_OAAD', '2026-07-27'));
chequear('un plazo inexistente devuelve null', calcular_fecha_limite('NO_EXISTE') === null);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Definition of Done · catálogo de medidas de los Acuerdos');

$art24 = db_select("SELECT * FROM catalogo_medidas WHERE fundamento LIKE '%art. 24%'") ?: [];
chequear('están las 7 medidas generales del art. 24', count($art24) === 7, (string)count($art24));

// Nota: «art. 26» y «art. 26 par.» son artículos distintos y el LIKE del
// primero captura al segundo. Se excluye explícitamente.
$n = (int)db_select_one(
    "SELECT COUNT(*) AS t FROM catalogo_medidas
      WHERE fundamento LIKE '%art. 26%' AND fundamento NOT LIKE '%art. 26 par%'")['t'];
chequear('medidas del art. 26 (estudiante víctima): 4', $n === 4, (string)$n);

$conteos = [
    'art. 26 par.' => 7,  // persona denunciada docente
    'art. 28'      => 3,  // docente víctima
    'art. 29'      => 2,  // administrativos y contratistas
];
foreach ($conteos as $art => $esperado) {
    $n = (int)db_select_one("SELECT COUNT(*) AS t FROM catalogo_medidas WHERE fundamento LIKE ?",
        ['%' . $art . '%'])['t'];
    chequear("medidas del $art: $esperado", $n === $esperado, (string)$n);
}

// El total del catálogo debe cuadrar con la suma de los artículos sembrados
chequear('el catálogo tiene 25 medidas (7+4+7+3+2+1+1)',
    db_count('catalogo_medidas') === 25, (string)db_count('catalogo_medidas'));

$sin_fundamento = (int)db_select_one(
    "SELECT COUNT(*) AS t FROM catalogo_medidas WHERE fundamento NOT LIKE '%art%'")['t'];
chequear('ninguna medida carece de fundamento normativo', $sin_fundamento === 0);

chequear('existe la prohibición de contacto del art. 26 par. lit. h',
    db_select_one("SELECT id FROM catalogo_medidas WHERE codigo='A26P_PROHIBICION_CONTACTO'") !== false);
chequear('el catálogo filtra por vinculación',
    count(catalogo_aplicable('estudiante_pregrado')) > count(catalogo_aplicable('externo')));

// ─────────────────────────────────────────────────────────────────────────────
seccion('Máquina de estados del art. 25');

$medida_cat = db_select_one("SELECT id FROM catalogo_medidas WHERE codigo='A26_CAMBIO_GRUPO'");

$r = solicitar_medida([
    'denuncia_id' => $denuncia['id'], 'catalogo_id' => $medida_cat['id'],
    'solicitada_por' => 'victima',
], $usuario['id']);
chequear('se solicita una medida', $r['exito'] === true, $r['motivo']);
$medida_id = $r['medida_id'];

$m = db_select_one('SELECT * FROM medidas WHERE id = ?', [$medida_id]);
chequear('nace en estado solicitada', $m['estado'] === 'solicitada');
chequear('la fecha límite se calcula en días hábiles', !empty($m['fecha_limite']));
chequear('la solicitud queda en el historial',
    db_count('medida_transiciones', ['medida_id' => $medida_id]) === 1);

// Saltos prohibidos
$r = cambiar_estado_medida($medida_id, 'implementada', $usuario['id'], 'x');
chequear('NO se puede saltar de solicitada a implementada', $r['exito'] === false, $r['motivo']);

$r = cambiar_estado_medida($medida_id, 'notificada', $usuario['id']);
chequear('solicitada → notificada es válida', $r['exito'] === true, $r['motivo']);

// ── RN-06 ────────────────────────────────────────────────────────────────
$r = cambiar_estado_medida($medida_id, 'en_ejecucion', $usuario['id']);
chequear('RN-06: sin consentimiento NO pasa a ejecución', $r['exito'] === false);
chequear('el rechazo cita el fundamento', strpos($r['motivo'], 'art') !== false, $r['motivo']);

$aud = db_select_one("SELECT COUNT(*) AS t FROM auditoria WHERE accion='medida_bloqueada_rn06'");
chequear('el bloqueo por RN-06 queda auditado', (int)$aud['t'] > 0);

$consent = registrar_consentimiento($denuncia['id'], 'medida_especifica',
    'Se explicó que se cambiará de grupo sin afectación académica y que puede retirarlo cuando quiera.',
    true, 'presencial', $usuario['id'], $medida_id);
chequear('se registra el consentimiento', $consent !== false);
chequear('un consentimiento sin alcance explicado se rechaza',
    registrar_consentimiento($denuncia['id'], 'medida_especifica', '   ', true, 'presencial', $usuario['id']) === false);

db_update_simple('medidas', ['consentimiento_id' => $consent], ['id' => $medida_id]);

$r = cambiar_estado_medida($medida_id, 'en_ejecucion', $usuario['id']);
chequear('RN-06: con consentimiento SÍ pasa a ejecución', $r['exito'] === true, $r['motivo']);

// Revocación
chequear('el consentimiento se puede revocar',
    revocar_consentimiento($consent, 'La persona cambió de opinión', $usuario['id']) === true);
chequear('un consentimiento revocado deja de ser vigente', consentimiento_vigente($consent) === false);

// Implementación
$r = cambiar_estado_medida($medida_id, 'implementada', $usuario['id'], 'Cambio de grupo aplicado el 27/07.');
chequear('en_ejecucion → implementada es válida', $r['exito'] === true, $r['motivo']);

$m = db_select_one('SELECT * FROM medidas WHERE id = ?', [$medida_id]);
chequear('se registra la fecha de implementación', !empty($m['fecha_implementacion']));
chequear('se guarda la constancia', !empty($m['constancia_texto']));
chequear('se programa la revisión trimestral a 3 meses',
    $m['proxima_revision'] === date('Y-m-d', strtotime('+3 months')), (string)$m['proxima_revision']);

// Levantamiento motivado (art. 30)
$r = cambiar_estado_medida($medida_id, 'levantada', $usuario['id'], '');
chequear('art. 30: levantar sin motivación se rechaza', $r['exito'] === false);

$r = cambiar_estado_medida($medida_id, 'levantada', $usuario['id'],
    'La persona culminó el semestre y solicitó el levantamiento.');
chequear('art. 30: levantar con motivación se acepta', $r['exito'] === true, $r['motivo']);

$m = db_select_one('SELECT * FROM medidas WHERE id = ?', [$medida_id]);
chequear('se conserva el motivo del levantamiento', !empty($m['motivo_levantamiento']));
chequear('se cancela la revisión trimestral', $m['proxima_revision'] === null);
chequear('una medida levantada es estado final',
    cambiar_estado_medida($medida_id, 'en_ejecucion', $usuario['id'])['exito'] === false);

chequear('todas las transiciones quedan en el historial',
    db_count('medida_transiciones', ['medida_id' => $medida_id]) === 5,
    (string)db_count('medida_transiciones', ['medida_id' => $medida_id]));

// Medida de oficio
$r = solicitar_medida([
    'denuncia_id' => $denuncia['id'], 'catalogo_id' => $medida_cat['id'],
    'solicitada_por' => 'oficio',
], $usuario['id']);
chequear('una medida de oficio sin motivación se rechaza', $r['exito'] === false);

$r = solicitar_medida([
    'denuncia_id' => $denuncia['id'], 'catalogo_id' => $medida_cat['id'],
    'solicitada_por' => 'oficio',
    'motivacion_oficio' => 'Riesgo alto verificado; la persona no está en condiciones de decidir.',
], $usuario['id']);
chequear('una medida de oficio con motivación se acepta', $r['exito'] === true, $r['motivo']);

$oficio_id = $r['medida_id'];
cambiar_estado_medida($oficio_id, 'notificada', $usuario['id']);
$r = cambiar_estado_medida($oficio_id, 'en_ejecucion', $usuario['id']);
chequear('RN-06: la medida de oficio no exige consentimiento', $r['exito'] === true, $r['motivo']);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-05 · Prohibición de contacto');

$prohibicion_cat = db_select_one("SELECT id FROM catalogo_medidas WHERE codigo='A26P_PROHIBICION_CONTACTO'");

chequear('sin medida activa no hay prohibición',
    hay_prohibicion_contacto_activa($denuncia['id']) === false);

$r = solicitar_medida([
    'denuncia_id' => $denuncia['id'], 'catalogo_id' => $prohibicion_cat['id'],
    'solicitada_por' => 'equipo',
], $usuario['id']);
$prohibicion_id = $r['medida_id'];
cambiar_estado_medida($prohibicion_id, 'notificada', $usuario['id']);

chequear('con la medida notificada la prohibición está activa',
    hay_prohibicion_contacto_activa($denuncia['id']) === true);

$r = verificar_prohibicion_contacto($denuncia['id'], [
    ['papel' => 'afectada'], ['papel' => 'denunciada'],
]);
chequear('RN-05 impide un espacio compartido entre las partes', $r['permitido'] === false);
chequear('el rechazo cita el fundamento', strpos($r['motivo'], 'lit. h') !== false, $r['motivo']);

$r = verificar_prohibicion_contacto($denuncia['id'], [
    ['papel' => 'afectada'], ['papel' => 'profesional'],
]);
chequear('un encuentro solo con la persona afectada sigue siendo posible', $r['permitido'] === true);

cambiar_estado_medida($prohibicion_id, 'rechazada', $usuario['id'], 'Prueba: se retira la medida.');
chequear('retirada la medida, cesa la prohibición',
    hay_prohibicion_contacto_activa($denuncia['id']) === false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Instrumento de riesgo · art. 23');

chequear('no hay instrumento vigente (lo define Bienestar)',
    instrumento_riesgo_vigente() === null);

$r = registrar_valoracion_riesgo($denuncia['id'], ['X' => 1], $usuario['id']);
chequear('sin instrumento no se puede valorar', $r['exito'] === false);
chequear('el mensaje señala a quién corresponde definirlo',
    strpos($r['motivo'], 'Bienestar') !== false, $r['motivo']);

// Instrumento SINTÉTICO, solo para probar la maquinaria.
// Los factores y pesos reales los define la Universidad.
$inst_id = db_insert_simple('instrumentos_riesgo', [
    'version' => 'PRUEBA-1', 'vigente' => 1, 'umbral_alto' => 7, 'umbral_medio' => 4,
    'aprobado_por' => 'Suite de pruebas', 'aprobado_at' => date('Y-m-d H:i:s'),
]);
foreach ([['F1', 5], ['F2', 3], ['F3', 2]] as $i => $item) {
    db_insert_simple('instrumento_items', [
        'instrumento_id' => $inst_id, 'codigo' => $item[0],
        'enunciado' => 'Factor sintético ' . $item[0], 'peso' => $item[1], 'orden' => $i,
    ]);
}

$c = calcular_riesgo($inst_id, ['F1' => 1, 'F2' => 1, 'F3' => 1]);
chequear('todo afirmativo da el puntaje máximo', (float)$c['puntaje'] === 10.0, (string)$c['puntaje']);
chequear('el puntaje máximo cae en banda alta', $c['banda'] === 'alto');

$c = calcular_riesgo($inst_id, ['F1' => 0, 'F2' => 0, 'F3' => 0]);
chequear('todo negativo da cero', (float)$c['puntaje'] === 0.0);
chequear('cero cae en banda moderada', $c['banda'] === 'moderado');

$c = calcular_riesgo($inst_id, ['F1' => 1, 'F2' => 0, 'F3' => 0]);
chequear('un puntaje intermedio cae en banda media', $c['banda'] === 'medio', $c['banda']);

// Renormalización: omitir no debe abaratar el riesgo
$c = calcular_riesgo($inst_id, ['F1' => 1]); // solo responde el factor de mayor peso
chequear('omitir ítems renormaliza en vez de castigar',
    (float)$c['puntaje'] === 10.0, (string)$c['puntaje']);
chequear('se registran los ítems omitidos', $c['items_omitidos'] === 2);

$r = registrar_valoracion_riesgo($denuncia['id'], ['F1' => 1, 'F2' => 1, 'F3' => 1], $usuario['id']);
chequear('se registra la valoración', $r['exito'] === true, $r['motivo']);
chequear('la banda vigente es la registrada', banda_riesgo_vigente($denuncia['id']) === 'alto');

// El puntaje no decide (art. 23)
$r = registrar_valoracion_riesgo($denuncia['id'], ['F1' => 1, 'F2' => 1, 'F3' => 1],
    $usuario['id'], 'moderado');
chequear('apartarse de la banda sin motivación se rechaza', $r['exito'] === false);

$r = registrar_valoracion_riesgo($denuncia['id'], ['F1' => 1, 'F2' => 1, 'F3' => 1],
    $usuario['id'], 'moderado', 'La situación de riesgo cesó: la persona denunciada ya no está en el país.');
chequear('apartarse CON motivación se acepta (el puntaje no decide)', $r['exito'] === true, $r['motivo']);

$v = db_select_one('SELECT * FROM valoraciones_riesgo WHERE id = ?', [$r['valoracion_id']]);
chequear('se conservan ambas bandas: sugerida y final',
    $v['banda_sugerida'] === 'alto' && $v['banda_final'] === 'moderado');
chequear('se conserva la motivación del apartamiento', !empty($v['motivacion_ajuste']));
chequear('hay serie histórica para medir avances o retrocesos',
    count(historico_riesgo($denuncia['id'])) >= 2);

// Versionado
db_update_simple('instrumentos_riesgo', ['vigente' => 0], ['id' => $inst_id]);
$inst2 = db_insert_simple('instrumentos_riesgo', [
    'version' => 'PRUEBA-2', 'vigente' => 1, 'umbral_alto' => 2, 'umbral_medio' => 1,
    'aprobado_por' => 'Suite', 'aprobado_at' => date('Y-m-d H:i:s'),
]);
chequear('cambiar de versión no reinterpreta lo ya valorado',
    (int)db_select_one('SELECT instrumento_id FROM valoraciones_riesgo WHERE id = ?',
        [$r['valoracion_id']])['instrumento_id'] === (int)$inst_id);
chequear('la nueva versión pasa a ser la vigente',
    (int)instrumento_riesgo_vigente()['id'] === (int)$inst2);

// ─────────────────────────────────────────────────────────────────────────────
seccion('La auditoría no filtra datos del caso');

$aud = db_select("SELECT datos FROM auditoria WHERE tabla IN ('medidas','consentimientos','valoraciones_riesgo')
                  ORDER BY id DESC LIMIT 60");
$todo = implode(' ', array_column($aud ?: [], 'datos'));
chequear('no contiene el texto del consentimiento', strpos($todo, 'Se explicó') === false);
chequear('no contiene motivaciones en texto libre', strpos($todo, 'cambió de opinión') === false);
chequear('no contiene las respuestas del instrumento', strpos($todo, 'F1') === false);
chequear('sí registra la banda de riesgo', strpos($todo, 'banda_riesgo') !== false);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('-', 60) . "\n";
echo ($fallidas === 0 ? 'TODAS LAS REGLAS SE CUMPLEN' : 'HAY REGLAS INCUMPLIDAS')
   . ": $correctas correctas, $fallidas fallidas\n";

exit($fallidas === 0 ? 0 : 1);
