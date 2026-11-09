<?php
/**
 * OFICIO DE ORDEN DE MEDIDA · VERSIÓN IMPRIMIBLE
 *
 * Basado en los formatos institucionales REALES entregados el 2/09/2026:
 *   · DBI-811-2026 → medidas académicas y de acompañamiento (cambio de
 *     grupo…): destinatario coordinación del proyecto curricular.
 *   · DBI-477-2026 → «Restricción de ingreso a sede» (catálogo
 *     R142_RESTRICCION_INGRESO): destinatario Infraestructura/vigilancia,
 *     con bloque de datos identificativos de la persona restringida.
 *
 * Proyección del §10.4 (ADR-05): radicado opaco, medida, fundamento y plazo.
 * En el formato DBI-811 PROHIBIDO: tipo de violencia, relato, identidad de
 * la persona denunciada, riesgo. El nombre de la persona BENEFICIARIA solo
 * con petición expresa auditada («solo si es indispensable», §10.4).
 *
 * En el formato DBI-477 la lógica se INVIERTE deliberadamente: el documento
 * existe para que vigilancia reconozca a la persona RESTRINGIDA (la
 * denunciada), así que su nombre aparece con el toggle auditado; la persona
 * afectada NUNCA aparece en él (protegerla es el objeto de la medida).
 *
 * Firmas (migración 0029): «Cordialmente» con la firma digitalizada de quien
 * genera + tabla «Proyectó · NOMBRE / CARGO / FIRMA», como en los DBI.
 *
 * ⚠ Texto en BORRADOR (v3.1 §12.4.5): la numeración de consecutivo DBI/IE la
 *   asigna la ventanilla institucional — queda en blanco para diligenciarla.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_auth();

$medida_id = input_get('id', 0, 'int');

if (!$medida_id) {
    redirect_with_message(url('?page=denuncias'), 'Medida no encontrada.', 'error');
}

$usuario_actual = auth_user();

$medida = db_select_one(
    'SELECT m.*, cm.codigo AS medida_codigo, cm.nombre AS medida_nombre,
            cm.descripcion AS medida_descripcion, cm.fundamento, cm.categoria,
            d.codigo AS radicado, d.id AS denuncia_id, d.unidad_hechos_id,
            u.nombre AS unidad_nombre
       FROM medidas m
       JOIN catalogo_medidas cm ON cm.id = m.catalogo_id
       JOIN denuncias d ON d.id = m.denuncia_id
       LEFT JOIN unidades u ON u.id = m.unidad_ejecutora_id
      WHERE m.id = ? LIMIT 1',
    [$medida_id]
);

// RN-02: mismo mensaje exista o no
if (!$medida
    || !puede_ver_expediente($usuario_actual['id'], $usuario_actual['rol'], (int)$medida['denuncia_id'])) {
    audit_log($usuario_actual['id'], 'acceso_expediente_denegado', 'medidas', [
        'rol' => $usuario_actual['rol'],
    ], $medida_id);
    redirect_with_message(url('?page=denuncias'), 'Medida no encontrada.', 'error');
}

if (!in_array($usuario_actual['rol'],
    ['coordinacion_edheg', 'coordinador', 'psicologo', 'trabajador_social', 'abogado'], true)) {
    redirect_with_message(url('?page=denuncias'), 'Medida no encontrada.', 'error');
}

$es_restriccion = $medida['medida_codigo'] === 'R142_RESTRICCION_INGRESO';

// ── Nombre de persona: solo a petición expresa, y auditada ──────────────────
// DBI-811: la BENEFICIARIA (afectada). DBI-477: la persona RESTRINGIDA
// (denunciada) — la afectada jamás aparece en ese formato.
$incluir_persona = input_get('con_persona', 0, 'int') === 1
                || input_get('con_beneficiaria', 0, 'int') === 1; // compatibilidad
$nombre_persona = null;

if ($incluir_persona) {
    if ($es_restriccion) {
        $personas = personas_del_caso((int)$medida['denuncia_id'], 'denunciada');
        $nombre_persona = $personas[0]['nombre'] ?? null;
    } else {
        $personas = personas_del_caso((int)$medida['denuncia_id'], 'afectada');
        $nombre_persona = $personas[0]['nombre'] ?? null;

        if ($nombre_persona === null) {
            $fila = db_select_one(
                "SELECT d.denunciante_nombre FROM denuncias d
                  JOIN personas_caso p ON p.denuncia_id = d.id
                 WHERE d.id = ? AND p.papel = 'denunciante' AND p.es_mismo_denunciante = 1 LIMIT 1",
                [(int)$medida['denuncia_id']]);
            $nombre_persona = $fila['denunciante_nombre'] ?? null;
        }
    }
}

// Sede a proteger (DBI-477): la de los hechos, o la señalada en el detalle
$sede_nombre = null;
if ($es_restriccion && $medida['unidad_hechos_id']) {
    $fila = db_select_one('SELECT nombre FROM unidades WHERE id = ? LIMIT 1',
                          [$medida['unidad_hechos_id']]);
    $sede_nombre = $fila['nombre'] ?? null;
}

// Firmas digitalizadas (0029)
$firma_generador = firma_datauri($usuario_actual['id']);
$cargo_generador = trim((string)($usuario_actual['cargo_oficio'] ?? '')) ?: 'Equipo de Derechos Humanos y Equidad de Género';

$momento = date('d/m/Y H:i');

audit_log($usuario_actual['id'], 'oficio_medida_generado', 'medidas', [
    'rol'    => $usuario_actual['rol'],
    'motivo' => ($es_restriccion ? 'dbi477' : 'dbi811') . ($incluir_persona ? '_con_persona' : '_sin_persona'),
], $medida_id);

$meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
          'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Oficio · <?= e($medida['radicado']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Georgia, 'Times New Roman', serif; font-size: 11pt;
               color: #1a1a1a; max-width: 19cm; margin: 0 auto; padding: 1.5rem;
               line-height: 1.55; }
        .cabecera { text-align: center; border-bottom: 3px double #6b21a8;
                    padding-bottom: .75rem; margin-bottom: 1rem; }
        .institucion { font-size: 10pt; letter-spacing: .08em; text-transform: uppercase; }
        .consecutivo { text-align: right; font-family: 'Courier New', monospace; font-size: 10pt;
                       margin-bottom: .75rem; }
        .radicado { font-family: 'Courier New', monospace; }
        .destinatario { margin: 1rem 0; }
        .asunto { margin: .8rem 0; }
        .cuerpo { margin: 1rem 0; text-align: justify; }
        .cuerpo p { margin: .7rem 0; }
        .cuerpo ul { margin: .5rem 0 .5rem 2rem; }
        .datos-persona { border: 1px solid #6b21a8; background: #faf5ff;
                         padding: .6rem .9rem; margin: 1rem 0; }
        .plazo { border: 1px solid #6b21a8; padding: .6rem .9rem; margin: 1rem 0;
                 background: #faf5ff; }
        .reserva { font-size: 9pt; color: #444; border: 1px solid #bbb;
                   padding: .6rem .9rem; margin: 1.25rem 0; }
        .firma { margin-top: 2.25rem; }
        .firma img { max-height: 70px; max-width: 240px; display: block; margin-bottom: .2rem; }
        .firma .linea { border-top: 1px solid #333; width: 24em; padding-top: .3rem; }
        .proyecto { width: 100%; border-collapse: collapse; margin-top: 1.75rem; font-size: 9pt; }
        .proyecto th, .proyecto td { border: 1px solid #999; padding: .3rem .6rem; text-align: left; }
        .proyecto th { background: #f3f4f6; width: 8em; }
        .proyecto img { max-height: 45px; max-width: 160px; }
        .pie { margin-top: 1.5rem; padding-top: .75rem; border-top: 1px solid #999;
               font-size: 9pt; color: #444; }
        .marca { position: fixed; left: 0; right: 0; bottom: 18%; z-index: -1;
                 display: flex; align-items: center; justify-content: center;
                 transform: rotate(-24deg); font-size: 30pt; font-weight: bold;
                 color: rgba(107, 33, 168, .05); pointer-events: none;
                 text-align: center; line-height: 1.4; letter-spacing: .05em; }
        .barra { text-align: center; margin-bottom: 1.25rem; }
        .barra button, .barra a { font-family: inherit; font-size: 10pt;
                 padding: .5rem 1.25rem; margin: 0 .25rem; border: 1px solid #6b21a8;
                 border-radius: 4px; background: #6b21a8; color: #fff; cursor: pointer;
                 text-decoration: none; display: inline-block; }
        .barra a { background: #fff; color: #6b21a8; }
        .borrador { background: #fefce8; border: 1px solid #ca8a04; color: #713f12;
                    font-size: 9pt; padding: .5rem .9rem; margin-bottom: 1rem; }
        @media print {
            body { padding: 0; }
            .barra, .borrador { display: none; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="marca" aria-hidden="true">
    RESERVADO<br><?= e($usuario_actual['nombre']) ?><br><?= e($momento) ?>
</div>

<div class="barra">
    <button type="button" onclick="window.print()">Imprimir</button>
    <?php if (!$incluir_persona): ?>
        <a href="?page=oficio-medida&amp;id=<?= (int)$medida_id ?>&amp;con_persona=1">
            <?= $es_restriccion ? 'Incluir persona restringida' : 'Incluir persona beneficiaria' ?></a>
    <?php else: ?>
        <a href="?page=oficio-medida&amp;id=<?= (int)$medida_id ?>">Quitar el nombre</a>
    <?php endif; ?>
    <a href="?page=expediente&amp;id=<?= (int)$medida['denuncia_id'] ?>&amp;tab=medidas">Volver a medidas</a>
</div>

<div class="borrador">
    ⚠ BORRADOR sobre los formatos DBI-811-2026 / DBI-477-2026. El consecutivo DBI/IE lo asigna la
    ventanilla; el firmante titular lo define Bienestar (§12.4). Este aviso no sale en la impresión.
    <?php if (!$firma_generador): ?>
        · No tienes firma cargada: súbela en «Mi perfil → Mi firma para oficios».
    <?php endif; ?>
</div>

<div class="consecutivo">DBI-____-<?= e(date('Y')) ?> &nbsp;·&nbsp; <?= e(date('Y')) ?>IE_____</div>

<header class="cabecera">
    <p class="institucion">Universidad Distrital Francisco José de Caldas</p>
    <p class="institucion">Oficina de Bienestar Universitario · Programa de Derechos Humanos y Equidad de Género</p>
</header>

<p>Bogotá D.C., <?= e(date('d')) ?> de <?= e($meses[(int)date('n')]) ?> de <?= e(date('Y')) ?></p>

<div class="destinatario">
    <?php if ($es_restriccion): ?>
        <p>Doctor(a)</p>
        <p><strong>_____________________________</strong></p>
        <p>Jefe de Oficina de Infraestructura / Coordinación de Vigilancia</p>
    <?php else: ?>
        <p>Doctor(a)</p>
        <p><strong>_____________________________</strong></p>
        <p>Coordinación · <?= e($medida['unidad_nombre'] ?? 'unidad ejecutora') ?></p>
    <?php endif; ?>
    <p>Universidad Distrital Francisco José de Caldas</p>
    <p>Ciudad</p>
</div>

<p class="asunto"><strong>Asunto:</strong>
    <?php if ($es_restriccion): ?>
        Solicitud de no ingreso · Caso <span class="radicado"><?= e($medida['radicado']) ?></span>
    <?php else: ?>
        Solicitud de <?= e(mb_strtolower($medida['medida_nombre'])) ?> — medida de acompañamiento y
        protección · Caso <span class="radicado"><?= e($medida['radicado']) ?></span>
    <?php endif; ?>
</p>

<p>Cordial saludo,</p>

<div class="cuerpo">
<?php if ($es_restriccion): ?>
    <!-- ══ Formato DBI-477 · restricción de ingreso a sede ══ -->
    <p>En cumplimiento del protocolo institucional de prevención y atención de las
    Violencias Basadas en Género, esta solicitud de medidas de protección se realiza
    bajo la <strong>Resolución 142 de 2026</strong>. La Oficina de Bienestar
    Universitario INFORMA, con carácter <strong>ESTRICTAMENTE CONFIDENCIAL</strong>,
    que una persona de la comunidad universitaria podría estar expuesta a una
    situación de riesgo en el marco del caso del radicado en referencia.</p>

    <p>Con el fin de prevenir nuevas situaciones que pongan en riesgo a la comunidad
    universitaria, se solicita:</p>
    <ul>
        <li>Bloquear de manera inmediata el ingreso de la persona señalada
            <?= $sede_nombre ? 'a la ' . e($sede_nombre) : 'a la sede indicada' ?>.</li>
        <li>Instruir al personal de vigilancia para verificar estrictamente los
            carnés de ingreso.</li>
        <li>Socializar entre todos los turnos de vigilancia los datos identificativos
            de esta persona, remitidos con carácter confidencial.</li>
    </ul>

    <?php if (!empty($medida['detalle_ejecucion'])): ?>
        <p><strong>Precisiones de la medida:</strong> <?= e($medida['detalle_ejecucion']) ?></p>
    <?php endif; ?>

    <div class="datos-persona">
        <p><strong>Datos para uso institucional y del personal de vigilancia:</strong></p>
        <p>Nombre completo: <?= $incluir_persona && $nombre_persona ? e($nombre_persona) : '_____________________________' ?></p>
        <p>Número de documento: _____________________________</p>
        <p>Código (si aplica): _____________________________</p>
        <p>Se remite fotografía para reconocimiento: SÍ ☐ &nbsp; NO ☐</p>
    </div>

    <p>Solicitamos que esta información sea socializada y verificada por el equipo de
    vigilancia, y que se niegue el ingreso a esta persona. En caso de detectarse su
    presencia, se debe informar de inmediato a esta Oficina y a la autoridad
    correspondiente.</p>

    <p>Esta solicitud tiene como única finalidad proteger la integridad y seguridad
    de integrantes de nuestra comunidad universitaria. La medida se adopta al
    conocerse la situación y <strong>no implica prejuzgamiento</strong>
    (Ac. 002 art. 22).</p>

<?php else: ?>
    <!-- ══ Formato DBI-811 · medida académica / de acompañamiento ══ -->
    <p>En el marco de las acciones de orientación y acompañamiento integral
    adelantadas por la Oficina de Bienestar Universitario, a través del Programa de
    Derechos Humanos y Equidad de Género, respetuosamente nos permitimos solicitar
    su colaboración para gestionar la medida
    <strong><?= e(mb_strtolower($medida['medida_nombre'])) ?></strong>
    <?php if ($incluir_persona && $nombre_persona): ?>
        respecto de la persona estudiante <strong><?= e($nombre_persona) ?></strong>
    <?php endif; ?>
    en el caso del radicado en referencia.</p>

    <p>La presente solicitud se formula atendiendo a circunstancias particulares
    identificadas durante el acompañamiento institucional que actualmente se brinda,
    con el propósito de favorecer la continuidad del proceso académico y evitar
    afectaciones adicionales derivadas de la situación objeto de atención.</p>

    <?php if (!empty($medida['detalle_ejecucion'])): ?>
        <div class="datos-persona">
            <p><strong>Datos para la gestión:</strong> <?= e($medida['detalle_ejecucion']) ?></p>
        </div>
    <?php endif; ?>

    <p>Esta gestión se realiza en desarrollo de las medidas institucionales de
    acompañamiento, protección y no repetición. En particular, el
    <strong>Acuerdo 003 del 21 de abril de 2026</strong> del Consejo Superior
    Universitario contempla la adopción de medidas de protección y no repetición, y
    la presente medida se fundamenta en <strong><?= e($medida['fundamento']) ?></strong>,
    con el propósito de garantizar los derechos de las personas y evitar
    afectaciones, represalias o confrontaciones durante las actuaciones
    institucionales.</p>

    <p>Agradecemos realizar las gestiones necesarias para autorizar y materializar la
    medida a la mayor brevedad posible, e informar a la Oficina de Bienestar
    Universitario sobre la gestión realizada o sobre cualquier requerimiento adicional
    estrictamente necesario para su materialización.</p>
<?php endif; ?>

    <div class="plazo">
        <b>Plazo de implementación:</b> <?= e(format_date($medida['fecha_limite'])) ?>
        · La constancia de cumplimiento se registra en el sistema Botón Violeta
        (Órdenes de medida), donde su dependencia tiene el compromiso correspondiente.
    </div>

    <p>Se recuerda la <strong>prohibición de retaliaciones</strong> contra quien
    denuncia, la persona afectada o quienes declaren (Ac. 003 art. 18).</p>
</div>

<div class="reserva">
    <strong>Reserva.</strong> Este oficio hace parte de una actuación reservada
    (Ley 1581 de 2012; Ac. 003 de 2026, art. 47). La información se entrega
    únicamente para ejecutar la medida; su divulgación a personas distintas de las
    estrictamente necesarias compromete la responsabilidad de quien la hace.
</div>

<p>Cordialmente,</p>

<div class="firma">
    <?php if ($firma_generador): ?>
        <img src="<?= $firma_generador ?>" alt="Firma">
    <?php else: ?>
        <div style="height: 55px;"></div>
    <?php endif; ?>
    <div class="linea">
        <strong><?= e(mb_strtoupper($usuario_actual['nombre'])) ?></strong><br>
        <?= e($cargo_generador) ?><br>
        Universidad Distrital Francisco José de Caldas
    </div>
</div>

<table class="proyecto">
    <tr><th></th><th>NOMBRE</th><th>CARGO</th><th>FIRMA</th></tr>
    <tr>
        <th>Proyectó</th>
        <td><?= e($usuario_actual['nombre']) ?></td>
        <td><?= e($cargo_generador) ?></td>
        <td><?php if ($firma_generador): ?><img src="<?= $firma_generador ?>" alt="Firma de quien proyecta"><?php endif; ?></td>
    </tr>
</table>

<p class="pie">
    Generado por <?= e($usuario_actual['nombre']) ?> el <?= e($momento) ?> ·
    Emisión registrada en la auditoría del sistema · Línea 6013238309 ·
    vbgbienestarud@udistrital.edu.co
</p>

</body>
</html>
<?php
exit; // sin layout: el oficio es un documento, no una pantalla del panel
