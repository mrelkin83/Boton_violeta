<?php
/**
 * MÓDULO: DENUNCIAS - CREAR NUEVA
 *
 * Formulario para registrar nueva denuncia
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Radicar exige competencia sobre expedientes
//
// Con `require_auth()` a secas, cualquier cuenta autenticada podía abrir un
// expediente: también `administrador`, `reportes`, `talento_humano` y
// `unidad_academica`, que por §9.1 y RN-08 no tienen acceso a ninguno. El
// resultado era un expediente cuyo `usuario_id` apunta a alguien que después
// no puede ni abrirlo, con un relato escrito por quien no debía recogerlo.
//
// Quien recibe una denuncia por el canal presencial forma parte del equipo de
// atención; el resto de roles no tiene por qué transcribir un relato.
require_any_role([
    'coordinacion_edheg', 'coordinador',
    'psicologo', 'trabajador_social', 'abogado',
    'instructor',
]);

$errors = [];
$success = false;

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    // Obtener datos
    $tipo_violencia = input('tipo_violencia');
    $descripcion = input('descripcion');
    $fecha_hechos = input('fecha_hechos');
    $lugar_hechos = input('lugar_hechos');

    // Validar
    $errors = validate([
        'tipo_violencia' => $tipo_violencia,
        'descripcion' => $descripcion,
        'fecha_hechos' => $fecha_hechos,
    ], [
        'tipo_violencia' => 'required',
        'descripcion' => 'required|min:20',
        'fecha_hechos' => 'required',
    ]);

    if (empty($errors)) {
        $codigo = null;
        try {
            $codigo = generate_codigo_denuncia();
        } catch (Throwable $e) {
            log_error('No se pudo generar radicado: ' . $e->getMessage(), 'error');
            set_flash('No fue posible generar el radicado. Intente nuevamente.', 'error');
        }
    }

    if (empty($errors) && $codigo !== null) {
        // Preparar datos
        $data = [
            'codigo' => $codigo,
            'tipo_violencia' => $tipo_violencia,
            'descripcion' => $descripcion,
            'fecha_hechos' => $fecha_hechos,
            'lugar_hechos' => $lugar_hechos,
            'estado' => 'nueva',
            'usuario_id' => auth_user_id(),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // Insertar
        $denuncia_id = db_insert_simple('denuncias', $data);

        if ($denuncia_id) {
            // Fase 3 · toda denuncia debe tener competencia registrada, aunque
            // sea «por determinar»: un expediente sin competencia es un
            // expediente que nadie sabe quién debe atender.
            derivar_competencia($denuncia_id, 'desconocido', null);

            // Registrar en auditoría (sin tipo de violencia — prohibición 1)
            audit_log(auth_user_id(), 'denuncia_creada', 'denuncias', [
                'codigo' => $codigo,
                'canal'  => 'radicacion_interna',
            ], $denuncia_id);

            // Redirigir con mensaje
            redirect_with_message(
                url('?page=denuncia-ver&id=' . $denuncia_id),
                'Denuncia registrada correctamente. Código: ' . $codigo,
                'success'
            );
        } else {
            set_flash('Error al registrar la denuncia. Intente nuevamente.', 'error');
        }
    } else {
        save_old_input();
    }
}

// Tipos de violencia
$tipos_violencia = [
    'fisica' => 'Violencia Física',
    'psicologica' => 'Violencia Psicológica',
    'sexual' => 'Violencia Sexual',
    'economica' => 'Violencia Económica',
    'verbal' => 'Violencia Verbal',
    'otro' => 'Otro',
];

// Preparar contenido
$title = 'Nueva Denuncia';
ob_start();
?>

<!-- Breadcrumb -->
<nav class="mb-6 text-sm">
    <a href="?page=dashboard" class="text-purple-600 hover:text-purple-800">Dashboard</a>
    <span class="mx-2 text-gray-400">/</span>
    <a href="?page=denuncias" class="text-purple-600 hover:text-purple-800">Denuncias</a>
    <span class="mx-2 text-gray-400">/</span>
    <span class="text-gray-600">Nueva</span>
</nav>

<!-- Formulario -->
<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-lg shadow p-6 md:p-8">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Registrar Nueva Denuncia</h2>
            <p class="text-gray-600 mt-2">Complete el formulario con la información de la denuncia</p>
        </div>

        <!-- Alerta de confidencialidad -->
        <div class="bg-purple-50 border-l-4 border-purple-600 p-4 mb-6">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-purple-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <p class="text-sm font-medium text-purple-900">Información Confidencial</p>
                    <p class="text-sm text-purple-700 mt-1">Todos los datos proporcionados serán tratados con estricta confidencialidad.</p>
                </div>
            </div>
        </div>

        <form method="POST" action="" class="space-y-6">
            <?= csrf_field() ?>

            <!-- Tipo de Violencia -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Tipo de Violencia <span class="text-red-600">*</span>
                </label>
                <select name="tipo_violencia" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent">
                    <option value="">Seleccione...</option>
                    <?php foreach ($tipos_violencia as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= old('tipo_violencia') === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['tipo_violencia'])): ?>
                    <p class="text-red-600 text-sm mt-1"><?= e($errors['tipo_violencia'][0]) ?></p>
                <?php endif; ?>
            </div>

            <!-- Descripción -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Descripción de los Hechos <span class="text-red-600">*</span>
                </label>
                <textarea
                    name="descripcion"
                    rows="6"
                    required
                    placeholder="Describa de manera detallada los hechos ocurridos..."
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                ><?= old('descripcion') ?></textarea>
                <p class="text-sm text-gray-500 mt-1">Mínimo 20 caracteres</p>
                <?php if (isset($errors['descripcion'])): ?>
                    <p class="text-red-600 text-sm mt-1"><?= e($errors['descripcion'][0]) ?></p>
                <?php endif; ?>
            </div>

            <!-- Fecha de los Hechos -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Fecha de los Hechos <span class="text-red-600">*</span>
                </label>
                <input
                    type="date"
                    name="fecha_hechos"
                    value="<?= old('fecha_hechos', date('Y-m-d')) ?>"
                    max="<?= date('Y-m-d') ?>"
                    required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                >
                <?php if (isset($errors['fecha_hechos'])): ?>
                    <p class="text-red-600 text-sm mt-1"><?= e($errors['fecha_hechos'][0]) ?></p>
                <?php endif; ?>
            </div>

            <!-- Lugar de los Hechos -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Lugar de los Hechos
                </label>
                <input
                    type="text"
                    name="lugar_hechos"
                    value="<?= old('lugar_hechos') ?>"
                    placeholder="Ej: Campus universitario, salón 201..."
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                >
            </div>

            <!-- Botones -->
            <div class="flex items-center justify-end space-x-4 pt-6 border-t">
                <a href="?page=denuncias" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition">
                    Cancelar
                </a>
                <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
                    Registrar Denuncia
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
clear_old_input();
require BASE_PATH . '/views/layouts/app.php';
?>
