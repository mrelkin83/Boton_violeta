<?php
/**
 * PRUEBAS · FASE 2 — SEGUIMIENTO SEGURO
 *
 * Verifica el segundo factor del seguimiento público, el cifrado de los datos
 * de contacto y el criterio de aceptación del roadmap:
 *
 *   «10.000 intentos de enumeración no deben revelar la existencia
 *    de ningún radicado.»
 *
 * ⚠ Ejecutar SIEMPRE contra una réplica, nunca contra producción.
 *
 * Uso:
 *   BV_CONFIG=/ruta/bv_config_pruebas.php php pruebas/fase2_seguimiento.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Estas pruebas solo se ejecutan por línea de comandos.');
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/init.php';

if (APP_ENV === 'production') {
    exit("ABORTADO: APP_ENV es 'production'.\n");
}

$correctas = 0;
$fallidas  = 0;

function chequear(string $nombre, bool $condicion, string $detalle = ''): void {
    global $correctas, $fallidas;
    if ($condicion) { $correctas++; echo "  [OK]    $nombre\n"; }
    else { $fallidas++; echo "  [FALLO] $nombre" . ($detalle !== '' ? "  ($detalle)" : '') . "\n"; }
}

function seccion(string $t): void { echo "\n=== $t ===\n"; }

/** Crea un código de un solo uso con valor conocido, para poder verificarlo */
function crear_codigo_prueba(int $denuncia_id, string $codigo, int $minutos = 10, int $intentos = 0): int {
    $sal = bin2hex(random_bytes(16));
    return (int)db_insert_simple('codigos_seguimiento', [
        'denuncia_id'  => $denuncia_id,
        'codigo_hash'  => hash('sha256', $sal . $codigo),
        'sal'          => $sal,
        'canal'        => 'correo',
        'intentos'     => $intentos,
        'expira_at'    => date('Y-m-d H:i:s', time() + $minutos * 60),
        'ip_solicitud' => '127.0.0.1',
        'created_at'   => date('Y-m-d H:i:s'),
    ]);
}

/** Limpia los tres ejes de limitación para aislar cada prueba */
function limpiar_limites(): void {
    foreach (['seguimiento_ip:', 'seguimiento_verif:'] as $prefijo) {
        rate_limit_clear($prefijo . get_user_ip());
    }
    rate_limit_clear('seguimiento_global');
}

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// ─────────────────────────────────────────────────────────────────────────────
// Estado inicial limpio
//
// Sin esto la suite no es idempotente: el límite de 5 códigos por radicado
// cada 24 h y los contadores de intentos sobreviven entre ejecuciones, y la
// segunda corrida falla por motivos que nada tienen que ver con el código.
// ─────────────────────────────────────────────────────────────────────────────
db_execute('DELETE FROM codigos_seguimiento');
foreach (glob(BASE_PATH . '/cache/rate_limit_*.json') as $archivo) {
    @unlink($archivo);
}
cerrar_sesion_consulta();

$denuncia = db_select_one(
    'SELECT id, codigo FROM denuncias WHERE denunciante_correo_cifrado IS NOT NULL ORDER BY id LIMIT 1'
);

if (!$denuncia) {
    exit("ABORTADO: la réplica no tiene denuncias con contacto cifrado. Ejecutar antes la migración 0009.\n");
}

// ─────────────────────────────────────────────────────────────────────────────
seccion('Cifrado en reposo e índice ciego');

$correo = 'Ana.Perez@UDistrital.edu.co';
$cifrado = cifrar_contacto($correo);

chequear('el cifrado devuelve algo distinto del original', $cifrado !== null && $cifrado !== $correo);
chequear('el texto plano no aparece en el cifrado', strpos((string)$cifrado, 'Ana.Perez') === false);
chequear('descifra al valor exacto', descifrar_contacto($cifrado) === $correo);
chequear('dos cifrados del mismo valor difieren (IV aleatorio)',
    cifrar_contacto($correo) !== cifrar_contacto($correo));

$i1 = indice_ciego('Ana.Perez@UDistrital.edu.co', 'correo');
$i2 = indice_ciego('ana.perez@udistrital.edu.co', 'correo');
chequear('el índice ciego es determinista e insensible a mayúsculas', $i1 === $i2);
chequear('el índice mide 64 caracteres hexadecimales', (bool)preg_match('/^[a-f0-9]{64}$/', (string)$i1));
chequear('el índice no revela el valor', strpos((string)$i1, 'ana') === false);
chequear('+573001234567 y 3001234567 comparten índice',
    indice_ciego('+573001234567', 'telefono') === indice_ciego('3001234567', 'telefono'));
chequear('correos distintos producen índices distintos',
    indice_ciego('otra@ud.edu.co', 'correo') !== $i1);

chequear('enmascarado de correo', enmascarar_contacto('ana.perez@ud.edu.co', 'correo') === 'an*******@ud.edu.co',
    enmascarar_contacto('ana.perez@ud.edu.co', 'correo'));
chequear('enmascarado de teléfono', enmascarar_contacto('3001234567', 'telefono') === '******4567',
    enmascarar_contacto('3001234567', 'telefono'));

// ─────────────────────────────────────────────────────────────────────────────
seccion('Respuesta uniforme · el sistema no confirma ni desmiente');

limpiar_limites();
$r_existe = solicitar_codigo_seguimiento($denuncia['codigo']);
limpiar_limites();
$r_no_existe = solicitar_codigo_seguimiento('BV-20260726-ZZZZZZ');
limpiar_limites();
$r_malformado = solicitar_codigo_seguimiento('no-es-un-radicado');

chequear('mismo mensaje para radicado existente e inexistente',
    $r_existe['mensaje'] === $r_no_existe['mensaje'], $r_no_existe['mensaje']);
chequear('mismo mensaje para radicado mal formado',
    $r_existe['mensaje'] === $r_malformado['mensaje']);
chequear('mismo indicador de aceptación',
    $r_existe['aceptada'] === $r_no_existe['aceptada'] &&
    $r_existe['aceptada'] === $r_malformado['aceptada']);
chequear('ninguna respuesta menciona "no encontrado"',
    stripos($r_no_existe['mensaje'], 'no se encontr') === false &&
    stripos($r_no_existe['mensaje'], 'no existe') === false);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Código de un solo uso');

$codigos_generados = [];
for ($i = 0; $i < 200; $i++) { $codigos_generados[] = generar_codigo_otp(); }
chequear('el código tiene 6 dígitos',
    (bool)preg_match('/^\d{6}$/', $codigos_generados[0]), $codigos_generados[0]);
chequear('conserva ceros a la izquierda (es cadena, no número)',
    count(array_filter($codigos_generados, fn($c) => strlen($c) === 6)) === 200);
chequear('hay variedad en 200 códigos', count(array_unique($codigos_generados)) > 150);

// El código en claro nunca se persiste
limpiar_limites();
solicitar_codigo_seguimiento($denuncia['codigo']);
$fila = db_select_one('SELECT * FROM codigos_seguimiento WHERE denuncia_id = ? ORDER BY id DESC LIMIT 1',
    [$denuncia['id']]);
chequear('se almacena un hash de 64 caracteres, no el código',
    (bool)preg_match('/^[a-f0-9]{64}$/', $fila['codigo_hash']));
chequear('se almacena una sal por código', strlen($fila['sal']) === 32);
chequear('la vigencia es de 10 minutos',
    abs(strtotime($fila['expira_at']) - (time() + 600)) <= 5);

// Verificación correcta
limpiar_limites();
crear_codigo_prueba((int)$denuncia['id'], '123456');
$v = verificar_codigo_seguimiento($denuncia['codigo'], '123456');
chequear('un código correcto concede acceso', $v['valido'] === true, $v['mensaje']);
chequear('se abre la sesión efímera de consulta', sesion_consulta_vigente() !== null);

// Reutilización
limpiar_limites();
$v2 = verificar_codigo_seguimiento($denuncia['codigo'], '123456');
chequear('el mismo código no sirve dos veces', $v2['valido'] === false);

// Expiración
cerrar_sesion_consulta();
limpiar_limites();
crear_codigo_prueba((int)$denuncia['id'], '654321', -1); // ya vencido
$v3 = verificar_codigo_seguimiento($denuncia['codigo'], '654321');
chequear('un código vencido no sirve', $v3['valido'] === false);

// Máximo de intentos
limpiar_limites();
crear_codigo_prueba((int)$denuncia['id'], '111111');
verificar_codigo_seguimiento($denuncia['codigo'], '999999');
verificar_codigo_seguimiento($denuncia['codigo'], '999998');
verificar_codigo_seguimiento($denuncia['codigo'], '999997');
$v4 = verificar_codigo_seguimiento($denuncia['codigo'], '111111'); // correcto, pero tarde
chequear('el código muere tras 3 intentos fallidos', $v4['valido'] === false);

// Mensaje genérico
chequear('el error no distingue entre código inválido y vencido',
    $v2['mensaje'] === $v3['mensaje']);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Sesión efímera de consulta');

cerrar_sesion_consulta();
limpiar_limites();
crear_codigo_prueba((int)$denuncia['id'], '222222');
verificar_codigo_seguimiento($denuncia['codigo'], '222222');

$sesion = sesion_consulta_vigente();
chequear('queda ligada a un único radicado', ($sesion['radicado'] ?? null) === $denuncia['codigo']);
chequear('dura 15 minutos', abs(($sesion['expira'] ?? 0) - (time() + 900)) <= 5);
chequear('no otorga sesión de usuario', !is_authenticated());
chequear('no otorga identidad de usuario', auth_user_id() === null);

$_SESSION['consulta_publica']['expira'] = time() - 1;
chequear('una sesión vencida deja de ser válida', sesion_consulta_vigente() === null);

// ─────────────────────────────────────────────────────────────────────────────
seccion('Proyección pública · qué puede ver quien consulta');

$proyeccion = proyeccion_publica_caso((int)$denuncia['id']);
chequear('incluye el estado', isset($proyeccion['estado']));
chequear('incluye el radicado', isset($proyeccion['codigo']));
chequear('incluye el historial de seguimiento', isset($proyeccion['seguimientos']));
chequear('NO incluye el relato de los hechos', !isset($proyeccion['descripcion']));
chequear('NO incluye datos de la persona denunciada', !isset($proyeccion['agresor_nombre']));
chequear('NO incluye el contacto cifrado', !isset($proyeccion['denunciante_correo_cifrado']));
chequear('NO incluye el nombre de quien denunció', !isset($proyeccion['denunciante_nombre']));

// ─────────────────────────────────────────────────────────────────────────────
seccion('Limitación de intentos en tres ejes');

limpiar_limites();
$bloqueada_en = null;
for ($i = 1; $i <= 30; $i++) {
    $r = solicitar_codigo_seguimiento('BV-20260726-QQQQQQ');
    if ($r['bloqueada']) { $bloqueada_en = $i; break; }
}
chequear('la IP queda bloqueada tras superar el límite', $bloqueada_en !== null,
    'nunca se bloqueó');
chequear('el bloqueo ocurre dentro de los primeros 15 intentos',
    $bloqueada_en !== null && $bloqueada_en <= 15, "bloqueó en el intento $bloqueada_en");

limpiar_limites();
$antes = db_count('codigos_seguimiento', ['denuncia_id' => $denuncia['id']]);
for ($i = 0; $i < 12; $i++) {
    limpiar_limites();
    solicitar_codigo_seguimiento($denuncia['codigo']);
}
$emitidos = db_count('codigos_seguimiento', ['denuncia_id' => $denuncia['id']]) - $antes;
chequear('el límite por radicado corta la emisión (máx. 5 en 24 h)', $emitidos <= 5,
    "se emitieron $emitidos");

// ─────────────────────────────────────────────────────────────────────────────
seccion('Criterio de aceptación · 10.000 intentos de enumeración');

limpiar_limites();
$respuestas    = [];
$bloqueados    = 0;
$total         = 10000;
$radicado_real = $denuncia['codigo'];

$inicio = microtime(true);
for ($i = 0; $i < $total; $i++) {
    // Uno de cada mil intentos usa el radicado REAL: si el sistema filtrara
    // información, ese intento se distinguiría del resto.
    $objetivo = ($i % 1000 === 0)
        ? $radicado_real
        : 'BV-20260726-' . substr(strtoupper(bin2hex(random_bytes(3))), 0, 6);

    $r = solicitar_codigo_seguimiento($objetivo);

    if ($r['bloqueada']) { $bloqueados++; continue; }
    $respuestas[$r['mensaje']] = ($respuestas[$r['mensaje']] ?? 0) + 1;
}
$duracion = round(microtime(true) - $inicio, 1);

echo "  intentos: $total · bloqueados por límite: $bloqueados · en {$duracion}s\n";
echo '  respuestas distinguibles: ' . count($respuestas) . "\n";

chequear('ninguna respuesta permite distinguir un radicado real', count($respuestas) <= 1,
    count($respuestas) . ' mensajes distintos');
chequear('la mayoría de los intentos fue bloqueada por límite',
    $bloqueados > $total * 0.9, "solo $bloqueados de $total");

// ─────────────────────────────────────────────────────────────────────────────
seccion('La auditoría no filtra códigos ni contactos');

$auditoria = db_select("SELECT datos FROM auditoria WHERE tabla = 'codigos_seguimiento'
                          OR accion LIKE 'seguimiento%' ORDER BY id DESC LIMIT 50");
$todo = implode(' ', array_column($auditoria ?: [], 'datos'));

chequear('no contiene códigos de 6 dígitos', !preg_match('/\b\d{6}\b/', $todo));
chequear('no contiene correos', strpos($todo, '@') === false);
chequear('sí registra el canal y la IP',
    strpos($todo, 'canal') !== false || strpos($todo, 'ip') !== false);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('-', 60) . "\n";
echo ($fallidas === 0 ? 'TODAS LAS VERIFICACIONES PASARON' : 'HAY FALLOS')
   . ": $correctas correctas, $fallidas fallidas\n";

exit($fallidas === 0 ? 0 : 1);
