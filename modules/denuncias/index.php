<?php
/**
 * MÓDULO: DENUNCIAS - LISTAR
 *
 * Lista todas las denuncias con filtros y paginación
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Requiere autenticación
require_auth();

// Parámetros de paginación y filtros
$page = input_get('p', 1, 'int');
$per_page = 20;
$search = input_get('search', '');
$estado_filter = input_get('estado', '');

// ────────────────────────────────────────────────────────────────────────────
// RN-02 · Reserva por etapa procesal  (sustituye el hallazgo H-18)
//
// Antes, cualquier usuario autenticado —incluido el rol `consulta`— veía el
// listado completo. Ahora la restricción se aplica DENTRO de la consulta: si
// se filtrara después, el conteo total seguiría revelando cuántos casos
// existen, que es información reservada por sí misma (Ac. 003 art. 47).
// ────────────────────────────────────────────────────────────────────────────
$usuario_actual = auth_user();
$restriccion = restriccion_listado_expedientes($usuario_actual['id'], $usuario_actual['rol']);

$where_conditions = [$restriccion['sql']];
$params = $restriccion['params'];

if (!empty($search)) {
    // La búsqueda solo opera sobre el radicado: buscar dentro del relato
    // permitiría inferir contenidos de expedientes no visibles
    $where_conditions[] = "d.codigo LIKE ?";
    $params[] = '%' . $search . '%';
}

if (!empty($estado_filter)) {
    $where_conditions[] = "d.estado = ?";
    $params[] = $estado_filter;
}

$where_sql = 'WHERE ' . implode(' AND ', $where_conditions);

// Obtener total (ya restringido)
$count_query = "SELECT COUNT(*) as total FROM denuncias d $where_sql";
$count_result = db_select_one($count_query, $params);
$total = $count_result['total'] ?? 0;

// Obtener denuncias paginadas
$offset = ($page - 1) * $per_page;
$query = "SELECT d.id, d.codigo, d.tipo_violencia, d.estado, d.etapa_procesal, d.created_at
          FROM denuncias d
          $where_sql
          ORDER BY d.created_at DESC
          LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$denuncias = db_select($query, $params) ?: [];

// Calcular total de páginas
$total_pages = ceil($total / $per_page);

// Estados disponibles
$estados = ['nueva', 'en_proceso', 'resuelta', 'archivada'];
$estado_colors = [
    'nueva' => 'bg-blue-100 text-blue-800',
    'en_proceso' => 'bg-yellow-100 text-yellow-800',
    'resuelta' => 'bg-green-100 text-green-800',
    'archivada' => 'bg-gray-100 text-gray-800',
];

// Preparar contenido
$title = 'Gestión de Denuncias';
ob_start();
?>

<!-- Header con acciones -->
<div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
    <div class="mb-4 md:mb-0">
        <h2 class="text-2xl font-bold text-gray-800">Denuncias</h2>
        <p class="text-gray-600 mt-1">Total: <?= $total ?> registro<?= $total != 1 ? 's' : '' ?></p>
    </div>
    <a href="?page=denuncia-nueva" class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
        </svg>
        Nueva Denuncia
    </a>
</div>

<!-- Filtros -->
<div class="bg-white rounded-lg shadow p-6 mb-6">
    <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input type="hidden" name="page" value="denuncias">

        <!-- Búsqueda -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Buscar</label>
            <input
                type="text"
                name="search"
                value="<?= e($search) ?>"
                placeholder="Código o tipo..."
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
            >
        </div>

        <!-- Estado -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Estado</label>
            <select name="estado" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600">
                <option value="">Todos</option>
                <?php foreach ($estados as $estado): ?>
                    <option value="<?= e($estado) ?>" <?= $estado_filter === $estado ? 'selected' : '' ?>>
                        <?= e(ucfirst($estado)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Botones -->
        <div class="flex items-end space-x-2">
            <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition">
                Filtrar
            </button>
            <a href="?page=denuncias" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition">
                Limpiar
            </a>
        </div>
    </form>
</div>

<!-- Tabla de denuncias -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <?php if (empty($denuncias)): ?>
        <div class="p-12 text-center">
            <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <p class="text-gray-600">No se encontraron denuncias</p>
            <a href="?page=denuncia-nueva" class="inline-block mt-4 text-purple-600 hover:text-purple-800">
                Crear la primera denuncia →
            </a>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tipo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($denuncias as $denuncia): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <span class="text-sm font-medium text-gray-900"><?= e($denuncia['codigo']) ?></span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <?= e(ucfirst($denuncia['tipo_violencia'] ?? 'N/A')) ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?= $estado_colors[$denuncia['estado']] ?? 'bg-gray-100 text-gray-800' ?>">
                                <?= e(ucfirst($denuncia['estado'])) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">
                            <?= format_datetime($denuncia['created_at']) ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center space-x-3">
                                <a href="?page=denuncia-ver&id=<?= $denuncia['id'] ?>" class="text-purple-600 hover:text-purple-800" title="Ver">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                </a>
                            </div>
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
                    <!-- Anterior -->
                    <?php if ($page > 1): ?>
                        <li>
                            <a href="?page=denuncias&p=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $estado_filter ? '&estado=' . urlencode($estado_filter) : '' ?>"
                               class="px-3 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">
                                Anterior
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Páginas -->
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li>
                            <a href="?page=denuncias&p=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $estado_filter ? '&estado=' . urlencode($estado_filter) : '' ?>"
                               class="px-3 py-2 <?= $i === $page ? 'bg-purple-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?> rounded">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <!-- Siguiente -->
                    <?php if ($page < $total_pages): ?>
                        <li>
                            <a href="?page=denuncias&p=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $estado_filter ? '&estado=' . urlencode($estado_filter) : '' ?>"
                               class="px-3 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">
                                Siguiente
                            </a>
                        </li>
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
