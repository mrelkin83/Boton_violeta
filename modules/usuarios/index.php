<?php
/**
 * MÓDULO: USUARIOS - GESTIÓN
 *
 * Lista y gestión de usuarios (solo admin/coordinador)
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Requiere rol de admin o coordinador
require_any_role(['admin', 'administrador', 'coordinador', 'coordinacion_edheg']);

// Coordinación consulta el directorio; solo administración lo modifica.
// Se calcula una vez y se usa tanto en las guardas del servidor como en la
// vista: mientras estuvieron separadas, `has_role('admin')` dejaba fuera al
// rol `administrador` que introdujo la migración 0011 y la pantalla aparecía
// sin ninguna acción disponible.
$puede_administrar = has_any_role(['admin', 'administrador']);

// Parámetros
$page = input_get('p', 1, 'int');
$per_page = 20;
$search = input_get('search', '');
$rol_filter = input_get('rol', '');

// Construir query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(nombre LIKE ? OR email LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($rol_filter)) {
    $where_conditions[] = "rol = ?";
    $params[] = $rol_filter;
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Total
$count_query = "SELECT COUNT(*) as total FROM usuarios $where_sql";
$count_result = db_select_one($count_query, $params);
$total = $count_result['total'] ?? 0;

// Obtener usuarios
$offset = ($page - 1) * $per_page;
$query = "SELECT id, nombre, email, rol, activo, created_at, ultimo_acceso
          FROM usuarios
          $where_sql
          ORDER BY created_at DESC
          LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$usuarios = db_select($query, $params) ?: [];

$total_pages = ceil($total / $per_page);

// Roles disponibles
//
// Esta lista se quedó atrás cuando la migración 0011 rehízo el ENUM: seguía
// ofreciendo `consulta`, que ya no existe, y no incluía ninguno de los ocho
// roles del modelo institucional. El filtro por rol no podía encontrarlos y la
// tabla mostraba el identificador crudo.
$roles = [
    'administrador'      => 'Administración',
    'admin'              => 'Administración',
    'coordinacion_edheg' => 'Coordinación EDHEG',
    'coordinador'        => 'Coordinador/a',
    'psicologo'          => 'Psicólogo/a',
    'trabajador_social'  => 'Trabajador/a Social',
    'abogado'            => 'Abogado/a',
    'instructor'         => 'Instructor/a',
    'decisor'            => 'Decisor/a',
    'unidad_academica'   => 'Unidad académica',
    'talento_humano'     => 'Talento humano',
    'reportes'           => 'Reportes',
];

// Activar/Desactivar usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    csrf_protect();

    $user_id = input('user_id', 0, 'int');
    $nuevo_estado = input('nuevo_estado', 1, 'int') === 1 ? 1 : 0;

    if (!$puede_administrar) {
        redirect_with_message(url('?page=usuarios'), 'No tienes permisos para cambiar el estado de una cuenta.', 'error');
    }

    // La vista oculta el botón sobre la propia cuenta, pero ocultar no es
    // impedir: sin esta comprobación, un POST directo permite desactivarse a
    // uno mismo y quedar fuera del sistema.
    if ($user_id === (int)auth_user_id()) {
        redirect_with_message(url('?page=usuarios'), 'No puedes desactivar tu propia cuenta.', 'error');
    }

    if ($user_id > 0) {
        // Y si es la última administración activa, desactivarla deja el sistema
        // sin nadie capaz de gestionar cuentas ni de revertirlo.
        if ($nuevo_estado === 0) {
            $objetivo = db_select_one('SELECT rol FROM usuarios WHERE id = ? LIMIT 1', [$user_id]);

            if ($objetivo && in_array($objetivo['rol'], ['admin', 'administrador'], true)) {
                $restantes = db_select_one(
                    "SELECT COUNT(*) AS total FROM usuarios
                      WHERE rol IN ('admin','administrador') AND activo = 1 AND id <> ?",
                    [$user_id]
                );

                if ((int)($restantes['total'] ?? 0) === 0) {
                    redirect_with_message(
                        url('?page=usuarios'),
                        'Es la última cuenta administradora activa: desactivarla dejaría el sistema sin gestión posible.',
                        'error'
                    );
                }
            }
        }

        db_update_simple('usuarios', ['activo' => $nuevo_estado], ['id' => $user_id]);
        audit_log(auth_user_id(), 'usuario_estado_cambiado', 'usuarios', [
            'activo' => $nuevo_estado,
        ], $user_id);
        redirect_with_message(url('?page=usuarios'), 'Estado actualizado correctamente.', 'success');
    }
}

$title = 'Gestión de Usuarios';
ob_start();
?>

<!-- Header -->
<div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Usuarios</h2>
        <p class="text-gray-600 mt-1">Total: <?= $total ?> usuario<?= $total != 1 ? 's' : '' ?></p>
    </div>
    <?php if ($puede_administrar): ?>
    <a href="?page=usuario-editar" class="mt-4 md:mt-0 inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
        </svg>
        Nuevo Usuario
    </a>
    <?php endif; ?>
</div>

<!-- Filtros -->
<div class="bg-white rounded-lg shadow p-6 mb-6">
    <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input type="hidden" name="page" value="usuarios">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Buscar</label>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Nombre o email..." class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Rol</label>
            <select name="rol" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
                <option value="">Todos</option>
                <?php foreach ($roles as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $rol_filter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex items-end space-x-2">
            <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition">Filtrar</button>
            <a href="?page=usuarios" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition">Limpiar</a>
        </div>
    </form>
</div>

<!-- Tabla -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <?php if (empty($usuarios)): ?>
        <div class="p-12 text-center">
            <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
            </svg>
            <p class="text-gray-600">No se encontraron usuarios</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nombre</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rol</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Último Acceso</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($usuarios as $usuario): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-900"><?= e($usuario['nombre']) ?></td>
                        <td class="px-6 py-4 text-sm text-gray-700"><?= e($usuario['email']) ?></td>
                        <td class="px-6 py-4 text-sm">
                            <span class="px-2 py-1 bg-purple-100 text-purple-800 rounded-full text-xs font-medium">
                                <?= e($roles[$usuario['rol']] ?? $usuario['rol']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($usuario['activo']): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium">Activo</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs font-medium">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">
                            <?= $usuario['ultimo_acceso'] ? time_ago($usuario['ultimo_acceso']) : 'Nunca' ?>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($puede_administrar): ?>
                                <div class="flex items-center gap-3">
                                    <a href="?page=usuario-editar&amp;id=<?= (int)$usuario['id'] ?>"
                                       class="text-sm text-purple-700 hover:text-purple-900 underline">Editar</a>

                                    <?php if ($usuario['id'] != auth_user_id()): ?>
                                        <form method="POST" action="" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="toggle_active" value="1">
                                            <input type="hidden" name="user_id" value="<?= (int)$usuario['id'] ?>">
                                            <input type="hidden" name="nuevo_estado" value="<?= $usuario['activo'] ? 0 : 1 ?>">
                                            <button type="submit"
                                                    onclick="return confirm('<?= $usuario['activo'] ? 'La cuenta no podrá iniciar sesión ni recibir asignaciones. ¿Desactivar?' : '¿Reactivar la cuenta?' ?>')"
                                                    class="text-sm underline <?= $usuario['activo'] ? 'text-red-700 hover:text-red-900' : 'text-green-700 hover:text-green-900' ?>">
                                                <?= $usuario['activo'] ? 'Desactivar' : 'Activar' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-sm text-gray-400">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <?php if ($total_pages > 1): ?>
        <div class="px-6 py-4 border-t border-gray-200">
            <nav class="flex justify-center">
                <ul class="flex space-x-2">
                    <?php if ($page > 1): ?>
                        <li><a href="?page=usuarios&p=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $rol_filter ? '&rol=' . urlencode($rol_filter) : '' ?>" class="px-3 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">Anterior</a></li>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li><a href="?page=usuarios&p=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $rol_filter ? '&rol=' . urlencode($rol_filter) : '' ?>" class="px-3 py-2 <?= $i === $page ? 'bg-purple-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?> rounded"><?= $i ?></a></li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li><a href="?page=usuarios&p=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $rol_filter ? '&rol=' . urlencode($rol_filter) : '' ?>" class="px-3 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">Siguiente</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
?>
