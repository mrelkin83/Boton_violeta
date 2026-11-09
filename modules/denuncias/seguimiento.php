<?php
/**
 * MÓDULO: SEGUIMIENTO PÚBLICO DE DENUNCIAS
 *
 * Fase 2 · CLAUDE.md §10.2
 *
 * Flujo de tres pasos:
 *   1. La persona introduce su radicado  → respuesta SIEMPRE idéntica
 *   2. Introduce el código de un solo uso → 10 min, 3 intentos
 *   3. Sesión efímera de 15 min ligada a ese radicado
 *
 * Corrige H-06. Antes bastaba el radicado —enumerable— para leer la
 * descripción de los hechos, el lugar y el tipo de violencia de casos ajenos.
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Las vistas de seguimiento no deben quedar en la caché del navegador:
// otra persona con acceso al mismo equipo no debe recuperarlas con el botón atrás
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
}

$paso           = 'radicado';   // radicado | codigo | caso
$aviso          = null;
$aviso_tipo     = 'info';
$radicado_actual = '';
$caso           = null;

// Sesión de consulta ya abierta
$sesion = sesion_consulta_vigente();

if ($sesion !== null) {
    $paso            = 'caso';
    $radicado_actual = $sesion['radicado'];
    $caso            = proyeccion_publica_caso($sesion['denuncia_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
// Procesamiento
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $accion = input('accion', '');

    if ($accion === 'salir') {
        cerrar_sesion_consulta();
        redirect(url('?page=seguimiento'));
    }

    if ($accion === 'solicitar') {
        $radicado_actual = strtoupper(trim(input('radicado', '')));

        if ($radicado_actual === '') {
            $aviso = 'Escribe el radicado que recibiste al registrar tu caso.';
            $aviso_tipo = 'error';
        } else {
            $resultado = solicitar_codigo_seguimiento($radicado_actual);

            $aviso = $resultado['mensaje'];
            $aviso_tipo = $resultado['bloqueada'] ? 'error' : 'info';

            if (!$resultado['bloqueada']) {
                // Se avanza al paso 2 exista o no el radicado: si solo
                // avanzáramos cuando existe, el propio flujo revelaría la
                // existencia y el segundo factor no serviría de nada.
                $paso = 'codigo';

                if (!SEGUIMIENTO_OTP_ACTIVO) {
                    $aviso = 'El envío de códigos de verificación aún no está habilitado. '
                           . 'Comunícate con la línea de atención para consultar el estado de tu caso.';
                    $aviso_tipo = 'aviso';
                }
            }
        }
    }

    if ($accion === 'verificar') {
        $radicado_actual = strtoupper(trim(input('radicado', '')));
        $codigo          = input('codigo', '');

        $resultado = verificar_codigo_seguimiento($radicado_actual, $codigo);

        if ($resultado['valido']) {
            $sesion          = sesion_consulta_vigente();
            $paso            = 'caso';
            $caso            = $sesion ? proyeccion_publica_caso($sesion['denuncia_id']) : null;
        } else {
            $paso       = 'codigo';
            $aviso      = $resultado['mensaje'];
            $aviso_tipo = 'error';
        }
    }
}

// Enlace heredado con ?codigo= en la URL: se conserva la comodidad de
// precargar el radicado, pero ya no concede acceso por sí solo.
if ($paso === 'radicado' && isset($_GET['codigo'])) {
    $radicado_actual = strtoupper(trim(input_get('codigo', '')));
}

// ─────────────────────────────────────────────────────────────────────────────
// Presentación
// ─────────────────────────────────────────────────────────────────────────────
$estados_info = [
    'nueva' => [
        'label' => 'Nueva',
        'color' => 'blue',
        'descripcion' => 'Tu caso fue recibido y está en revisión inicial.',
    ],
    'en_proceso' => [
        'label' => 'En Proceso',
        'color' => 'yellow',
        'descripcion' => 'Tu caso está siendo atendido por el equipo profesional.',
    ],
    'resuelta' => [
        'label' => 'Resuelta',
        'color' => 'green',
        'descripcion' => 'Tu caso fue atendido y cerrado.',
    ],
    'archivada' => [
        'label' => 'Archivada',
        'color' => 'gray',
        'descripcion' => 'Este caso fue archivado.',
    ],
];

$tipos_violencia = [
    'fisica' => 'Violencia Física',
    'psicologica' => 'Violencia Psicológica',
    'sexual' => 'Violencia Sexual',
    'economica' => 'Violencia Económica',
    'verbal' => 'Violencia Verbal',
    'laboral' => 'Acoso Laboral',
    'otro' => 'Otro',
];

$clases_aviso = [
    'error' => 'bg-red-50 border-red-500 text-red-800',
    'aviso' => 'bg-yellow-50 border-yellow-400 text-yellow-800',
    'info'  => 'bg-blue-50 border-blue-500 text-blue-800',
];

$title = 'Seguimiento de Caso';
ob_start();
?>

<!-- Encabezado -->
<div class="bg-gradient-to-r from-purple-600 to-purple-800 text-white py-8 mb-8 rounded-lg shadow-xl">
    <div class="max-w-4xl mx-auto text-center px-6">
        <svg class="w-16 h-16 mx-auto mb-3 opacity-90" fill="currentColor" viewBox="0 0 20 20">
            <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"></path>
            <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"></path>
        </svg>
        <h1 class="text-3xl font-bold mb-2">Seguimiento de Caso</h1>
        <p class="text-purple-100">Consulta el estado de tu caso de forma segura</p>
    </div>
</div>

<div class="max-w-4xl mx-auto">

    <?php if ($aviso): ?>
        <div class="border-l-4 p-4 rounded mb-6 <?= $clases_aviso[$aviso_tipo] ?? $clases_aviso['info'] ?>">
            <p><?= e($aviso) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($paso === 'radicado'): ?>
        <!-- ─── PASO 1 · Radicado ─────────────────────────────────────── -->
        <div class="bg-white rounded-lg shadow-lg p-6 md:p-8 mb-8">
            <h2 class="text-xl font-bold text-gray-900 mb-2">Paso 1 de 2 · Tu radicado</h2>
            <p class="text-gray-600 mb-6">
                Para proteger tu información, enviaremos un código de verificación
                al correo o al número que registraste al presentar tu caso.
            </p>

            <form method="POST" action="" class="space-y-4" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="solicitar">

                <div>
                    <label for="radicado" class="block text-sm font-medium text-gray-700 mb-2">
                        Código de radicado
                    </label>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <input
                            type="text"
                            id="radicado"
                            name="radicado"
                            value="<?= e($radicado_actual) ?>"
                            placeholder="BV-20260726-K7QM2X"
                            required
                            autocomplete="off"
                            spellcheck="false"
                            class="flex-1 px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent text-lg tracking-wide uppercase"
                        >
                        <button type="submit" class="px-8 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition shadow-lg">
                            Continuar
                        </button>
                    </div>
                    <p class="text-sm text-gray-500 mt-2">
                        Es el código que recibiste al registrar tu caso.
                        Si lo perdiste, comunícate con la línea de atención.
                    </p>
                </div>
            </form>
        </div>

    <?php elseif ($paso === 'codigo'): ?>
        <!-- ─── PASO 2 · Código de verificación ───────────────────────── -->
        <div class="bg-white rounded-lg shadow-lg p-6 md:p-8 mb-8">
            <h2 class="text-xl font-bold text-gray-900 mb-2">Paso 2 de 2 · Código de verificación</h2>
            <p class="text-gray-600 mb-6">
                El código tiene <strong>6 dígitos</strong> y vence en
                <strong>10 minutos</strong>. Puedes intentarlo hasta 3 veces.
            </p>

            <form method="POST" action="" class="space-y-4" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="verificar">
                <input type="hidden" name="radicado" value="<?= e($radicado_actual) ?>">

                <div>
                    <label for="codigo" class="block text-sm font-medium text-gray-700 mb-2">
                        Código recibido
                    </label>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <input
                            type="text"
                            id="codigo"
                            name="codigo"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            placeholder="000000"
                            required
                            autocomplete="one-time-code"
                            class="flex-1 px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent text-2xl tracking-[0.5em] text-center"
                        >
                        <button type="submit" class="px-8 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition shadow-lg">
                            Verificar
                        </button>
                    </div>
                </div>
            </form>

            <div class="mt-6 pt-6 border-t flex flex-col sm:flex-row gap-3">
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="solicitar">
                    <input type="hidden" name="radicado" value="<?= e($radicado_actual) ?>">
                    <button type="submit" class="text-purple-600 hover:text-purple-800 text-sm font-medium">
                        Enviar un código nuevo
                    </button>
                </form>
                <a href="?page=seguimiento" class="text-gray-600 hover:text-gray-800 text-sm sm:ml-auto">
                    Usar otro radicado
                </a>
            </div>
        </div>

    <?php elseif ($paso === 'caso' && $caso): ?>
        <!-- ─── PASO 3 · Caso ─────────────────────────────────────────── -->
        <?php
        $estado_info = $estados_info[$caso['estado']]
            ?? ['label' => $caso['estado'], 'color' => 'gray', 'descripcion' => ''];
        $minutos_restantes = max(0, (int)ceil(($sesion['expira'] - time()) / 60));
        ?>

        <div class="bg-purple-50 border-l-4 border-purple-600 p-4 rounded mb-6 flex flex-col sm:flex-row sm:items-center gap-3">
            <p class="text-sm text-purple-900 flex-1">
                Esta consulta se cerrará automáticamente en
                <strong><?= $minutos_restantes ?> minuto<?= $minutos_restantes === 1 ? '' : 's' ?></strong>
                por tu seguridad.
            </p>
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="salir">
                <button type="submit" class="px-4 py-2 bg-white border border-purple-600 text-purple-700 rounded-lg text-sm hover:bg-purple-50 transition">
                    Cerrar consulta
                </button>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-lg overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-6 py-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <p class="text-purple-100 text-sm">Estado del caso</p>
                        <p class="text-white text-2xl font-bold"><?= e($estado_info['label']) ?></p>
                    </div>
                    <div class="bg-white/20 backdrop-blur-sm rounded-full px-6 py-3">
                        <p class="text-white font-mono text-lg"><?= e($caso['codigo']) ?></p>
                    </div>
                </div>
            </div>

            <div class="px-6 py-4 bg-purple-50 border-b">
                <p class="text-purple-900"><?= e($estado_info['descripcion']) ?></p>
            </div>

            <div class="p-6">
                <?php if (($caso['alcance'] ?? 'quejoso') !== 'afectada'): ?>
                    <!-- RN-07 · Ac. 003 art. 44 -->
                    <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded">
                        <p class="text-blue-900 font-medium mb-1">Alcance de esta consulta</p>
                        <p class="text-sm text-blue-800">
                            Registraste este caso en nombre de otra persona. Por eso puedes
                            confirmar que el trámite existe y en qué estado va, pero no acceder
                            al contenido del expediente. Esta reserva protege a la persona
                            afectada y está prevista en el Acuerdo 003 de 2026, artículo 44.
                        </p>
                    </div>
                <?php else: ?>
                <div class="grid md:grid-cols-2 gap-4">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600 mb-1">Tipo de caso</p>
                        <p class="font-semibold text-gray-900">
                            <?= e($tipos_violencia[$caso['tipo_violencia']] ?? $caso['tipo_violencia']) ?>
                        </p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600 mb-1">Fecha de registro</p>
                        <p class="font-semibold text-gray-900"><?= format_datetime($caso['created_at']) ?></p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600 mb-1">Fecha de los hechos</p>
                        <p class="font-semibold text-gray-900"><?= format_date($caso['fecha_hechos']) ?></p>
                    </div>
                    <?php if (!empty($caso['updated_at'])): ?>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600 mb-1">Última actualización</p>
                        <p class="font-semibold text-gray-900"><?= format_datetime($caso['updated_at']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (($caso['alcance'] ?? 'quejoso') === 'afectada'): ?>
        <!-- Historial de seguimiento -->
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-xl font-bold text-gray-900 mb-4">Historial de seguimiento</h3>

            <?php if (!empty($caso['seguimientos'])): ?>
                <div class="space-y-4">
                    <?php foreach ($caso['seguimientos'] as $seg): ?>
                    <div class="border-l-4 border-purple-600 pl-4 py-2">
                        <p class="text-sm text-gray-600"><?= format_datetime($seg['created_at']) ?></p>
                        <p class="text-gray-900 mt-1 whitespace-pre-line"><?= e($seg['contenido']) ?></p>
                        <?php if (!empty($seg['profesional'])): ?>
                            <p class="text-sm text-gray-500 mt-1">Por: <?= e($seg['profesional']) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded">
                    <p class="text-blue-800">
                        Aún no hay actualizaciones registradas. El equipo está trabajando
                        en tu caso y las publicará aquí.
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Ayuda -->
    <div class="mt-8 bg-purple-50 border-l-4 border-purple-600 p-6 rounded">
        <h3 class="font-bold text-purple-900 mb-2">¿Necesitas ayuda?</h3>
        <div class="space-y-2 text-purple-900">
            <p><strong>Teléfono:</strong> 6013238309</p>
            <p><strong>Correo:</strong> vbgbienestarud@udistrital.edu.co</p>
        </div>
    </div>

    <div class="mt-6 bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
        <p class="text-sm text-yellow-900">
            <strong>¿Estás en peligro inmediato?</strong>
            Llama al <strong>123</strong> (emergencias) o al <strong>155</strong>
            (orientación a mujeres víctimas de violencia).
        </p>
    </div>

    <div class="mt-8 flex flex-col sm:flex-row gap-4 justify-center">
        <a href="?page=home" class="text-center px-6 py-3 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition">
            Volver al inicio
        </a>
        <a href="?page=denuncia-publica" class="text-center px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
            Registrar un nuevo caso
        </a>
    </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/guest.php';
