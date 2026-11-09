<?php
/**
 * MÓDULO: PORTAL · Acceso web centralizado
 *
 * Fase 7 · Ac. 002 art. 19 (acceso web centralizado, botones de emergencia y
 * caja de herramientas pedagógicas) y art. 39 (memorial digital).
 *
 * Amplía el alcance del producto: de «sistema de denuncias» a «acceso web
 * centralizado de género». Una persona que llega aquí puede no querer
 * denunciar todavía —o nunca— y aun así necesitar información, rutas de
 * apoyo o simplemente saber que la Universidad tiene una posición.
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

$seccion = input_get('seccion', 'inicio');

$secciones_validas = ['inicio', 'instituciones', 'normativa', 'privacidad', 'memorial'];

if (!in_array($seccion, $secciones_validas, true)) {
    $seccion = 'inicio';
}

$tipos_institucion = [
    'policia'       => 'Policía',
    'fiscalia'      => 'Fiscalía',
    'comisaria'     => 'Comisaría de Familia',
    'hospital'      => 'Servicios de salud',
    'centro_apoyo'  => 'Centros de apoyo',
    'otro'          => 'Otras entidades',
];

$title = 'Portal de acompañamiento';
ob_start();
?>

<!-- Encabezado -->
<div class="bg-gradient-to-r from-purple-700 to-purple-900 text-white py-10 mb-8 rounded-lg shadow-xl">
    <div class="max-w-4xl mx-auto text-center px-6">
        <h1 class="text-3xl md:text-4xl font-bold mb-3">Estamos contigo</h1>
        <p class="text-lg text-purple-100">
            Información, rutas de apoyo y acompañamiento en casos de violencias
            basadas en género, violencia sexual y discriminación.
        </p>
    </div>
</div>

<!-- Emergencia · Ac. 002 art. 19 -->
<div class="max-w-4xl mx-auto mb-8">
    <div class="bg-red-50 border-l-4 border-red-600 p-5 rounded" role="alert">
        <h2 class="text-lg font-bold text-red-900 mb-2">¿Estás en peligro ahora mismo?</h2>
        <div class="grid sm:grid-cols-2 gap-3">
            <a href="tel:123" class="flex items-center gap-3 bg-red-600 hover:bg-red-700 text-white px-5 py-4 rounded-lg font-semibold transition">
                <span class="text-2xl" aria-hidden="true">📞</span>
                <span>Llamar al <strong>123</strong><br>
                    <span class="text-sm font-normal text-red-100">Línea de emergencias</span></span>
            </a>
            <a href="tel:155" class="flex items-center gap-3 bg-purple-700 hover:bg-purple-800 text-white px-5 py-4 rounded-lg font-semibold transition">
                <span class="text-2xl" aria-hidden="true">💜</span>
                <span>Llamar al <strong>155</strong><br>
                    <span class="text-sm font-normal text-purple-100">Orientación a mujeres víctimas de violencia</span></span>
            </a>
        </div>
    </div>
</div>

<!-- Navegación del portal -->
<nav class="max-w-4xl mx-auto mb-8" aria-label="Secciones del portal">
    <ul class="flex flex-wrap gap-2">
        <?php
        $pestanas = [
            'inicio'        => 'Caja de herramientas',
            'instituciones' => 'Instituciones de apoyo',
            'normativa'     => 'Normativa',
            'memorial'      => 'Memorial',
            'privacidad'    => 'Tratamiento de datos',
        ];
        foreach ($pestanas as $clave => $etiqueta):
            $activa = $seccion === $clave;
        ?>
            <li>
                <a href="?page=portal&amp;seccion=<?= e($clave) ?>"
                   <?= $activa ? 'aria-current="page"' : '' ?>
                   class="inline-block px-4 py-2 rounded-lg transition <?= $activa
                       ? 'bg-purple-700 text-white font-semibold'
                       : 'bg-white text-purple-800 hover:bg-purple-50 border border-purple-200' ?>">
                    <?= e($etiqueta) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>

<div class="max-w-4xl mx-auto">

<?php if ($seccion === 'inicio'): ?>
    <section aria-labelledby="titulo-herramientas">
        <h2 id="titulo-herramientas" class="text-2xl font-bold text-gray-900 mb-4">Caja de herramientas</h2>

        <?php $recursos = recursos_portal('caja_herramientas'); ?>

        <?php if (empty($recursos)): ?>
            <div class="bg-white border border-gray-200 rounded-lg p-6">
                <p class="text-gray-700">
                    Los materiales pedagógicos estarán disponibles próximamente.
                    Mientras tanto, puedes comunicarte con el Equipo de Derechos Humanos
                    y Equidad de Género al
                    <a href="tel:+576013238309" class="text-purple-800 underline font-medium">601 323 8309</a>
                    o escribir a
                    <a href="mailto:vbgbienestarud@udistrital.edu.co" class="text-purple-800 underline font-medium">vbgbienestarud@udistrital.edu.co</a>.
                </p>
            </div>
        <?php else: ?>
            <ul class="grid md:grid-cols-2 gap-4">
                <?php foreach ($recursos as $recurso): ?>
                <li class="bg-white border border-gray-200 rounded-lg p-5">
                    <h3 class="font-bold text-gray-900 mb-1"><?= e($recurso['titulo']) ?></h3>
                    <p class="text-gray-700 text-sm mb-3"><?= e($recurso['descripcion'] ?? '') ?></p>
                    <?php if (!empty($recurso['url'])): ?>
                        <a href="<?= e($recurso['url']) ?>" class="text-purple-800 underline font-medium">
                            Abrir recurso
                        </a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h2 class="text-2xl font-bold text-gray-900 mt-10 mb-4">¿Cómo podemos acompañarte?</h2>
        <div class="grid md:grid-cols-3 gap-4">
            <div class="bg-white border border-gray-200 rounded-lg p-5">
                <h3 class="font-bold text-gray-900 mb-2">Presentar un caso</h3>
                <p class="text-gray-700 text-sm mb-3">
                    Con tus datos de contacto, para que el equipo pueda acompañarte
                    y hacer seguimiento.
                </p>
                <a href="?page=denuncia-publica" class="text-purple-800 underline font-medium">Ir al formulario</a>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-5">
                <h3 class="font-bold text-gray-900 mb-2">Consultar tu caso</h3>
                <p class="text-gray-700 text-sm mb-3">
                    Con el radicado que recibiste y un código de verificación.
                </p>
                <a href="?page=seguimiento" class="text-purple-800 underline font-medium">Consultar estado</a>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-5">
                <h3 class="font-bold text-gray-900 mb-2">Solo quiero información</h3>
                <p class="text-gray-700 text-sm mb-3">
                    No tienes que denunciar para pedir orientación. Puedes llamar
                    o escribir sin dar tu nombre.
                </p>
                <a href="tel:+576013238309" class="text-purple-800 underline font-medium">601 323 8309</a>
            </div>
        </div>
    </section>

<?php elseif ($seccion === 'instituciones'): ?>
    <section aria-labelledby="titulo-instituciones">
        <h2 id="titulo-instituciones" class="text-2xl font-bold text-gray-900 mb-2">Instituciones de apoyo</h2>
        <p class="text-gray-700 mb-6">
            Entidades externas a la Universidad que pueden acompañarte
            (Acuerdo 002 de 2026, art. 24).
        </p>

        <?php
        $instituciones = instituciones_apoyo();
        $por_tipo = [];
        foreach ($instituciones as $inst) {
            $por_tipo[$inst['tipo']][] = $inst;
        }
        ?>

        <?php if (empty($instituciones)): ?>
            <p class="bg-white border border-gray-200 rounded-lg p-6 text-gray-700">
                El directorio se está actualizando. Comunícate con la línea
                <a href="tel:+576013238309" class="text-purple-800 underline">601 323 8309</a>.
            </p>
        <?php else: ?>
            <?php foreach ($por_tipo as $tipo => $lista): ?>
                <h3 class="text-lg font-bold text-purple-900 mt-6 mb-3">
                    <?= e($tipos_institucion[$tipo] ?? ucfirst($tipo)) ?>
                </h3>
                <ul class="space-y-3">
                    <?php foreach ($lista as $inst): ?>
                    <li class="bg-white border border-gray-200 rounded-lg p-5">
                        <h4 class="font-bold text-gray-900"><?= e($inst['nombre']) ?></h4>
                        <?php if (!empty($inst['servicios_ofrecidos'])): ?>
                            <p class="text-gray-700 text-sm mt-1"><?= e($inst['servicios_ofrecidos']) ?></p>
                        <?php endif; ?>
                        <dl class="mt-3 text-sm space-y-1">
                            <?php if (!empty($inst['telefono'])): ?>
                            <div class="flex gap-2">
                                <dt class="font-medium text-gray-700">Teléfono:</dt>
                                <dd><a href="tel:<?= e(preg_replace('/\D+/', '', $inst['telefono'])) ?>"
                                       class="text-purple-800 underline"><?= e($inst['telefono']) ?></a></dd>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($inst['direccion'])): ?>
                            <div class="flex gap-2">
                                <dt class="font-medium text-gray-700">Dirección:</dt>
                                <dd class="text-gray-800"><?= e($inst['direccion']) ?></dd>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($inst['horario_atencion'])): ?>
                            <div class="flex gap-2">
                                <dt class="font-medium text-gray-700">Horario:</dt>
                                <dd class="text-gray-800"><?= e($inst['horario_atencion']) ?></dd>
                            </div>
                            <?php endif; ?>
                        </dl>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

<?php elseif ($seccion === 'normativa'): ?>
    <section aria-labelledby="titulo-normativa">
        <h2 id="titulo-normativa" class="text-2xl font-bold text-gray-900 mb-4">Normativa aplicable</h2>
        <ul class="space-y-3">
            <?php
            $normas = [
                ['Acuerdo CSU 002 de 2026', 'Medidas pedagógicas, de acción inmediata, restaurativas y de no revictimización.'],
                ['Acuerdo CSU 003 de 2026', 'Procedimiento disciplinario con enfoque de género.'],
                ['Sentencia T-210 de 2023', 'Corte Constitucional. Origen de los Acuerdos y de su seguimiento.'],
                ['Ley 2365 de 2024', 'Prevención y sanción del acoso sexual.'],
                ['Ley 1257 de 2008', 'Sensibilización, prevención y sanción de la violencia contra las mujeres.'],
                ['Ley 1581 de 2012', 'Protección de datos personales.'],
            ];
            foreach ($normas as [$titulo, $descripcion]):
            ?>
            <li class="bg-white border border-gray-200 rounded-lg p-5">
                <h3 class="font-bold text-gray-900"><?= e($titulo) ?></h3>
                <p class="text-gray-700 text-sm mt-1"><?= e($descripcion) ?></p>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>

<?php elseif ($seccion === 'memorial'): ?>
    <section aria-labelledby="titulo-memorial">
        <h2 id="titulo-memorial" class="text-2xl font-bold text-gray-900 mb-2">Memorial digital</h2>
        <p class="text-gray-700 mb-6">
            Espacio de memoria y reparación simbólica (Acuerdo 002 de 2026, art. 39).
        </p>
        <?php $memorial = recursos_portal('memorial'); ?>
        <?php if (empty($memorial)): ?>
            <p class="bg-white border border-gray-200 rounded-lg p-6 text-gray-700">
                El memorial se construirá con la participación de la comunidad universitaria.
            </p>
        <?php else: ?>
            <ul class="space-y-4">
                <?php foreach ($memorial as $entrada): ?>
                <li class="bg-white border-l-4 border-purple-600 rounded-lg p-5">
                    <h3 class="font-bold text-gray-900"><?= e($entrada['titulo']) ?></h3>
                    <p class="text-gray-700 mt-1"><?= e($entrada['descripcion'] ?? '') ?></p>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

<?php elseif ($seccion === 'privacidad'): ?>
    <section aria-labelledby="titulo-privacidad">
        <h2 id="titulo-privacidad" class="text-2xl font-bold text-gray-900 mb-4">Tratamiento de datos personales</h2>
        <?php
        $aviso = db_select_one('SELECT * FROM avisos_privacidad WHERE vigente = 1 ORDER BY id DESC LIMIT 1');
        ?>
        <?php if ($aviso): ?>
            <article class="bg-white border border-gray-200 rounded-lg p-6 prose max-w-none">
                <p class="text-sm text-gray-600 mb-4">
                    Versión <?= e($aviso['version']) ?> ·
                    publicada el <?= format_date($aviso['publicado_at']) ?>
                </p>
                <div class="text-gray-800 whitespace-pre-line"><?= e($aviso['texto']) ?></div>
            </article>
        <?php else: ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-500 p-5 rounded">
                <p class="text-yellow-900">
                    La política de tratamiento de datos está en elaboración por la
                    Oficina Asesora Jurídica. Mientras tanto, la información que
                    entregues se trata conforme a la Ley 1581 de 2012 y solo la
                    conoce el equipo profesional autorizado.
                </p>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/guest.php';
