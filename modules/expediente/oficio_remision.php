<?php
/**
 * OFICIO DE REMISIÓN · VERSIÓN IMPRIMIBLE (v3.1 §7.2)
 *
 * Sirve a los dos regímenes:
 *   · INTERNO (OCID —antes OAAD—, decanaturas, Comité de Convivencia): cita el
 *     Protocolo E4 y el plazo real del compromiso del receptor. El paquete
 *     puede incluir acta y evidencia — los ANEXOS van relacionados con su
 *     hash; el oficio mismo nunca contiene el relato.
 *   · EXTERNO (Procuraduría, Fiscalía…): deja constancia expresa de si el
 *     relato viaja o no (RN-03: solo por decisión motivada y auditada).
 *
 * Mismo patrón que oficio_medida.php: control de acceso idéntico a la vista
 * en línea, marca de agua con quién generó y cuándo, auditoría ANTES de
 * emitir.
 *
 * ⚠ Texto en BORRADOR (v3.1 §12.4 punto 5): la redacción definitiva y quién
 *   firma las define Jurídica/Rectoría. El aviso no sale en la impresión.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_auth();

$remision_id = input_get('id', 0, 'int');

if (!$remision_id) {
    redirect_with_message(url('?page=denuncias'), 'Remisión no encontrada.', 'error');
}

$usuario_actual = auth_user();

$remision = db_select_one(
    'SELECT r.*, dr.nombre AS destino_nombre, dr.codigo AS destino_codigo,
            dr.fundamento AS destino_fundamento, dr.interno,
            d.codigo AS radicado, d.id AS denuncia_id,
            ui.nombre AS unidad_interna_nombre
       FROM remisiones_externas r
       JOIN destinos_remision dr ON dr.id = r.destino_id
       JOIN denuncias d ON d.id = r.denuncia_id
       LEFT JOIN unidades ui ON ui.id = r.unidad_interna_id
      WHERE r.id = ? LIMIT 1',
    [$remision_id]
);

// RN-02: mismo mensaje exista o no
if (!$remision
    || !puede_ver_expediente($usuario_actual['id'], $usuario_actual['rol'], (int)$remision['denuncia_id'])) {
    audit_log($usuario_actual['id'], 'acceso_expediente_denegado', 'remisiones_externas', [
        'rol' => $usuario_actual['rol'],
    ], $remision_id);
    redirect_with_message(url('?page=denuncias'), 'Remisión no encontrada.', 'error');
}

// Quien gestiona la pestaña de competencia emite el oficio
if (!in_array($usuario_actual['rol'], ['coordinacion_edheg', 'coordinador', 'abogado'], true)) {
    redirect_with_message(url('?page=denuncias'), 'Remisión no encontrada.', 'error');
}

$es_interna = (int)($remision['interno'] ?? 0) === 1;

// Destinatario: la unidad interna concreta (decanatura) o el destino del catálogo
$destinatario = $remision['unidad_interna_nombre'] ?: $remision['destino_nombre'];

// Plazo real del receptor (régimen interno): su compromiso en la bandeja
$compromiso_receptor = $es_interna
    ? db_select_one(
        'SELECT fecha_limite, descripcion FROM compromisos
          WHERE remision_id = ? AND responsable_unidad_id IS NOT NULL LIMIT 1',
        [$remision_id])
    : null;

$anexos = $remision['anexos_json'] ? (json_decode($remision['anexos_json'], true) ?: []) : [];

$tipos_texto = [
    'traslado'     => 'traslado por competencia',
    'informacion'  => 'comunicación para su información',
    'coordinacion' => 'remisión para coordinación de actuaciones',
];

$momento = date('d/m/Y H:i');

// RN-13 · auditoría antes de emitir
audit_log($usuario_actual['id'], 'oficio_remision_generado', 'remisiones_externas', [
    'rol'    => $usuario_actual['rol'],
    'motivo' => $remision['destino_codigo'],
], $remision_id);

$meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
          'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Oficio de remisión · <?= e($remision['radicado']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Georgia, 'Times New Roman', serif; font-size: 11pt;
               color: #1a1a1a; max-width: 19cm; margin: 0 auto; padding: 1.5rem;
               line-height: 1.55; }
        .cabecera { text-align: center; border-bottom: 3px double #6b21a8;
                    padding-bottom: .75rem; margin-bottom: 1.25rem; }
        .institucion { font-size: 10pt; letter-spacing: .08em; text-transform: uppercase; }
        h1 { font-size: 13pt; margin-top: .5rem; }
        .radicado { font-family: 'Courier New', monospace; }
        .campo { margin: .8rem 0; }
        .campo b { display: inline-block; min-width: 11.5em; }
        .cuerpo { margin: 1.25rem 0; text-align: justify; }
        .plazo { border: 1px solid #6b21a8; padding: .6rem .9rem; margin: 1rem 0;
                 background: #faf5ff; }
        .anexos { width: 100%; border-collapse: collapse; margin: .8rem 0; font-size: 10pt; }
        .anexos th, .anexos td { border: 1px solid #bbb; padding: .35rem .6rem; text-align: left; }
        .anexos th { background: #f3f4f6; }
        .hash { font-family: 'Courier New', monospace; font-size: 8pt; }
        .reserva { font-size: 9pt; color: #444; border: 1px solid #bbb;
                   padding: .6rem .9rem; margin: 1.25rem 0; }
        .firma { margin-top: 3rem; }
        .firma .linea { border-top: 1px solid #333; width: 22em; padding-top: .3rem; }
        .pie { margin-top: 2rem; padding-top: .75rem; border-top: 1px solid #999;
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
    <a href="?page=expediente&amp;id=<?= (int)$remision['denuncia_id'] ?>&amp;tab=competencia">Volver a competencia</a>
</div>

<div class="borrador">
    ⚠ Texto de oficio en BORRADOR: la redacción definitiva y la firma competente
    las define Jurídica/Rectoría (pendiente §12.4). Este aviso no sale en la impresión.
</div>

<header class="cabecera">
    <p class="institucion">Universidad Distrital Francisco José de Caldas</p>
    <p class="institucion">Centro de Bienestar Institucional · Equipo de Derechos Humanos y Equidad de Género</p>
    <h1>Oficio de remisión · Radicado <span class="radicado"><?= e($remision['radicado']) ?></span></h1>
</header>

<p>Bogotá D.C., <?= e(date('d')) ?> de <?= e($meses[(int)date('n')]) ?> de <?= e(date('Y')) ?></p>

<div class="campo"><b>Destinatario:</b> <?= e($destinatario) ?></div>
<div class="campo"><b>Asunto:</b> <?= e(ucfirst($tipos_texto[$remision['tipo']] ?? $remision['tipo'])) ?>
    · caso <span class="radicado"><?= e($remision['radicado']) ?></span></div>

<div class="cuerpo">
    <?php if ($es_interna): ?>
        <p>En el marco de la ruta de atención institucional (Protocolo VBG, etapa E4)
        y del sistema interno de seguimiento y atención de casos (Acuerdo CSU 002 de
        2026, art. 25 lit. d), el Centro de Bienestar Institucional remite a su
        despacho la actuación del radicado en referencia, con fundamento en:
        <strong><?= e($remision['destino_fundamento']) ?></strong>.</p>
    <?php else: ?>
        <p>En cumplimiento del deber de articulación institucional (Acuerdo CSU 003
        de 2026, art. 3 num. 13) y con fundamento en
        <strong><?= e($remision['destino_fundamento']) ?></strong>, se remite a su
        despacho la información del caso del radicado en referencia.</p>
    <?php endif; ?>

    <div class="campo" style="margin-left: 1.5rem;"><b>Motivación:</b> <?= e($remision['motivacion']) ?></div>

    <?php if (!empty($anexos)): ?>
        <p><b>Anexos que integran el paquete</b> (cada uno sellado con hash SHA-256
        para verificación de integridad, Ac. 003 art. 45):</p>
        <table class="anexos">
            <tr><th>#</th><th>Descripción</th><th>Sello de integridad</th></tr>
            <?php foreach ($anexos as $n => $anexo): ?>
                <tr>
                    <td><?= (int)$n + 1 ?></td>
                    <td><?= e($anexo['descripcion'] ?? 'Documento ' . ((int)$n + 1)) ?></td>
                    <td class="hash"><?= e($anexo['hash'] ?? 'sin sello') ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <p>
        <?php if ((int)$remision['relato_incluido'] === 1): ?>
            El paquete <strong>incluye el relato de los hechos</strong> por decisión
            motivada y auditada de esta coordinación
            (<?= e($remision['motivacion_relato'] ?? 'motivación registrada en el sistema') ?>).
            Su tratamiento queda sujeto a la reserva indicada abajo.
        <?php else: ?>
            El presente oficio y su paquete <strong>no incluyen el relato de los
            hechos</strong> ni la tipología del caso: la información detallada
            permanece bajo reserva en el sistema institucional y solo se entrega
            mediante decisión motivada de esta coordinación.
        <?php endif; ?>
    </p>

    <?php if ($es_interna && $compromiso_receptor): ?>
        <div class="plazo">
            <b>Actuación a cargo de su despacho:</b>
            <?= e($compromiso_receptor['descripcion']) ?><br>
            <b>Plazo:</b> <?= e(format_date($compromiso_receptor['fecha_limite'])) ?>
            · La respuesta o constancia se registra en el sistema
            (Botón Violeta → Órdenes de medida), donde su dependencia tiene el
            compromiso correspondiente, sin necesidad de acceder al expediente.
        </div>
    <?php elseif (!$es_interna): ?>
        <p>Agradecemos informar el número de radicación que asigne su entidad, para
        la trazabilidad del seguimiento institucional.</p>
    <?php endif; ?>

    <p>Se recuerda la <strong>prohibición de retaliaciones</strong> contra quien
    denuncia, la persona afectada o quienes declaren (Ac. 003 art. 18).</p>
</div>

<div class="reserva">
    <strong>Reserva.</strong> Esta actuación es reservada hasta la formulación de
    cargos o el archivo definitivo (Ac. 003 de 2026, art. 47; Ley 1581 de 2012).
    La información remitida solo puede usarse para el trámite de competencia de su
    despacho; su divulgación a personas distintas compromete la responsabilidad de
    quien la hace.
</div>

<div class="firma">
    <div class="linea">
        Coordinación · Equipo de Derechos Humanos y Equidad de Género<br>
        Centro de Bienestar Institucional
    </div>
</div>

<p class="pie">
    Generado por <?= e($usuario_actual['nombre']) ?> el <?= e($momento) ?> ·
    Decisión de remisión del <?= e(format_date($remision['fecha_decision'])) ?> ·
    Emisión registrada en la auditoría del sistema · Línea 6013238309 ·
    vbgbienestarud@udistrital.edu.co
</p>

</body>
</html>
<?php
exit; // sin layout: es un documento, no una pantalla del panel
