<?php
/**
 * MÓDULO: DASHBOARD
 *
 * Panel principal con estadísticas
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Requiere autenticación
require_auth();

// Obtener usuario actual
$user = auth_user();

// Obtener estadísticas
$stats = [];

// Total de denuncias
$stats['total_denuncias'] = db_count('denuncias') ?: 0;

// Denuncias por estado
$stats['nuevas'] = db_count('denuncias', ['estado' => 'nueva']) ?: 0;
$stats['en_proceso'] = db_count('denuncias', ['estado' => 'en_proceso']) ?: 0;
$stats['resueltas'] = db_count('denuncias', ['estado' => 'resuelta']) ?: 0;

// Denuncias del mes actual
$inicio_mes = date('Y-m-01 00:00:00');
$query_mes = "SELECT COUNT(*) as total FROM denuncias WHERE created_at >= ?";
$result_mes = db_select_one($query_mes, [$inicio_mes]);
$stats['mes_actual'] = $result_mes ? $result_mes['total'] : 0;

// ────────────────────────────────────────────────────────────────────────────
// Últimos expedientes
//
// Esta consulta traía `d.*` —el relato incluido— de los cinco expedientes más
// recientes del sistema, para cualquier rol de la lista. Una psicóloga sin
// asignación veía en la portada casos que no le competen, y el relato viajaba
// hasta la plantilla aunque esta no lo imprimiera. Contradice RN-02 y RN-08 y
// deja sin efecto el control por expediente que aplica `ver.php`.
//
// Ahora se piden solo las columnas de identificación y clasificación, y se
// aplica la misma restricción que gobierna el listado completo.
// ────────────────────────────────────────────────────────────────────────────
$ultimas_denuncias = [];

if (has_any_role(['coordinacion_edheg', 'coordinador', 'psicologo',
                  'trabajador_social', 'abogado', 'instructor', 'decisor'])) {

    $restriccion = restriccion_listado_expedientes($user['id'], $user['rol']);

    $ultimas_denuncias = db_select(
        "SELECT d.id, d.codigo, d.estado, d.tipo_violencia, d.created_at
           FROM denuncias d
          WHERE {$restriccion['sql']}
          ORDER BY d.created_at DESC
          LIMIT 5",
        $restriccion['params']
    ) ?: [];
}

// ────────────────────────────────────────────────────────────────────────────
// Medidas con plazo vencido
//
// `medidas_vencidas()` existía desde la Fase 5 y no la invocaba nadie: el
// sistema calculaba los incumplimientos y no se los mostraba a nadie. Un plazo
// vencido sin avisar es exactamente el fallo que la medida pretendía evitar.
// ────────────────────────────────────────────────────────────────────────────
$vencidas = [];

if (has_any_role(['coordinacion_edheg', 'coordinador'])) {
    $vencidas = medidas_vencidas();
}

// Estados con sus colores
$estado_colors = [
    'nueva' => 'bg-blue-100 text-blue-800',
    'en_proceso' => 'bg-yellow-100 text-yellow-800',
    'resuelta' => 'bg-green-100 text-green-800',
    'archivada' => 'bg-gray-100 text-gray-800',
];

// v3.1 §9 · compromisos activos a cargo de esta cuenta (o de su unidad)
$usuario_dash = auth_user();
$mis_compromisos = function_exists('compromisos_de_usuario')
    ? compromisos_de_usuario($usuario_dash['id'], $usuario_dash['unidad_id'] ?? null)
    : [];
$compromisos_vencidos = 0;
foreach ($mis_compromisos as $c) {
    if (esta_vencido($c['fecha_limite'])) { $compromisos_vencidos++; }
}

// Preparar contenido
$title = 'Dashboard';
ob_start();
?>

<?php if (!empty($mis_compromisos)): ?>
<a href="?page=mis-compromisos"
   class="block mb-6 p-4 rounded-lg border <?= $compromisos_vencidos > 0 ? 'bg-red-50 border-red-300' : 'bg-purple-50 border-purple-200' ?> hover:shadow transition">
    <span class="font-semibold <?= $compromisos_vencidos > 0 ? 'text-red-800' : 'text-purple-800' ?>">
        Tiene <?= count($mis_compromisos) ?> compromiso(s) activo(s)
        <?= $compromisos_vencidos > 0 ? '· ' . $compromisos_vencidos . ' VENCIDO(S)' : '' ?>
    </span>
    <span class="text-sm text-gray-600 block mt-1">Ver la bandeja «Mis compromisos» →</span>
</a>
<?php endif; ?>

<!-- Stats Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Total Denuncias -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-600 text-sm font-medium">Total Denuncias</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?= (int)$stats['total_denuncias'] ?></p>
            </div>
            <div class="bg-purple-100 rounded-full p-3">
                <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
        </div>
    </div>

    <!-- Nuevas -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-600 text-sm font-medium">Nuevas</p>
                <p class="text-3xl font-bold text-blue-600 mt-2"><?= (int)$stats['nuevas'] ?></p>
            </div>
            <div class="bg-blue-100 rounded-full p-3">
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
            </div>
        </div>
    </div>

    <!-- En Proceso -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-600 text-sm font-medium">En Proceso</p>
                <p class="text-3xl font-bold text-yellow-600 mt-2"><?= (int)$stats['en_proceso'] ?></p>
            </div>
            <div class="bg-yellow-100 rounded-full p-3">
                <svg class="w-8 h-8 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
    </div>

    <!-- Resueltas -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-600 text-sm font-medium">Resueltas</p>
                <p class="text-3xl font-bold text-green-600 mt-2"><?= (int)$stats['resueltas'] ?></p>
            </div>
            <div class="bg-green-100 rounded-full p-3">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="bg-white rounded-lg shadow p-6 mb-8">
    <h2 class="text-lg font-bold text-gray-800 mb-4">Acciones Rápidas</h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <?php
        /*
         * Solo se ofrece a quien puede usarlo.
         *
         * La lista es la misma que exige `modules/denuncias/nueva.php`. Estaba
         * visible para todos los roles, así que administración veía «Nueva
         * Denuncia» en su portada y al pulsarla la devolvían al dashboard sin
         * más. Un botón que rebota es peor que un botón ausente: parece un
         * fallo del sistema y no una decisión.
         */
        ?>
        <?php if (has_any_role(['coordinacion_edheg', 'coordinador', 'psicologo',
                                'trabajador_social', 'abogado', 'instructor'])): ?>
        <a href="?page=denuncia-nueva" class="flex items-center space-x-3 p-4 border-2 border-purple-200 rounded-lg hover:border-purple-400 hover:bg-purple-50 transition">
            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            <span class="font-medium text-gray-700">Nueva Denuncia</span>
        </a>
        <?php endif; ?>

        <a href="?page=denuncias" class="flex items-center space-x-3 p-4 border-2 border-blue-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <span class="font-medium text-gray-700">Ver Denuncias</span>
        </a>

        <?php // Misma lista que la guarda de modules/usuarios/index.php ?>
        <?php if (has_any_role(['admin', 'administrador', 'coordinador', 'coordinacion_edheg'])): ?>
        <a href="?page=usuarios" class="flex items-center space-x-3 p-4 border-2 border-green-200 rounded-lg hover:border-green-400 hover:bg-green-50 transition">
            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
            </svg>
            <span class="font-medium text-gray-700">Gestionar Usuarios</span>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Medidas con plazo vencido -->
<?php if (!empty($vencidas)): ?>
<div class="bg-white rounded-lg shadow mb-6 border-l-4 border-red-600">
    <div class="p-6 border-b border-gray-200">
        <h2 class="text-lg font-bold text-red-800">
            Medidas con plazo vencido
            <span class="ml-2 px-2 py-1 bg-red-100 text-red-800 rounded-full text-sm"><?= count($vencidas) ?></span>
        </h2>
        <p class="text-sm text-gray-700 mt-1">
            Dispuestas y no implementadas dentro del plazo. Requieren gestión ante
            la unidad responsable.
        </p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <caption class="sr-only">Medidas de protección cuyo plazo de implementación ya venció</caption>
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Radicado</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Medida</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Venció</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Estado</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($vencidas as $medida): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900"><?= e($medida['codigo']) ?></td>
                    <td class="px-6 py-4 text-sm text-gray-700"><?= e($medida['nombre']) ?></td>
                    <td class="px-6 py-4 text-sm text-red-800 font-medium"><?= e(format_date($medida['fecha_limite'])) ?></td>
                    <td class="px-6 py-4 text-sm text-gray-700"><?= e(ucfirst(str_replace('_', ' ', $medida['estado']))) ?></td>
                    <td class="px-6 py-4 text-sm">
                        <a href="?page=denuncia-ver&amp;id=<?= (int)$medida['denuncia_id'] ?>"
                           class="text-purple-700 hover:text-purple-900 underline">Ver expediente</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Últimas Denuncias -->
<?php if (!empty($ultimas_denuncias)): ?>
<div class="bg-white rounded-lg shadow">
    <div class="p-6 border-b border-gray-200">
        <h2 class="text-lg font-bold text-gray-800">Últimas Denuncias</h2>
    </div>
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
                <?php foreach ($ultimas_denuncias as $denuncia): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">
                        <?= e($denuncia['codigo']) ?>
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
                        <?= e(format_datetime($denuncia['created_at'])) ?>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <a href="?page=denuncia-ver&amp;id=<?= (int)$denuncia['id'] ?>" class="text-purple-600 hover:text-purple-800">
                            Ver detalles →
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="p-4 border-t border-gray-200 text-center">
        <a href="?page=denuncias" class="text-purple-600 hover:text-purple-800 font-medium">
            Ver todas las denuncias →
        </a>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
?>
