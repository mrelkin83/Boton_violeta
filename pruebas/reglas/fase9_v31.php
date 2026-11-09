<?php
/**
 * PRUEBAS DE REGLAS DE NEGOCIO · v3.1 (FASES A–G)
 *
 *   RN-14  Decisión registrada sobre la Procuraduría   (Ac. 003 art. 3 n.13, 79)
 *   RN-15  Canal de notificación / doble correo        (Ac. 003 art. 43 · Ley 1581)
 *   RN-16  Compromisos restaurativos con firmeza       (Ac. 002 art. 44)
 *   RN-17  La sede no decide quién instruye            (Ac. 003 art. 23 · ADR-04)
 *   RN-18  Encuentro inicial sin confrontación         (Ac. 002 art. 47.5)
 *   RN-19  Etapa de ruta con evidencia                 (Res. 027/2023 art. 9)
 *   RN-20  Menores de edad                             (Protocolo E1)
 *   RN-21  Queja anónima con mérito                    (Ac. 003 art. 5 · Res. 142/2026)
 *
 * Más: H-23 (estado derivado de la etapa), máquina de estados de compromisos,
 * aislamiento de `administrador` y `comision_seguimiento`, menú por rol.
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config.php php pruebas/reglas/fase9_v31.php
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

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures propios: esta suite no depende de los datos sintéticos y limpia
// todo lo que crea.
// ─────────────────────────────────────────────────────────────────────────────

$coordinador = db_select_one(
    "SELECT id, rol FROM usuarios WHERE rol IN ('coordinacion_edheg','coordinador') AND activo = 1 LIMIT 1");
if (!$coordinador) {
    exit("ABORTADO: se necesita una cuenta de coordinación.\n");
}
$coordinador_id = (int)$coordinador['id'];

$sede_id = db_insert_simple('unidades', [
    'tipo' => 'sede', 'codigo' => 'TEST-F9-SEDE', 'nombre' => '[PRUEBA F9] Sede', 'activa' => 1,
]);

function crear_denuncia_prueba(string $sufijo, array $extra = []): int {
    $id = db_insert_simple('denuncias', array_merge([
        'codigo'         => 'BV-TEST9-' . strtoupper($sufijo) . random_int(100, 999),
        'tipo_violencia' => 'psicologica',
        'descripcion'    => '[PRUEBA F9] Relato sintético para la suite v3.1. No corresponde a ninguna persona real.',
        'fecha_hechos'   => date('Y-m-d'),
        'lugar_hechos'   => '[PRUEBA F9]',
        'estado'         => 'nueva',
        'etapa_procesal' => 'recepcion',
        'es_anonima'     => 0,
        'canal_preferido'=> 'personal',
        'created_at'     => date('Y-m-d H:i:s'),
    ], $extra));

    if (!$id) { exit("ABORTADO: no se pudo crear la denuncia de prueba.\n"); }
    return (int)$id;
}

// ─────────────────────────────────────────────────────────────────────────────
seccion('H-23 · el estado se deriva de la etapa procesal');

chequear('recepcion → nueva', estado_desde_etapa('recepcion') === 'nueva');
chequear('indagacion_previa → en_proceso', estado_desde_etapa('indagacion_previa') === 'en_proceso');
chequear('cargos_formulados → en_proceso', estado_desde_etapa('cargos_formulados') === 'en_proceso');
chequear('juzgamiento → en_proceso', estado_desde_etapa('juzgamiento') === 'en_proceso');
chequear('cerrada → resuelta', estado_desde_etapa('cerrada') === 'resuelta');
chequear('archivo_definitivo → archivada', estado_desde_etapa('archivo_definitivo') === 'archivada');

$d_h23 = crear_denuncia_prueba('H23');
$r = cambiar_etapa_procesal($d_h23, 'indagacion_previa', $coordinador_id, 'prueba');
$fila = db_select_one('SELECT estado, etapa_procesal FROM denuncias WHERE id = ?', [$d_h23]);
chequear('cambiar_etapa_procesal actualiza etapa y deriva estado',
    $r['exito'] && $fila['etapa_procesal'] === 'indagacion_previa' && $fila['estado'] === 'en_proceso');
$r = cambiar_etapa_procesal($d_h23, 'etapa_inventada', $coordinador_id);
chequear('una etapa inexistente se rechaza', $r['exito'] === false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-14 · decisión sobre la Procuraduría');

$d_rn14 = crear_denuncia_prueba('RN14');
derivar_competencia($d_rn14, 'docente_carrera', null);
$comp = obtener_competencia($d_rn14);
chequear('la vía derivada para docente es disciplinaria_docente',
    ($comp['via'] ?? '') === 'disciplinaria_docente', $comp['via'] ?? 'sin via');

chequear('sin decisión, RN-14 está pendiente', rn14_decision_pendiente($d_rn14) === true);

$r = cambiar_etapa_procesal($d_rn14, 'investigacion', $coordinador_id);
chequear('la transición a investigación se BLOQUEA sin decisión (RN-14)', $r['exito'] === false);

$r = decidir_procuraduria($d_rn14, false, '', $coordinador_id);
chequear('decidir «no» sin motivación se rechaza', $r['exito'] === false);

$r = decidir_procuraduria($d_rn14, true, 'Hechos con relevancia disciplinaria de servidor público.', $coordinador_id);
chequear('decidir «sí» con motivación procede', $r['exito'] === true);
chequear('la decisión «sí» crea la remisión a la Procuraduría', $r['remision_id'] !== null);

$remision = db_select_one(
    "SELECT r.id FROM remisiones_externas r JOIN destinos_remision dr ON dr.id = r.destino_id
      WHERE r.denuncia_id = ? AND dr.codigo = 'procuraduria'", [$d_rn14]);
chequear('la remisión quedó en la tabla', (bool)$remision);

chequear('con decisión registrada, RN-14 deja de estar pendiente', rn14_decision_pendiente($d_rn14) === false);
$r = cambiar_etapa_procesal($d_rn14, 'investigacion', $coordinador_id);
chequear('la transición a investigación ahora procede', $r['exito'] === true);

// Vía estudiantil: RN-14 no aplica
$d_est = crear_denuncia_prueba('EST');
derivar_competencia($d_est, 'estudiante_pregrado', null);
chequear('RN-14 no aplica a la vía estudiantil', rn14_decision_pendiente($d_est) === false);
$r = decidir_procuraduria($d_est, true, 'motivo', $coordinador_id);
chequear('decidir Procuraduría en vía estudiantil se rechaza', $r['exito'] === false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-15 · doble correo y canal elegido');

$correo_inst = 'prueba.f9.' . random_int(1000, 9999) . '@udistrital.edu.co';

$sin_personal = create_user([
    'nombre' => '[PRUEBA F9] Sin personal', 'email' => $correo_inst,
    'password' => 'ClaveDePrueba123!', 'rol' => 'psicologo',
]);
chequear('crear usuario SIN correo personal se rechaza', $sin_personal === false);

$dominio_malo = create_user([
    'nombre' => '[PRUEBA F9] Dominio', 'email' => 'persona@gmail.com',
    'password' => 'ClaveDePrueba123!', 'rol' => 'psicologo',
    'correo_personal' => 'personal@correo.com',
]);
chequear('un institucional fuera del dominio se rechaza', $dominio_malo === false);

$usuario_ok = create_user([
    'nombre' => '[PRUEBA F9] Completo', 'email' => $correo_inst,
    'password' => 'ClaveDePrueba123!', 'rol' => 'psicologo',
    'correo_personal' => 'personal.f9@correo.com',
]);
chequear('con ambos correos el alta procede', $usuario_ok !== false);

$pares = destinatarios_de_usuario((int)$usuario_ok);
chequear('un usuario del equipo recibe en DOS direcciones', count($pares) === 2, (string)count($pares));
$tipos = array_column($pares, 'tipo');
chequear('una es institucional y otra personal',
    in_array('institucional', $tipos, true) && in_array('personal', $tipos, true));

$d_rn15 = crear_denuncia_prueba('RN15');
registrar_persona_caso($d_rn15, 'afectada', [
    'nombre' => '[PRUEBA F9]', 'correo' => 'afectada.personal@correo.com',
    'correo_institucional' => 'afectada@udistrital.edu.co',
    'canal_preferido' => 'personal', 'es_mismo_denunciante' => 1,
]);

$pares = destinatarios_de_persona($d_rn15, 'afectada');
chequear('afectada con canal personal recibe SOLO en el personal',
    count($pares) === 1 && $pares[0]['tipo'] === 'personal');

db_execute("UPDATE personas_caso SET canal_preferido = 'institucional' WHERE denuncia_id = ? AND papel = 'afectada'", [$d_rn15]);
$pares = destinatarios_de_persona($d_rn15, 'afectada');
chequear('con canal institucional recibe SOLO en el institucional',
    count($pares) === 1 && $pares[0]['tipo'] === 'institucional');

db_execute("UPDATE personas_caso SET canal_preferido = 'ninguno' WHERE denuncia_id = ? AND papel = 'afectada'", [$d_rn15]);
chequear('con canal «ninguno» no se envía nada', destinatarios_de_persona($d_rn15, 'afectada') === []);

db_execute("UPDATE personas_caso SET canal_preferido = 'ambos' WHERE denuncia_id = ? AND papel = 'afectada'", [$d_rn15]);
chequear('con canal «ambos» recibe en las dos', count(destinatarios_de_persona($d_rn15, 'afectada')) === 2);

$encoladas = encolar_notificacion_grupo(destinatarios_de_usuario((int)$usuario_ok), 'compromiso_asignado', [
    'radicado' => 'BV-PRUEBA', 'plazo' => date('Y-m-d'), 'enlace' => url('?page=login'),
], $d_rn15);
chequear('el grupo de envío crea una fila por dirección', $encoladas === 2, (string)$encoladas);

$grupo = db_select(
    'SELECT grupo_envio, destinatario_tipo FROM notificaciones_salientes
      WHERE denuncia_id = ? ORDER BY id DESC LIMIT 2', [$d_rn15]) ?: [];
chequear('ambas filas comparten el mismo grupo_envio (UUID)',
    count($grupo) === 2 && $grupo[0]['grupo_envio'] !== null
    && $grupo[0]['grupo_envio'] === $grupo[1]['grupo_envio']);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-16 · compromisos restaurativos solo con decisión en firme');

$d_rn16 = crear_denuncia_prueba('RN16');

$base_restaurativo = [
    'denuncia_id' => $d_rn16, 'origen' => 'restaurativo',
    'descripcion' => 'Compromiso de no repetición', 'fecha_limite' => date('Y-m-d', strtotime('+30 days')),
    'responsable_tipo' => 'usuario', 'responsable_usuario_id' => $coordinador_id,
];

$r = crear_compromiso($base_restaurativo, $coordinador_id);
chequear('sin sanción en firme se RECHAZA', $r['exito'] === false);

$sancion = registrar_sancion([
    'denuncia_id' => $d_rn16, 'autoridad' => 'Consejo de Facultad',
    'tipo' => 'Amonestación', 'acto_fecha' => date('Y-m-d'),
], $coordinador_id, $coordinador['rol']);
chequear('la sanción se registra sin firmeza', $r2 = $sancion['exito']);

$r = crear_compromiso($base_restaurativo + ['sancion_id' => $sancion['sancion_id']], $coordinador_id);
chequear('con sanción SIN firmeza también se rechaza', $r['exito'] === false);

marcar_sancion_firme($sancion['sancion_id'], date('Y-m-d'), $coordinador_id, $coordinador['rol']);

$r = crear_compromiso($base_restaurativo + ['sancion_id' => $sancion['sancion_id']], $coordinador_id);
chequear('con firmeza pero sin consentimiento se rechaza', $r['exito'] === false);

$consentimiento_id = registrar_consentimiento($d_rn16, 'medida_especifica',
    '[PRUEBA F9] Alcance explicado.', true, 'presencial', $coordinador_id);

$r = crear_compromiso($base_restaurativo + [
    'sancion_id' => $sancion['sancion_id'], 'consentimiento_id' => $consentimiento_id,
], $coordinador_id);
chequear('con firmeza y consentimiento pero sin concepto favorable se rechaza', $r['exito'] === false);

$r = crear_compromiso($base_restaurativo + [
    'sancion_id' => $sancion['sancion_id'], 'consentimiento_id' => $consentimiento_id,
    'concepto_favorable_por' => $coordinador_id,
], $coordinador_id);
chequear('con los TRES requisitos el compromiso restaurativo procede', $r['exito'] === true);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Máquina de estados de compromisos');

$r = crear_compromiso([
    'denuncia_id' => $d_rn16, 'origen' => 'seguimiento',
    'descripcion' => 'Informar estado', 'fecha_limite' => date('Y-m-d', strtotime('+10 days')),
    'responsable_tipo' => 'unidad', 'responsable_unidad_id' => $sede_id,
], $coordinador_id);
$compromiso_id = $r['compromiso_id'];
chequear('el compromiso de seguimiento se crea', $r['exito'] === true);

$r = crear_compromiso([
    'denuncia_id' => $d_rn16, 'origen' => 'seguimiento',
    'descripcion' => 'Sin responsable', 'fecha_limite' => date('Y-m-d'),
    'responsable_tipo' => 'usuario',
], $coordinador_id);
chequear('un compromiso sin responsable se rechaza', $r['exito'] === false);

$r = cambiar_estado_compromiso($compromiso_id, 'cumplido', $coordinador_id, []);
chequear('cumplido SIN constancia se rechaza', $r['exito'] === false);

$r = cambiar_estado_compromiso($compromiso_id, 'cancelado', $coordinador_id, []);
chequear('cancelar sin motivación se rechaza', $r['exito'] === false);

$r = cambiar_estado_compromiso($compromiso_id, 'cumplido', $coordinador_id, [
    'constancia_texto' => '[PRUEBA F9] Constancia registrada.',
]);
chequear('cumplido CON constancia procede', $r['exito'] === true);

$r = cambiar_estado_compromiso($compromiso_id, 'en_curso', $coordinador_id, []);
chequear('un compromiso cumplido no admite más transiciones', $r['exito'] === false);

$transicion = db_select_one(
    'SELECT desde, hasta FROM compromiso_transiciones WHERE compromiso_id = ? ORDER BY id DESC LIMIT 1',
    [$compromiso_id]);
chequear('la transición quedó registrada',
    $transicion && $transicion['desde'] === 'pendiente' && $transicion['hasta'] === 'cumplido');

// Respuesta de solicitud de estado (§16 E5)
$r = crear_compromiso([
    'denuncia_id' => $d_rn16, 'origen' => 'seguimiento',
    'descripcion' => 'Solicitud de estado', 'fecha_limite' => date('Y-m-d', strtotime('+10 days')),
    'responsable_tipo' => 'unidad', 'responsable_unidad_id' => $sede_id,
], $coordinador_id);
$r2 = responder_compromiso($r['compromiso_id'], 'La actuación avanza en término.', $coordinador_id);
$respondido = db_select_one('SELECT respuesta_texto, estado FROM compromisos WHERE id = ?', [$r['compromiso_id']]);
chequear('la dependencia responde y el compromiso queda cumplido',
    $r2['exito'] && $respondido['estado'] === 'cumplido' && $respondido['respuesta_texto'] !== null);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-17 · la sede no decide quién instruye');

$d_rn17 = crear_denuncia_prueba('RN17');
derivar_competencia($d_rn17, 'docente_carrera', null);
$antes = obtener_competencia($d_rn17);

db_update_simple('denuncias', ['unidad_hechos_id' => $sede_id, 'unidad_atencion_id' => $sede_id],
                 ['id' => $d_rn17]);

$despues = obtener_competencia($d_rn17);
chequear('cambiar la sede de los hechos NO altera la vía',
    $antes['via'] === $despues['via']);
chequear('ni la autoridad que instruye',
    $antes['autoridad_instruye'] === $despues['autoridad_instruye']);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-18 · encuentro inicial automático sin confrontación');

$d_rn18 = crear_denuncia_prueba('RN18', ['unidad_atencion_id' => $sede_id]);
registrar_persona_caso($d_rn18, 'afectada', [
    'nombre' => '[PRUEBA F9] Afectada', 'correo' => 'af.rn18@correo.com',
    'canal_preferido' => 'personal', 'es_mismo_denunciante' => 1,
]);
registrar_persona_caso($d_rn18, 'denunciada', ['tipo_vinculacion' => 'docente_carrera']);

// Turno para el siguiente día hábil
$fecha_turno = sumar_dias_habiles(date('Y-m-d'), 1);
$turno_id = db_insert_simple('turnos_atencion', [
    'unidad_id' => $sede_id, 'usuario_id' => (int)$usuario_ok,
    'dia_semana' => (int)date('N', strtotime($fecha_turno)),
    'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00', 'activo' => 1,
]);

$r = agendar_encuentro_inicial($d_rn18);
chequear('el encuentro inicial se agenda automáticamente', $r['exito'] === true, json_encode($r));

$participantes = db_select(
    'SELECT papel FROM encuentro_participantes WHERE encuentro_id = ?', [(int)$r['encuentro_id']]) ?: [];
$papeles = array_column($participantes, 'papel');
chequear('la persona denunciada NUNCA participa', !in_array('denunciada', $papeles, true));
chequear('participan la afectada y el profesional',
    in_array('afectada', $papeles, true) && in_array('profesional', $papeles, true));

// Con canal 'ninguno' no se agenda
$d_rn18b = crear_denuncia_prueba('RN18B', ['canal_preferido' => 'ninguno', 'unidad_atencion_id' => $sede_id]);
$r = agendar_encuentro_inicial($d_rn18b);
chequear('con canal «ninguno» no se agenda encuentro', $r['exito'] === false && $r['pendiente'] === false);

// Sin turno cae a la bandeja
db_update_simple('turnos_atencion', ['activo' => 0], ['id' => $turno_id]);
$d_rn18c = crear_denuncia_prueba('RN18C', ['unidad_atencion_id' => $sede_id]);
registrar_persona_caso($d_rn18c, 'afectada', ['canal_preferido' => 'personal', 'es_mismo_denunciante' => 1]);
$r = agendar_encuentro_inicial($d_rn18c);
chequear('sin turno disponible queda en la bandeja «Por agendar»',
    $r['exito'] === false && $r['pendiente'] === true);
$bandeja = encuentros_por_agendar($sede_id);
$ids_bandeja = array_column($bandeja, 'id');
chequear('la bandeja lista el caso pendiente', in_array($d_rn18c, $ids_bandeja));
db_update_simple('turnos_atencion', ['activo' => 1], ['id' => $turno_id]);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-19 · la ruta de atención exige evidencia');

$d_rn19 = crear_denuncia_prueba('RN19');
$r = avanzar_etapa_ruta($d_rn19, 'encuentro_inicial', $coordinador_id);
chequear('avanzar sin encuentro creado se rechaza', $r['exito'] === false);

$r = avanzar_etapa_ruta($d_rn19, 'encuentro_inicial', $coordinador_id, 'Avance de oficio motivado para la prueba.');
chequear('el avance de oficio con motivación procede y queda auditado', $r['exito'] === true);

$evento = db_select_one(
    "SELECT id FROM auditoria WHERE accion = 'etapa_ruta_avanzada_oficio'
      AND tabla = 'denuncias' AND registro_id = ? LIMIT 1", [$d_rn19]);
chequear('la auditoría registró el avance de oficio', (bool)$evento);

$r = avanzar_etapa_ruta($d_rn19, 'conocimiento', $coordinador_id);
chequear('la ruta no retrocede', $r['exito'] === false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-20 · menores de edad');

$d_rn20 = crear_denuncia_prueba('RN20');
$r = registrar_persona_caso($d_rn20, 'afectada', [
    'es_menor_de_edad' => 1, 'canal_preferido' => 'personal', 'es_mismo_denunciante' => 1,
]);
chequear('menor sin contacto de emergencia se rechaza', $r === false);

$r = registrar_persona_caso($d_rn20, 'afectada', [
    'es_menor_de_edad' => 1, 'canal_preferido' => 'ninguno',
    'contacto_emergencia' => 'Acudiente 3001234567', 'es_mismo_denunciante' => 1,
]);
chequear('menor con canal «ninguno» se rechaza', $r === false);

$r = registrar_persona_caso($d_rn20, 'afectada', [
    'es_menor_de_edad' => 1, 'canal_preferido' => 'personal',
    'contacto_emergencia' => 'Acudiente 3001234567', 'es_mismo_denunciante' => 1,
]);
chequear('menor con contacto y canal procede', $r !== false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('RN-21 · queja anónima con mérito');

$reporte_id = db_insert_simple('reportes_anonimos', [
    'tipo' => 'anonimo', 'descripcion' => '[PRUEBA F9] Reporte con hechos concretos y autor identificable.',
    'tiene_pruebas' => 1, 'estado' => 'recibido', 'created_at' => date('Y-m-d H:i:s'),
]);

$r = decidir_reporte_anonimo($reporte_id, 'convertir_en_expediente_oficio', '', $coordinador_id, $coordinador['rol']);
chequear('decidir sin motivación se rechaza', $r['exito'] === false);

$r = decidir_reporte_anonimo($reporte_id, 'convertir_en_expediente_oficio',
    'Trae pruebas y autor identificable.', 1, 'psicologo');
chequear('un rol distinto de coordinación no decide', $r['exito'] === false);

$r = decidir_reporte_anonimo($reporte_id, 'convertir_en_expediente_oficio',
    'Trae pruebas y autor identificable.', $coordinador_id, $coordinador['rol']);
chequear('la conversión de oficio procede', $r['exito'] === true && $r['denuncia_id'] !== null);

$oficio = db_select_one('SELECT * FROM denuncias WHERE id = ?', [(int)$r['denuncia_id']]);
chequear('el expediente de oficio nace con canal_origen = anonimo',
    ($oficio['canal_origen'] ?? '') === 'anonimo');
chequear('sin persona denunciante (no hay quejoso identificado)',
    db_count('personas_caso', ['denuncia_id' => (int)$r['denuncia_id'], 'papel' => 'denunciante']) === 0);
chequear('la reserva RN-02 rige desde el primer momento',
    expediente_bajo_reserva($oficio['etapa_procesal']) === true);

$r2 = decidir_reporte_anonimo($reporte_id, 'archivar_sin_merito', 'x', $coordinador_id, $coordinador['rol']);
chequear('un reporte ya decidido no se decide dos veces', $r2['exito'] === false);
$denuncia_oficio_id = (int)$r['denuncia_id'];

// ─────────────────────────────────────────────────────────────────────────────
seccion('Aislamiento de roles (Res. 142/2026 art. 13 · §9.1)');

chequear('comision_seguimiento NO accede a expedientes',
    puede_ver_expediente(999999, 'comision_seguimiento', $d_rn14) === false);
chequear('administrador NO accede a expedientes',
    puede_ver_expediente(1, 'administrador', $d_rn14) === false);
chequear('unidad_academica NO accede a expedientes',
    puede_ver_expediente(1, 'unidad_academica', $d_rn14) === false);

$restriccion = restriccion_listado_expedientes(1, 'comision_seguimiento');
chequear('el listado para comision_seguimiento es la condición imposible',
    $restriccion['sql'] === '1 = 0');

$menu_ua = menu_para_rol('unidad_academica');
chequear('el menú de unidad_academica solo tiene órdenes y perfil',
    array_keys($menu_ua) === ['ordenes-medida', 'perfil']);
chequear('el menú de comision_seguimiento solo tiene reportes y perfil',
    array_keys(menu_para_rol('comision_seguimiento')) === ['reportes', 'perfil']);
chequear('un rol desconocido solo ve su perfil',
    array_keys(menu_para_rol('rol_inventado')) === ['perfil']);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Medida → compromiso automático (§7.1)');

$catalogo = db_select_one("SELECT id FROM catalogo_medidas WHERE activo = 1 LIMIT 1");
if ($catalogo) {
    $antes = db_count('compromisos', ['denuncia_id' => $d_rn16, 'origen' => 'medida']);
    $r = solicitar_medida([
        'denuncia_id' => $d_rn16, 'catalogo_id' => (int)$catalogo['id'],
        'solicitada_por' => 'victima', 'unidad_ejecutora_id' => $sede_id,
    ], $coordinador_id);
    $despues = db_count('compromisos', ['denuncia_id' => $d_rn16, 'origen' => 'medida']);
    chequear('solicitar una medida crea su compromiso para la unidad ejecutora',
        $r['exito'] && $despues === $antes + 1);
} else {
    chequear('hay catálogo de medidas para probar', false, 'catálogo vacío');
}

// ─────────────────────────────────────────────────────────────────────────────
seccion('Remisiones · el relato no viaja sin decisión motivada (§7.2)');

$destino_fiscalia = db_select_one("SELECT id FROM destinos_remision WHERE codigo = 'fiscalia'");
$r = crear_remision([
    'denuncia_id' => $d_rn16, 'destino_id' => (int)$destino_fiscalia['id'],
    'tipo' => 'traslado', 'motivacion' => 'Compulsa de copias.',
    'relato_incluido' => true,
], $coordinador_id);
chequear('incluir el relato sin motivación se rechaza', $r['exito'] === false);

$r = crear_remision([
    'denuncia_id' => $d_rn16, 'destino_id' => (int)$destino_fiscalia['id'],
    'tipo' => 'traslado', 'motivacion' => 'Compulsa de copias por presunto delito.',
], $coordinador_id);
chequear('la remisión sin relato procede', $r['exito'] === true);

$fila = db_select_one('SELECT relato_incluido FROM remisiones_externas WHERE id = ?', [(int)$r['remision_id']]);
chequear('por defecto el relato NO viaja', (int)$fila['relato_incluido'] === 0);

$destino_inactivo = db_select_one("SELECT id FROM destinos_remision WHERE codigo = 'secretaria_mujer'");
$r2 = crear_remision([
    'denuncia_id' => $d_rn16, 'destino_id' => (int)$destino_inactivo['id'],
    'tipo' => 'informacion', 'motivacion' => 'x',
], $coordinador_id);
chequear('un destino sin fundamento aprobado (inactivo) se rechaza', $r2['exito'] === false);

$compromiso_envio = db_select_one(
    "SELECT id, estado FROM compromisos WHERE remision_id = ? AND origen = 'remision'",
    [(int)$r['remision_id']]);
chequear('la remisión creó su compromiso de envío', (bool)$compromiso_envio);

cambiar_estado_remision((int)$r['remision_id'], 'enviada', $coordinador_id, [
    'medio_envio' => 'correo_institucional', 'radicado_destino' => 'FGN-2026-00123',
]);
$compromiso_envio = db_select_one('SELECT estado FROM compromisos WHERE id = ?', [(int)$compromiso_envio['id']]);
chequear('registrar el radicado de destino cumple el compromiso de envío',
    $compromiso_envio['estado'] === 'cumplido');

// ─────────────────────────────────────────────────────────────────────────────
seccion('Remisiones INTERNAS · OAAD, OCID y decanaturas (Protocolo E4 · 0026)');

$facultad_prueba = db_insert_simple('unidades', [
    'tipo' => 'facultad', 'padre_id' => $sede_id, 'codigo' => 'TEST-F9-FAC',
    'nombre' => '[PRUEBA F9] Facultad', 'activa' => 1,
]);

// 0030: OAAD y OCID son la MISMA oficina (renombrada). Solo existe OCID.
chequear('el destino «oaad» ya no existe (0030)',
    !db_select_one("SELECT id FROM destinos_remision WHERE codigo = 'oaad'"));
chequear('la dependencia OAAD ya no existe (0030)',
    !db_select_one("SELECT id FROM unidades WHERE codigo = 'OAAD'"));

// OCID asume el direccionamiento del Protocolo E4: 5 días hábiles
$destino_ocid = db_select_one("SELECT id FROM destinos_remision WHERE codigo = 'ocid'");
$r = crear_remision([
    'denuncia_id' => $d_rn16, 'destino_id' => (int)$destino_ocid['id'],
    'tipo' => 'traslado', 'motivacion' => 'Remisión del caso a la OCID para direccionamiento.',
], $coordinador_id);
chequear('la remisión interna a OCID procede', $r['exito'] === true, $r['error'] ?? '');

$unidad_ocid = db_select_one("SELECT id FROM unidades WHERE codigo = 'OCID'");
$compromiso_ocid = db_select_one(
    "SELECT fecha_limite FROM compromisos
      WHERE remision_id = ? AND responsable_unidad_id = ?",
    [(int)$r['remision_id'], (int)$unidad_ocid['id']]);
chequear('la OCID recibe su compromiso de direccionamiento', (bool)$compromiso_ocid);
chequear('con el plazo del catálogo (5 días hábiles, Protocolo E4)',
    $compromiso_ocid && $compromiso_ocid['fecha_limite'] === sumar_dias_habiles(date('Y-m-d'), 5),
    $compromiso_ocid['fecha_limite'] ?? 'sin fecha');

// El envío con radicado cumple SOLO el compromiso del remitente
cambiar_estado_remision((int)$r['remision_id'], 'enviada', $coordinador_id, [
    'medio_envio' => 'correo_institucional', 'radicado_destino' => 'OCID-2026-001',
]);
$receptor = db_select_one(
    "SELECT estado FROM compromisos WHERE remision_id = ? AND responsable_unidad_id = ?",
    [(int)$r['remision_id'], (int)$unidad_ocid['id']]);
chequear('el radicado de destino NO cierra el compromiso del receptor',
    $receptor && $receptor['estado'] === 'pendiente', $receptor['estado'] ?? '');

// Decanatura: exige señalar la facultad
$destino_dec = db_select_one("SELECT id FROM destinos_remision WHERE codigo = 'decanatura'");
$r = crear_remision([
    'denuncia_id' => $d_rn16, 'destino_id' => (int)$destino_dec['id'],
    'tipo' => 'informacion', 'motivacion' => 'Comunicación a la decanatura.',
], $coordinador_id);
chequear('decanatura SIN facultad señalada se rechaza', $r['exito'] === false);

$r = crear_remision([
    'denuncia_id' => $d_rn16, 'destino_id' => (int)$destino_dec['id'],
    'unidad_interna_id' => $sede_id, // una sede no es una facultad
    'tipo' => 'informacion', 'motivacion' => 'Comunicación a la decanatura.',
], $coordinador_id);
chequear('una unidad que no es facultad se rechaza', $r['exito'] === false);

$r = crear_remision([
    'denuncia_id' => $d_rn16, 'destino_id' => (int)$destino_dec['id'],
    'unidad_interna_id' => $facultad_prueba,
    'tipo' => 'informacion', 'motivacion' => 'Comunicación del caso a la decanatura (Protocolo E4 paso 4).',
], $coordinador_id);
chequear('con la facultad señalada, la remisión a decanatura procede', $r['exito'] === true);
chequear('la decanatura (facultad) recibe su compromiso en la bandeja',
    db_exists('compromisos', [
        'remision_id' => (int)$r['remision_id'], 'responsable_unidad_id' => (int)$facultad_prueba]));

// ─────────────────────────────────────────────────────────────────────────────
seccion('Limpieza · la suite no deja rastro');

db_execute('DELETE FROM turnos_atencion WHERE id = ?', [$turno_id]);
db_execute("DELETE FROM usuarios WHERE nombre LIKE '[PRUEBA F9]%'");
$ids_prueba = array_filter([$d_h23, $d_rn14, $d_est, $d_rn15, $d_rn16, $d_rn17,
                            $d_rn18, $d_rn18b, $d_rn18c, $d_rn19, $d_rn20, $denuncia_oficio_id]);
foreach ($ids_prueba as $id) {
    db_execute('DELETE FROM sanciones WHERE denuncia_id = ?', [$id]);
    db_execute('DELETE FROM denuncias WHERE id = ?', [$id]); // CASCADE limpia hijos
}
db_execute('DELETE FROM reportes_anonimos WHERE id = ?', [$reporte_id]);
// La facultad antes que la sede (FK padre_id); ambas después de las denuncias
// (FK unidad_interna_id de las remisiones)
db_execute('DELETE FROM unidades WHERE id = ?', [$facultad_prueba]);
db_execute('DELETE FROM unidades WHERE id = ?', [$sede_id]);

chequear('las denuncias de prueba se eliminaron',
    db_count('denuncias', ['id' => $d_rn16]) === 0);
chequear('las cuentas de prueba se eliminaron',
    db_select_one("SELECT COUNT(*) AS t FROM usuarios WHERE nombre LIKE '[PRUEBA F9]%'")['t'] == 0);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('-', 60) . "\n";
if ($fallidas === 0) {
    echo "TODAS LAS COMPROBACIONES PASARON: $correctas correctas, 0 fallidas\n";
    exit(0);
}
echo "RESULTADO: $correctas correctas, $fallidas FALLIDAS\n";
exit(1);
