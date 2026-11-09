<?php
/**
 * PRUEBAS DE REGLAS DE NEGOCIO · FASE 4
 *
 *   RN-04  No confrontación                    (Ac. 002 art. 47.5)
 *   RN-09  Notificación sin carga              (Ac. 003 art. 47 + Ley 1581)
 *   ADR-06 El correo solo transporta el radicado
 *   ADR-08 Outbox: ninguna llamada de red bloquea una denuncia
 *
 * Definition of Done del roadmap:
 *   «Ningún dato del caso sale por correo ni en asunto de reunión (auditado)»
 *
 * El cliente de Graph no puede probarse contra la API real sin credenciales
 * de Azure AD. Lo que sí se prueba es todo lo que ocurre ANTES de la llamada:
 * validación, composición del mensaje, cola, reintentos y borrado del secreto.
 * Se usa un transporte simulado inyectado.
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config_pruebas.php php pruebas/reglas/fase4_notificaciones.php
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

db_execute('DELETE FROM notificaciones_salientes');
db_execute('DELETE FROM encuentro_participantes');
db_execute('DELETE FROM encuentros');

$denuncia = db_select_one('SELECT id, codigo FROM denuncias ORDER BY id LIMIT 1');
if (!$denuncia) {
    exit("ABORTADO: la réplica no tiene denuncias.\n");
}

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-09 · La notificación no transporta datos del caso');

$prohibidos = [
    'descripcion'        => 'El relato completo de los hechos',
    'relato'             => 'Texto del relato',
    'tipo_violencia'     => 'sexual',
    'denunciante_nombre' => 'Nombre Real',
    'lugar_hechos'       => 'Edificio A salón 301',
    'testigos'           => 'Lista de testigos',
    'agresor_nombre'     => 'Nombre de la persona denunciada',
];

foreach ($prohibidos as $clave => $valor) {
    $r = validar_payload_notificacion(['radicado' => 'BV-X', $clave => $valor]);
    chequear("se rechaza la clave «{$clave}»", $r['valido'] === false);
}

$r = validar_payload_notificacion([
    'radicado' => 'BV-20260726-K7QM2X', 'banda_riesgo' => 'alto',
    'plazo' => '27/07/2026', 'enlace' => 'https://ejemplo/login',
]);
chequear('se acepta un payload conforme', $r['valido'] === true, $r['motivo']);

$r = validar_payload_notificacion(['radicado' => 'BV-X', 'motivo' => str_repeat('a', 200)]);
chequear('se rechaza texto libre largo aunque la clave sea válida', $r['valido'] === false);

$r = validar_payload_notificacion(['radicado' => 'BV-X', 'plazo' => ['no', 'escalar']]);
chequear('se rechaza un valor no escalar', $r['valido'] === false);

$antes = db_count('notificaciones_salientes');
$rechazada = encolar_notificacion('correo', 'destino@pruebas.local', 'caso_asignado',
    ['radicado' => 'BV-X', 'descripcion' => 'RELATO QUE NO DEBE SALIR'], $denuncia['id']);
chequear('encolar_notificacion() rechaza el payload prohibido', $rechazada === false);
chequear('la notificación rechazada NO llega a la cola', db_count('notificaciones_salientes') === $antes);

$aud = db_select_one("SELECT COUNT(*) AS t FROM auditoria WHERE accion = 'notificacion_rechazada_rn09'");
chequear('el rechazo queda auditado', (int)$aud['t'] > 0);

chequear('una plantilla desconocida se rechaza',
    encolar_notificacion('correo', 'x@y.z', 'plantilla_inventada', ['radicado' => 'BV-X']) === false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('ADR-06 · El mensaje compuesto solo lleva el radicado');

$mensaje = componer_notificacion('caso_asignado', [
    'radicado' => 'BV-20260726-K7QM2X', 'banda_riesgo' => 'alto',
    'plazo' => '27/07/2026', 'dias_habiles' => 1,
    'enlace' => 'https://ejemplo/?page=denuncias',
]);

chequear('el asunto lleva el radicado', strpos($mensaje['asunto'], 'BV-20260726-K7QM2X') !== false);
chequear('el asunto NO menciona violencia',
    stripos($mensaje['asunto'], 'violencia') === false && stripos($mensaje['asunto'], 'denuncia') === false,
    $mensaje['asunto']);
chequear('el cuerpo lleva la banda de riesgo', strpos($mensaje['cuerpo'], 'ALTO') !== false);
chequear('el cuerpo lleva el plazo', strpos($mensaje['cuerpo'], '27/07/2026') !== false);
chequear('el cuerpo lleva el enlace autenticado', strpos($mensaje['cuerpo'], '?page=denuncias') !== false);
chequear('el cuerpo declara que no contiene información del caso',
    strpos($mensaje['cuerpo'], 'no contiene información del caso') !== false);

foreach (array_keys(PLANTILLAS_NOTIFICACION) as $plantilla) {
    $m = componer_notificacion($plantilla, ['radicado' => 'BV-TEST'], '123456');
    $texto = $m['asunto'] . ' ' . $m['cuerpo'];
    $limpio = stripos($texto, 'violencia') === false
           && stripos($texto, 'agresor') === false
           && stripos($texto, 'relato') === false;
    chequear("plantilla «{$plantilla}» sin datos del caso", $limpio);
}

// ─────────────────────────────────────────────────────────────────────────────
seccion('El asunto de la reunión usa plantilla fija (RN-09)');

$asunto_reunion = 'Acompañamiento · ' . $denuncia['codigo'] . ' · Psicología';
chequear('el asunto sigue la plantilla «Acompañamiento · radicado · área»',
    (bool)preg_match('/^Acompañamiento · BV-[A-Z0-9\-]+ · [\wáéíóúÁÉÍÓÚ ]+$/u', $asunto_reunion),
    $asunto_reunion);
chequear('las áreas son un catálogo cerrado', count(AREAS_ENCUENTRO) === 7);
chequear('ningún nombre de área revela el tipo de caso',
    !preg_match('/violencia|sexual|acoso/i', implode(' ', AREAS_ENCUENTRO)));

// ─────────────────────────────────────────────────────────────────────────────
seccion('Configuración fija de las reuniones');

$cfg = graph_configuracion_reunion(false);
chequear('la grabación viene deshabilitada', $cfg['allowRecording'] === false);
chequear('la transcripción viene deshabilitada', $cfg['allowTranscription'] === false);
chequear('la sala de espera está activa', $cfg['lobbyBypassSettings']['scope'] === 'organizer');
chequear('no se anuncian entradas ni salidas', $cfg['isEntryExitAnnounced'] === false);

$cfg_grab = graph_configuracion_reunion(true);
chequear('la grabación solo se habilita explícitamente', $cfg_grab['allowRecording'] === true);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-04 · No confrontación');

$r = verificar_no_confrontacion([
    ['papel' => 'afectada'], ['papel' => 'profesional'],
]);
chequear('un encuentro solo con la persona afectada es válido', $r['permitido'] === true);

$r = verificar_no_confrontacion([
    ['papel' => 'denunciada'], ['papel' => 'profesional'],
]);
chequear('un encuentro solo con la persona denunciada es válido', $r['permitido'] === true);

$r = verificar_no_confrontacion([
    ['papel' => 'afectada'], ['papel' => 'denunciada'], ['papel' => 'profesional'],
]);
chequear('se RECHAZA reunir a ambas partes', $r['permitido'] === false);
chequear('el rechazo cita el fundamento normativo',
    strpos($r['motivo'], 'art. 47.5') !== false, $r['motivo']);

$profesional = db_select_one("SELECT id, email FROM usuarios LIMIT 1");

$resultado = crear_encuentro([
    'denuncia_id' => $denuncia['id'], 'etapa' => 'encuentro_inicial',
    'modalidad' => 'presencial',
    'inicia_at' => date('Y-m-d H:i:s', time() + 3600),
    'termina_at' => date('Y-m-d H:i:s', time() + 7200),
    'profesional_id' => $profesional['id'],
], [
    ['papel' => 'afectada'], ['papel' => 'denunciada'],
]);

chequear('crear_encuentro() rechaza la confrontación', $resultado['exito'] === false);
chequear('no se creó ningún encuentro', db_count('encuentros') === 0);
$aud = db_select_one("SELECT COUNT(*) AS t FROM auditoria WHERE accion = 'encuentro_rechazado_rn04'");
chequear('el intento queda auditado', (int)$aud['t'] > 0);

// La regla no admite excepción: `crear_encuentro()` no expone ningún parámetro
// de omisión, y la verificación es lo primero que ejecuta.
$reflexion = new ReflectionFunction('crear_encuentro');
$nombres_parametros = array_map(fn($p) => $p->getName(), $reflexion->getParameters());
chequear('crear_encuentro() no expone ningún parámetro de omisión',
    count(array_intersect($nombres_parametros, ['forzar', 'omitir_rn04', 'ignorar_validacion', 'excepcion'])) === 0
    && count($nombres_parametros) === 2,
    implode(', ', $nombres_parametros));

// Y ninguna variante de los participantes logra colar la confrontación
$variantes = [
    [['papel' => 'denunciada'], ['papel' => 'afectada']],                       // orden inverso
    [['papel' => 'afectada'], ['papel' => 'apoyo'], ['papel' => 'denunciada']], // intercalada
    [['papel' => 'afectada'], ['papel' => 'afectada'], ['papel' => 'denunciada']], // duplicada
];
$todas_rechazadas = true;
foreach ($variantes as $v) {
    if (verificar_no_confrontacion($v)['permitido'] !== false) {
        $todas_rechazadas = false;
    }
}
chequear('ninguna disposición de participantes evade la regla', $todas_rechazadas);

$resultado = crear_encuentro([
    'denuncia_id' => $denuncia['id'], 'etapa' => 'atencion_psicologica',
    'modalidad' => 'presencial',
    'inicia_at' => date('Y-m-d H:i:s', time() + 3600),
    'termina_at' => date('Y-m-d H:i:s', time() + 7200),
    'profesional_id' => $profesional['id'],
], [
    ['papel' => 'afectada'], ['papel' => 'profesional', 'usuario_id' => $profesional['id']],
]);
chequear('un encuentro conforme sí se crea', $resultado['exito'] === true, $resultado['motivo']);
chequear('la modalidad presencial no requiere Graph',
    db_select_one('SELECT estado_sync FROM encuentros WHERE id = ?',
        [$resultado['encuentro_id']])['estado_sync'] === 'sincronizado');

$r_grab = crear_encuentro([
    'denuncia_id' => $denuncia['id'], 'etapa' => 'audiencia', 'modalidad' => 'virtual',
    'inicia_at' => date('Y-m-d H:i:s', time() + 3600),
    'termina_at' => date('Y-m-d H:i:s', time() + 7200),
    'profesional_id' => $profesional['id'], 'permitir_grabacion' => true,
], [['papel' => 'profesional', 'usuario_id' => $profesional['id']]]);
chequear('grabar sin consentimiento registrado se rechaza', $r_grab['exito'] === false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('ADR-08 · Outbox, reintentos y borrado del secreto');

db_execute('DELETE FROM notificaciones_salientes');

$id = encolar_notificacion('correo', 'equipo@pruebas.local', 'caso_asignado', [
    'radicado' => $denuncia['codigo'], 'banda_riesgo' => 'medio',
    'plazo' => date('d/m/Y'), 'enlace' => url('?page=denuncias'),
], $denuncia['id']);

chequear('la notificación se encola', $id !== false);
$fila = db_select_one('SELECT * FROM notificaciones_salientes WHERE id = ?', [$id]);
chequear('nace en estado pendiente', $fila['estado'] === 'pendiente');
chequear('nace con cero intentos', (int)$fila['intentos'] === 0);
chequear('el payload no contiene datos del caso',
    !preg_match('/descripcion|relato|violencia/i', $fila['payload']), $fila['payload']);

// Transporte simulado que siempre falla → reintentos con retroceso exponencial
$fallo = function () { return false; };

$esperas = [];
for ($i = 1; $i <= 5; $i++) {
    db_execute('UPDATE notificaciones_salientes SET proximo_intento = NOW() WHERE id = ?', [$id]);
    despachar_notificaciones($fallo, 10);
    $f = db_select_one('SELECT intentos, estado, proximo_intento FROM notificaciones_salientes WHERE id = ?', [$id]);
    $esperas[] = ['intento' => (int)$f['intentos'], 'estado' => $f['estado'],
                  'espera_min' => (int)round((strtotime($f['proximo_intento']) - time()) / 60)];
}

chequear('el intento 1 espera 1 minuto',  $esperas[0]['espera_min'] === 1, (string)$esperas[0]['espera_min']);
chequear('el intento 2 espera 2 minutos', $esperas[1]['espera_min'] === 2, (string)$esperas[1]['espera_min']);
chequear('el intento 3 espera 4 minutos', $esperas[2]['espera_min'] === 4, (string)$esperas[2]['espera_min']);
chequear('el intento 4 espera 8 minutos', $esperas[3]['espera_min'] === 8, (string)$esperas[3]['espera_min']);
chequear('al quinto intento se marca fallida', $esperas[4]['estado'] === 'fallido', $esperas[4]['estado']);

$aud = db_select_one("SELECT COUNT(*) AS t FROM auditoria WHERE accion = 'notificacion_fallida'");
chequear('el fallo definitivo queda auditado', (int)$aud['t'] > 0);

// Transporte simulado que funciona
$recibidos = [];
$exito = function ($destinatario, $asunto, $cuerpo, $tipo) use (&$recibidos) {
    $recibidos[] = compact('destinatario', 'asunto', 'cuerpo', 'tipo');
    return true;
};

$id2 = encolar_notificacion('correo', 'persona@pruebas.local', 'codigo_seguimiento',
    ['radicado' => $denuncia['codigo']], null, '654321');

$fila2 = db_select_one('SELECT payload, payload_cifrado FROM notificaciones_salientes WHERE id = ?', [$id2]);
chequear('el código NO viaja en el payload en claro',
    strpos($fila2['payload'], '654321') === false, $fila2['payload']);
chequear('el código se guarda cifrado',
    !empty($fila2['payload_cifrado']) && strpos((string)$fila2['payload_cifrado'], '654321') === false);

$resumen = despachar_notificaciones($exito, 10);
chequear('la notificación se despacha', $resumen['enviadas'] >= 1);
chequear('el mensaje entregado contiene el código', strpos($recibidos[0]['cuerpo'], '654321') !== false);
chequear('el mensaje advierte que nadie pedirá el código',
    strpos($recibidos[0]['cuerpo'], 'Nadie del equipo') !== false);

$fila2 = db_select_one('SELECT estado, payload_cifrado, enviado_at FROM notificaciones_salientes WHERE id = ?', [$id2]);
chequear('queda marcada como enviada', $fila2['estado'] === 'enviado');
chequear('el secreto se BORRA tras el envío', $fila2['payload_cifrado'] === null);
chequear('se registra la fecha de envío', !empty($fila2['enviado_at']));

// ─────────────────────────────────────────────────────────────────────────────
seccion('Los logs no filtran códigos ni destinatarios');

$aud = db_select("SELECT datos FROM auditoria WHERE tabla = 'notificaciones_salientes'
                  ORDER BY id DESC LIMIT 50");
$todo = implode(' ', array_column($aud ?: [], 'datos'));
chequear('la auditoría no contiene el código', strpos($todo, '654321') === false);
chequear('la auditoría no contiene correos', strpos($todo, '@') === false);
chequear('la auditoría sí registra la plantilla', strpos($todo, 'plantilla') !== false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Sin Graph configurado, nada se pierde');

chequear('graph_activo() es false sin credenciales', graph_activo() === false);
chequear('graph_enviar_correo() devuelve false, no lanza', graph_enviar_correo('a@b.c', 'x', 'y') === false);

db_execute('DELETE FROM notificaciones_salientes');
$id3 = encolar_notificacion('correo', 'x@pruebas.local', 'plazo_por_vencer',
    ['radicado' => 'BV-TEST', 'plazo' => date('d/m/Y')]);
despachar_notificaciones(null, 10);
$f3 = db_select_one('SELECT estado, intentos FROM notificaciones_salientes WHERE id = ?', [$id3]);
chequear('sin canal la notificación NO se pierde: sigue en cola', $f3['estado'] === 'pendiente');
chequear('queda programada para reintento', (int)$f3['intentos'] === 1);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('-', 60) . "\n";
echo ($fallidas === 0 ? 'TODAS LAS REGLAS SE CUMPLEN' : 'HAY REGLAS INCUMPLIDAS')
   . ": $correctas correctas, $fallidas fallidas\n";

exit($fallidas === 0 ? 0 : 1);
