<?php
/**
 * MÓDULO: DENUNCIAS · VERSIÓN IMPRIMIBLE DEL EXPEDIENTE
 *
 * Cierra la otra mitad del hallazgo H-19: el botón «Imprimir Reporte» era un
 * `alert('próximamente')`.
 *
 * Un expediente impreso sale del sistema y de todos sus controles: ya no hay
 * registro de quién lo lee, ni forma de retirarlo. Por eso esta pantalla:
 *
 *   · aplica el MISMO control de acceso que la vista en línea (RN-02, RN-08,
 *     RN-12) — no basta con haber podido abrir el expediente antes;
 *   · aplica la MISMA reserva por campo: lo que no se puede ver en pantalla
 *     tampoco se imprime;
 *   · no incluye datos de contacto en claro: quien los necesite debe revelarlos
 *     en línea, donde queda constancia;
 *   · estampa una marca de agua con el nombre de quien imprime y la fecha, de
 *     modo que una copia filtrada sea trazable;
 *   · deja registro en la auditoría ANTES de emitir una sola línea.
 *
 * No usa el layout de la aplicación: no debe llevar menú, ni barra lateral, ni
 * botón de salida rápida.
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_auth();

$denuncia_id = input_get('id', 0, 'int');

if (!$denuncia_id) {
    redirect_with_message(url('?page=denuncias'), 'Denuncia no encontrada.', 'error');
}

$usuario_actual = auth_user();

$denuncia = db_select_one(
    'SELECT * FROM denuncias WHERE id = ? LIMIT 1',
    [$denuncia_id]
);

// Mismo mensaje para «no existe» y «no puedes verla»: distinguirlos confirma
// la existencia del expediente, que está bajo reserva (Ac. 003 art. 47)
if (!$denuncia
    || !puede_ver_expediente($usuario_actual['id'], $usuario_actual['rol'], $denuncia_id)) {

    audit_log($usuario_actual['id'], 'impresion_expediente_denegada', 'denuncias', [
        'rol' => $usuario_actual['rol'],
    ], $denuncia_id);

    redirect_with_message(url('?page=denuncias'), 'Denuncia no encontrada.', 'error');
}

// RN-13 · la impresión se audita antes de generarse. Si el proceso muere a
// mitad de camino, el registro de que se intentó ya está escrito.
audit_log($usuario_actual['id'], 'expediente_impreso', 'denuncias', [
    'rol'   => $usuario_actual['rol'],
    'etapa' => $denuncia['etapa_procesal'] ?? null,
], $denuncia_id);

// Mismo formato que el resto de la interfaz. Estaba en ISO mientras las fechas
// de al lado salían en dd/mm/aaaa: dos formatos en el mismo documento obligan
// a quien lo lee a decidir cuál está mirando.
$momento = date('d/m/Y H:i');

/** Estados de medida en lenguaje legible, no el identificador del ENUM */
$estados_medida = [
    'solicitada'   => 'Solicitada',
    'notificada'   => 'Notificada',
    'en_ejecucion' => 'En ejecución',
    'implementada' => 'Implementada',
    'incumplida'   => 'Incumplida',
    'levantada'    => 'Levantada',
    'rechazada'    => 'Rechazada',
];

$tipos_violencia = [
    'fisica'      => 'Violencia física',
    'psicologica' => 'Violencia psicológica',
    'sexual'      => 'Violencia sexual',
    'economica'   => 'Violencia económica',
    'verbal'      => 'Violencia verbal',
    'otro'        => 'Otro',
];

$estados = [
    'nueva'      => 'Nueva',
    'en_proceso' => 'En proceso',
    'resuelta'   => 'Resuelta',
    'archivada'  => 'Archivada',
];

$etapas = [
    'recepcion'         => 'Recepción',
    'indagacion_previa' => 'Indagación previa',
    'investigacion'     => 'Investigación',
    'juzgamiento'       => 'Juzgamiento',
    'seguimiento'       => 'Seguimiento',
    'cerrado'           => 'Cerrado',
];

// El relato es el dato más sensible del expediente (RN-03). Solo se imprime
// para quien tiene competencia sobre el fondo del caso.
$puede_ver_relato = puede_ver_campo(
    $usuario_actual['id'],
    $usuario_actual['rol'],
    $denuncia_id,
    'descripcion'
);

// Personas del caso: se imprimen los papeles y los nombres, nunca los
// contactos. Un teléfono en un papel impreso es una vía de contacto directo
// con la persona afectada (RN-05).
//
// Y solo si el rol tiene acceso al grupo de campos «partes»: la misma reserva
// que rige en pantalla rige aquí.
$puede_ver_partes = puede_ver_campo(
    $usuario_actual['id'],
    $usuario_actual['rol'],
    $denuncia_id,
    'denunciante_nombre'
);

$personas = [];

if ($puede_ver_partes && function_exists('personas_del_caso')) {
    foreach (personas_del_caso($denuncia_id) as $persona) {
        $personas[] = [
            'papel'  => $persona['papel'],
            'nombre' => $persona['nombre'] ?: '(sin registrar)',
        ];
    }
}

$papeles = [
    'denunciante' => 'Persona denunciante',
    'afectada'    => 'Persona afectada',
    'denunciada'  => 'Persona denunciada',
    'testigo'     => 'Testigo',
];

// Actuaciones. Las marcadas como privadas se imprimen —esta copia es interna—
// pero se señalan: quien imprime debe saber qué está sacando del sistema.
$seguimientos = db_select(
    'SELECT contenido, privado, created_at FROM seguimientos
      WHERE denuncia_id = ? ORDER BY created_at',
    [$denuncia_id]
) ?: [];

$medidas = [];
if (function_exists('medidas_del_caso')) {
    $medidas = medidas_del_caso($denuncia_id) ?: [];
}

// A partir de aquí se emite HTML propio: sin layout, sin menú, sin salida
// rápida. No se llama a `require app.php`.
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Expediente <?= e($denuncia['codigo']) ?></title>
    <style>
        /* Hoja de estilo propia y embebida: este documento debe imprimirse
           igual aunque no haya red y aunque el CDN esté caído. */
        *      { box-sizing: border-box; }
        body   { font-family: Georgia, 'Times New Roman', serif; font-size: 11pt;
                 line-height: 1.5; color: #1a1a1a; margin: 0; padding: 2rem;
                 max-width: 19cm; margin-inline: auto; background: #fff; }
        h1     { font-size: 16pt; margin: 0 0 .25rem; }
        h2     { font-size: 12pt; margin: 1.75rem 0 .5rem; padding-bottom: .2rem;
                 border-bottom: 1px solid #999; page-break-after: avoid; }
        p, td, th, dd, dt, li { font-size: 11pt; }
        .cabecera     { border-bottom: 3px solid #6b21a8; padding-bottom: 1rem; margin-bottom: 1.5rem; }
        .institucion  { font-size: 10pt; color: #444; margin: 0; }
        .radicado     { font-family: 'Courier New', monospace; font-size: 14pt;
                        font-weight: bold; letter-spacing: .05em; }
        .reserva      { border: 2px solid #6b21a8; background: #faf5ff; color: #4a1d6e;
                        padding: .75rem 1rem; margin: 1rem 0 1.5rem; font-size: 10pt; }
        .reserva strong { display: block; margin-bottom: .25rem; }
        dl.datos      { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem 1.5rem; margin: 0; }
        dl.datos dt   { font-size: 9pt; text-transform: uppercase; letter-spacing: .04em;
                        color: #555; margin: 0; }
        dl.datos dd   { margin: .1rem 0 0; font-weight: 600; }
        .relato       { white-space: pre-line; text-align: justify;
                        border-left: 3px solid #ddd; padding-left: 1rem; }
        .oculto       { color: #666; font-style: italic; border-left: 3px solid #ddd;
                        padding-left: 1rem; }
        table         { width: 100%; border-collapse: collapse; margin-top: .5rem; }
        th            { text-align: left; font-size: 9pt; text-transform: uppercase;
                        letter-spacing: .04em; color: #555; border-bottom: 1px solid #999;
                        padding: .4rem .5rem; }
        td            { padding: .4rem .5rem; border-bottom: 1px solid #e5e5e5;
                        vertical-align: top; }
        .vacio        { color: #666; font-style: italic; }
        .pie          { margin-top: 2.5rem; padding-top: 1rem; border-top: 1px solid #999;
                        font-size: 9pt; color: #444; }
        /* Marca de agua
         *
         * `z-index: -1` no basta: sin un fondo opaco por delante, el texto se
         * pinta encima del contenido y en la primera prueba tapaba la tabla de
         * medidas hasta hacerla difícil de leer. Un documento que sirve de
         * constancia tiene que leerse sin esfuerzo; la marca es para disuadir y
         * rastrear, no para estorbar.
         *
         * Ahora es mucho más tenue, más pequeña y se ancla abajo, lejos de las
         * tablas. Sigue identificando a quien imprimió y cuándo. */
        .marca        { position: fixed; left: 0; right: 0; bottom: 18%;
                        z-index: -1; display: flex;
                        align-items: center; justify-content: center;
                        transform: rotate(-24deg); font-size: 30pt; font-weight: bold;
                        color: rgba(107, 33, 168, .05); pointer-events: none;
                        text-align: center; line-height: 1.4; letter-spacing: .05em; }
        .barra        { text-align: center; margin-bottom: 1.5rem; }
        .barra button, .barra a { font-family: inherit; font-size: 10pt; padding: .5rem 1.25rem;
                        margin: 0 .25rem; border: 1px solid #6b21a8; border-radius: 4px;
                        background: #6b21a8; color: #fff; cursor: pointer;
                        text-decoration: none; display: inline-block; }
        .barra a      { background: #fff; color: #6b21a8; }

        @media print {
            body   { padding: 0; }
            .barra { display: none; }
            h2     { page-break-after: avoid; }
            tr     { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="marca" aria-hidden="true">
    RESERVADO<br><?= e($usuario_actual['nombre']) ?><br><?= e($momento) ?>
</div>

<div class="barra">
    <button type="button" onclick="window.print()">Imprimir</button>
    <a href="?page=denuncia-ver&amp;id=<?= (int)$denuncia['id'] ?>">Volver al expediente</a>
</div>

<header class="cabecera">
    <p class="institucion">Universidad Distrital Francisco José de Caldas</p>
    <p class="institucion">Ruta de atención de violencias basadas en género · Botón Violeta</p>
    <h1>Expediente <span class="radicado"><?= e($denuncia['codigo']) ?></span></h1>
</header>

<div class="reserva">
    <strong>Documento reservado</strong>
    Contiene información sometida a reserva legal (Ley 1581 de 2012; Acuerdo 003
    de 2026, art. 47). Su divulgación, reproducción o traslado a personas
    distintas de las competentes compromete la responsabilidad de quien lo hace.
    Esta copia fue generada por <strong><?= e($usuario_actual['nombre']) ?></strong>
    el <?= e($momento) ?> y su emisión quedó registrada en la auditoría del sistema.
</div>

<h2>Identificación</h2>
<dl class="datos">
    <div>
        <dt>Radicado</dt>
        <dd><?= e($denuncia['codigo']) ?></dd>
    </div>
    <div>
        <dt>Tipo de violencia</dt>
        <dd><?= e($tipos_violencia[$denuncia['tipo_violencia']] ?? $denuncia['tipo_violencia']) ?></dd>
    </div>
    <div>
        <dt>Estado</dt>
        <dd><?= e($estados[$denuncia['estado']] ?? $denuncia['estado']) ?></dd>
    </div>
    <div>
        <dt>Etapa procesal</dt>
        <dd><?= e($etapas[$denuncia['etapa_procesal'] ?? ''] ?? 'No registrada') ?></dd>
    </div>
    <div>
        <dt>Fecha de los hechos</dt>
        <dd><?= e(format_date($denuncia['fecha_hechos'])) ?></dd>
    </div>
    <div>
        <dt>Fecha de radicación</dt>
        <dd><?= e(format_datetime($denuncia['created_at'])) ?></dd>
    </div>
    <div>
        <dt>Lugar de los hechos</dt>
        <dd><?= e($denuncia['lugar_hechos'] ?: 'No especificado') ?></dd>
    </div>
</dl>

<h2>Relato de los hechos</h2>
<?php if ($puede_ver_relato): ?>
    <p class="relato"><?= e($denuncia['descripcion']) ?></p>
<?php else: ?>
    <p class="oculto">
        El relato no se imprime en esta copia: tu rol no tiene competencia sobre
        el fondo del caso (RN-03, Ac. 003 art. 44).
    </p>
<?php endif; ?>

<h2>Personas del caso</h2>
<?php if (!$puede_ver_partes): ?>
    <p class="oculto">
        La identidad de las partes no se imprime en esta copia: tu rol no tiene
        acceso a ese grupo de campos.
    </p>
<?php elseif (empty($personas)): ?>
    <p class="vacio">No hay personas registradas con papel explícito.</p>
<?php else: ?>
    <table>
        <thead>
            <tr><th>Papel</th><th>Nombre</th></tr>
        </thead>
        <tbody>
            <?php foreach ($personas as $persona): ?>
            <tr>
                <td><?= e($papeles[$persona['papel']] ?? $persona['papel']) ?></td>
                <td><?= e($persona['nombre']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p style="font-size:9pt;color:#555;margin-top:.5rem;">
        Los datos de contacto no se imprimen. Consultarlos requiere revelarlos en
        el sistema, donde queda constancia de quién lo hizo (RN-05).
    </p>
<?php endif; ?>

<?php if (!empty($medidas)): ?>
<h2>Medidas de protección</h2>
<table>
    <thead>
        <tr><th>Medida</th><th>Estado</th><th>Dispuesta</th></tr>
    </thead>
    <tbody>
        <?php foreach ($medidas as $medida): ?>
        <tr>
            <td><?= e($medida['nombre'] ?? $medida['catalogo_codigo'] ?? '—') ?></td>
            <td><?= e($estados_medida[$medida['estado'] ?? ''] ?? ($medida['estado'] ?? '—')) ?></td>
            <td><?= e(isset($medida['created_at']) ? format_date($medida['created_at']) : '—') ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h2>Actuaciones</h2>
<?php if (empty($seguimientos)): ?>
    <p class="vacio">No hay actuaciones registradas.</p>
<?php else: ?>
    <table>
        <thead>
            <tr><th style="width:9rem;">Fecha</th><th>Actuación</th></tr>
        </thead>
        <tbody>
            <?php foreach ($seguimientos as $seguimiento): ?>
            <tr>
                <td><?= e(format_datetime($seguimiento['created_at'])) ?></td>
                <td>
                    <?php if ((int)$seguimiento['privado'] === 1): ?>
                        <strong style="color:#6b21a8;">[Nota interna]</strong>
                    <?php endif; ?>
                    <?= e($seguimiento['contenido']) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<footer class="pie">
    <p>
        Expediente <strong><?= e($denuncia['codigo']) ?></strong> ·
        impreso por <strong><?= e($usuario_actual['nombre']) ?></strong> ·
        <?= e($momento) ?>
    </p>
    <p>
        Este documento es una copia de consulta. El expediente auténtico es el que
        reposa en el sistema, con su cadena de auditoría. Si esta copia deja de ser
        necesaria, destrúyela: fuera del sistema no hay control sobre quién la lee.
    </p>
</footer>

</body>
</html>
