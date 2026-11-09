<?php
/**
 * EXPEDIENTE OPERABLE · DESPACHADOR DE PESTAÑAS
 *
 * v3.1 · Fase A · §7.1 · Reemplaza modules/denuncias/ver.php
 *
 * Toda pestaña: (1) pasa por puede_ver_expediente(), (2) audita la lectura,
 * (3) llama a las funciones de dominio de includes/ sin duplicar lógica.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_auth();

$denuncia_id = input_get('id', 0, 'int');

if (!$denuncia_id) {
    redirect_with_message(url('?page=denuncias'), 'Expediente no encontrado.', 'error');
}

$usuario_actual = auth_user();

$denuncia = db_select_one('SELECT * FROM denuncias WHERE id = ? LIMIT 1', [$denuncia_id]);

// RN-02 · el mensaje es idéntico exista o no el expediente: confirmar su
// existencia ya viola la reserva (Ac. 003 art. 47)
if (!$denuncia
    || !puede_ver_expediente($usuario_actual['id'], $usuario_actual['rol'], $denuncia_id)) {
    audit_log($usuario_actual['id'], 'acceso_expediente_denegado', 'denuncias', [
        'rol' => $usuario_actual['rol'],
    ], $denuncia_id);
    redirect_with_message(url('?page=denuncias'), 'Expediente no encontrado.', 'error');
}

// ── Pestañas: archivo + roles que la ven (§7.1) ─────────────────────────────
$roles_equipo = ['coordinacion_edheg', 'coordinador', 'psicologo', 'trabajador_social', 'abogado'];

$pestanas = [
    'resumen'         => ['archivo' => 'resumen.php',         'titulo' => 'Resumen',
                          'roles' => array_merge($roles_equipo, ['instructor', 'decisor'])],
    'relato'          => ['archivo' => 'relato.php',          'titulo' => 'Relato y evidencia',
                          'roles' => array_merge($roles_equipo, ['instructor'])],
    'riesgo'          => ['archivo' => 'riesgo.php',          'titulo' => 'Riesgo',
                          'roles' => ['psicologo', 'trabajador_social', 'coordinacion_edheg', 'coordinador']],
    'medidas'         => ['archivo' => 'medidas.php',         'titulo' => 'Medidas',
                          'roles' => $roles_equipo],
    'compromisos'     => ['archivo' => 'compromisos.php',     'titulo' => 'Compromisos',
                          'roles' => array_merge($roles_equipo, ['instructor', 'decisor'])],
    'encuentros'      => ['archivo' => 'encuentros.php',      'titulo' => 'Encuentros',
                          'roles' => $roles_equipo],
    'competencia'     => ['archivo' => 'competencia.php',     'titulo' => 'Competencia y remisión',
                          'roles' => ['coordinacion_edheg', 'coordinador', 'abogado']],
    'consentimientos' => ['archivo' => 'consentimientos.php', 'titulo' => 'Consentimientos',
                          'roles' => $roles_equipo],
    'retaliaciones'   => ['archivo' => 'retaliaciones.php',   'titulo' => 'Retaliaciones',
                          'roles' => $roles_equipo],
    'sanciones'       => ['archivo' => 'sanciones.php',       'titulo' => 'Sanciones',
                          'roles' => ['decisor', 'coordinacion_edheg', 'coordinador']],
    'historial'       => ['archivo' => 'historial.php',       'titulo' => 'Línea de tiempo',
                          'roles' => array_merge($roles_equipo, ['instructor', 'decisor'])],
];

$tab = input_get('tab', 'resumen');

if (!isset($pestanas[$tab])) {
    $tab = 'resumen';
}

// Falla cerrada: pestaña fuera del alcance del rol → a resumen
if (!in_array($usuario_actual['rol'], $pestanas[$tab]['roles'], true)) {
    audit_log($usuario_actual['id'], 'pestana_denegada', 'denuncias', [
        'rol' => $usuario_actual['rol'],
        'tab' => $tab,
    ], $denuncia_id);
    $tab = 'resumen';
}

// RN-13 · se audita la LECTURA del expediente, con la pestaña consultada
audit_log($usuario_actual['id'], 'expediente_consultado', 'denuncias', [
    'rol'   => $usuario_actual['rol'],
    'tab'   => $tab,
    'etapa' => $denuncia['etapa_procesal'] ?? null,
], $denuncia_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
}

$flash_local = null; // las pestañas dejan aquí sus mensajes de resultado

$title = 'Expediente ' . $denuncia['codigo'];

ob_start();
?>
<div class="max-w-6xl mx-auto">

    <!-- Encabezado del expediente -->
    <div class="bg-white rounded-lg shadow p-4 mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-bold text-gray-800"><?= e($denuncia['codigo']) ?></h2>
            <p class="text-sm text-gray-500">
                Etapa procesal: <span class="font-medium"><?= e(str_replace('_', ' ', $denuncia['etapa_procesal'])) ?></span>
                · Ruta de atención: <span class="font-medium"><?= e(str_replace('_', ' ', $denuncia['etapa_ruta'] ?? 'conocimiento')) ?></span>
            </p>
        </div>
        <span class="px-3 py-1 rounded-full text-xs font-semibold
            <?= $denuncia['estado'] === 'nueva' ? 'bg-blue-100 text-blue-800'
              : ($denuncia['estado'] === 'en_proceso' ? 'bg-yellow-100 text-yellow-800'
              : ($denuncia['estado'] === 'resuelta' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800')) ?>">
            <?= e(str_replace('_', ' ', $denuncia['estado'])) ?>
        </span>
    </div>

    <!-- Navegación de pestañas -->
    <nav class="bg-white rounded-lg shadow mb-4 overflow-x-auto" aria-label="Secciones del expediente">
        <div class="flex whitespace-nowrap">
            <?php foreach ($pestanas as $clave => $info): ?>
                <?php if (!in_array($usuario_actual['rol'], $info['roles'], true)) continue; ?>
                <a href="?page=expediente&id=<?= (int)$denuncia_id ?>&tab=<?= e($clave) ?>"
                   class="px-4 py-3 text-sm border-b-2 transition <?= $tab === $clave
                        ? 'border-purple-600 text-purple-700 font-semibold'
                        : 'border-transparent text-gray-600 hover:text-purple-700' ?>"
                   <?= $tab === $clave ? 'aria-current="page"' : '' ?>>
                    <?= e($info['titulo']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>

    <?php
    // La pestaña procesa su POST y pinta su contenido
    require BASE_PATH . '/modules/expediente/' . $pestanas[$tab]['archivo'];
    ?>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/app.php';
