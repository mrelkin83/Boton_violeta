<?php
/**
 * PESTAÑA · COMPETENCIA Y REMISIÓN
 *
 * v3.1 §7.1 y §7.2 · Derivación automática (solo lectura) + ajuste motivado;
 * asignación de instructor y decisor (RN-01); decisión RN-14 sobre la
 * Procuraduría; remisiones externas con estado y acuse.
 */

if (!defined('BASE_PATH')) { http_response_code(403); exit('Acceso denegado'); }

$competencia = obtener_competencia($denuncia_id);

// ── POST: decisión RN-14 ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'decidir_procuraduria') {
    $r = decidir_procuraduria($denuncia_id, input('informar') === '1',
                              input('motivacion_procuraduria'), $usuario_actual['id']);
    $flash_local = $r['exito']
        ? ['ok', 'Decisión registrada.' . ($r['remision_id'] ? ' Se creó la remisión a la Procuraduría.' : '')]
        : ['error', $r['error']];
    $competencia = obtener_competencia($denuncia_id);
}

// ── POST: asignar instructor / decisor (RN-01) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'asignar') {
    $r = asignar_actuacion($denuncia_id, input('usuario_id', 0, 'int'),
                           input('funcion'), $usuario_actual['id']);
    $flash_local = $r['exito'] ? ['ok', 'Asignación registrada.'] : ['error', $r['motivo']];
}

// ── POST: nueva remisión externa ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'crear_remision') {
    $r = crear_remision([
        'denuncia_id'       => $denuncia_id,
        'destino_id'        => input('destino_id', 0, 'int'),
        'unidad_interna_id' => input('unidad_interna_id', 0, 'int') ?: null,
        'tipo'              => input('tipo_remision'),
        'motivacion'        => input('motivacion_remision'),
        'relato_incluido'   => input('relato_incluido') === '1',
        'motivacion_relato' => input('motivacion_relato'),
        'informado_facultad_fiscalia' => input('informado_fiscalia') === '1',
    ], $usuario_actual['id']);
    $flash_local = $r['exito'] ? ['ok', 'Remisión registrada; el compromiso de envío quedó creado.'] : ['error', $r['error']];
}

// ── POST: transición de remisión ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('accion') === 'transicion_remision') {
    $r = cambiar_estado_remision(input('remision_id', 0, 'int'), input('nuevo_estado'),
                                 $usuario_actual['id'], [
        'medio_envio'      => input('medio_envio') ?: null,
        'radicado_destino' => input('radicado_destino') ?: null,
        'observaciones'    => input('observaciones_remision') ?: null,
    ]);
    $flash_local = $r['exito'] ? ['ok', 'Remisión actualizada.'] : ['error', $r['error']];
}

$remisiones = remisiones_del_caso($denuncia_id);
$destinos = destinos_remision_disponibles();
$facultades = db_select(
    "SELECT id, nombre FROM unidades WHERE tipo = 'facultad' AND activa = 1 ORDER BY nombre") ?: [];
$elegibles = db_select(
    "SELECT id, nombre, rol FROM usuarios WHERE activo = 1
      AND rol IN ('instructor','decisor','abogado') ORDER BY rol, nombre") ?: [];

$rn14_pendiente = rn14_decision_pendiente($denuncia_id);
$vias_servidores = in_array($competencia['via'] ?? '', ['disciplinaria_docente', 'disciplinaria_administrativa'], true);
?>

<?php if ($flash_local): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?= $flash_local[0] === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>" role="alert">
        <?= e($flash_local[1]) ?>
    </div>
<?php endif; ?>

<?php if ($rn14_pendiente): ?>
    <div class="mb-4 p-3 rounded-lg bg-yellow-50 border border-yellow-300 text-yellow-800 text-sm" role="alert">
        <strong>RN-14 pendiente:</strong> este expediente está en una vía disciplinaria de servidores
        públicos y no puede pasar a investigación mientras no se registre la decisión sobre la
        Procuraduría (Ac. 003 art. 3 n.13 y art. 79).
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <!-- Competencia derivada -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Competencia derivada (ADR-04 · RN-17)</h3>
        <?php if (!$competencia): ?>
            <p class="text-sm text-gray-400">La competencia aún no se ha derivado. Se fija al registrar la
                vinculación de la persona denunciada.</p>
        <?php else: ?>
            <dl class="text-sm space-y-2">
                <div><dt class="text-gray-500">Vía</dt><dd class="font-medium"><?= e(str_replace('_', ' ', $competencia['via'])) ?></dd></div>
                <div><dt class="text-gray-500">Autoridad que instruye</dt><dd><?= e($competencia['autoridad_instruye']) ?></dd></div>
                <div><dt class="text-gray-500">Autoridad que falla</dt><dd><?= e($competencia['autoridad_falla'] ?? 'Por definir') ?></dd></div>
                <div><dt class="text-gray-500">Segunda instancia</dt><dd><?= e($competencia['autoridad_segunda'] ?? 'Rectoría') ?></dd></div>
                <?php if (!empty($competencia['motivacion_ajuste'])): ?>
                    <div><dt class="text-gray-500">Ajuste manual</dt><dd class="text-xs"><?= e($competencia['motivacion_ajuste']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <p class="text-xs text-gray-400 mt-3">RN-17: la sede de los hechos determina quién atiende,
                nunca quién instruye ni quién falla.</p>
        <?php endif; ?>

        <!-- RN-01 · asignaciones -->
        <h4 class="font-semibold text-gray-700 mt-4 mb-2 text-sm">Asignar instructor / decisor (RN-01)</h4>
        <form method="POST" class="flex flex-wrap items-end gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="asignar">
            <select name="usuario_id" required class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                <option value="">Persona…</option>
                <?php foreach ($elegibles as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['nombre']) ?> (<?= e($u['rol']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <select name="funcion" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                <option value="instruccion">Instrucción</option>
                <option value="juzgamiento">Juzgamiento</option>
                <option value="juridica">Apoyo jurídico</option>
            </select>
            <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Asignar</button>
        </form>
    </div>

    <!-- Decisión RN-14 -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">¿Se informa a la Procuraduría General de la Nación?</h3>
        <?php if (!$vias_servidores): ?>
            <p class="text-sm text-gray-400">RN-14 no aplica a esta vía (los estudiantes no son sujetos
                disciplinables por la Procuraduría). Puede registrar remisiones voluntarias a otras
                autoridades más abajo.</p>
        <?php elseif ($competencia['informar_procuraduria'] !== null): ?>
            <p class="text-sm">
                Decisión: <strong><?= (int)$competencia['informar_procuraduria'] === 1 ? 'SÍ se informa' : 'NO se informa' ?></strong>
                · registrada el <?= e(format_datetime($competencia['decidida_at'])) ?>
            </p>
            <p class="text-xs text-gray-500 mt-2">Motivación: <?= e($competencia['motivacion_procuraduria']) ?></p>
        <?php else: ?>
            <form method="POST" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="decidir_procuraduria">
                <div class="flex space-x-4 text-sm">
                    <label class="flex items-center space-x-2"><input type="radio" name="informar" value="1" required> <span>Sí</span></label>
                    <label class="flex items-center space-x-2"><input type="radio" name="informar" value="0"> <span>No</span></label>
                </div>
                <label class="block text-sm text-gray-600" for="motivacion_procuraduria">Motivación (obligatoria en ambos casos)</label>
                <textarea name="motivacion_procuraduria" id="motivacion_procuraduria" rows="3" required
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Registrar decisión</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Remisiones -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-1">Añadir remisión</h3>
        <p class="text-xs text-gray-500 mb-3">Por defecto la remisión externa NO incluye el relato (RN-03);
            incluirlo exige motivación y queda auditado (§7.2).</p>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="crear_remision">
            <label class="block text-sm text-gray-600" for="destino_id">Destino</label>
            <select name="destino_id" id="destino_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Seleccione…</option>
                <?php foreach ($destinos as $d): ?>
                    <option value="<?= (int)$d['id'] ?>">
                        <?= (int)($d['interno'] ?? 0) === 1 ? '[Interna] ' : '' ?><?= e($d['nombre']) ?><?= (int)$d['activo'] !== 1 ? ' (inactivo — §12.4)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="block text-sm text-gray-600" for="unidad_interna_id">Facultad receptora (solo destino «Decanatura»)</label>
            <select name="unidad_interna_id" id="unidad_interna_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">— no aplica —</option>
                <?php foreach ($facultades as $f): ?>
                    <option value="<?= (int)$f['id'] ?>"><?= e($f['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="block text-sm text-gray-600" for="tipo_remision">Tipo</label>
            <select name="tipo_remision" id="tipo_remision" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="informacion">Información</option>
                <option value="traslado">Traslado</option>
                <option value="coordinacion">Coordinación</option>
            </select>
            <label class="block text-sm text-gray-600" for="motivacion_remision">Motivación</label>
            <textarea name="motivacion_remision" id="motivacion_remision" rows="2" required
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            <label class="flex items-center space-x-2 text-sm text-gray-700">
                <input type="checkbox" name="relato_incluido" value="1">
                <span>Incluir el relato (decisión excepcional, exige motivación)</span>
            </label>
            <input type="text" name="motivacion_relato" placeholder="Motivación para incluir el relato"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <label class="flex items-center space-x-2 text-sm text-gray-700">
                <input type="checkbox" name="informado_fiscalia" value="1">
                <span>Se informó a la víctima su facultad de acudir a la Fiscalía (Ley 2365 art. 11 n.4)</span>
            </label>
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Registrar remisión</button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-800 mb-3">Remisiones del caso</h3>
        <ul class="text-sm space-y-3">
            <?php if (empty($remisiones)): ?>
                <li class="text-gray-400">Sin remisiones registradas.</li>
            <?php endif; ?>
            <?php foreach ($remisiones as $rem): ?>
                <li class="border border-gray-200 rounded-lg p-3">
                    <div class="flex justify-between">
                        <p class="font-medium"><?= e($rem['destino_nombre']) ?></p>
                        <span class="px-2 py-0.5 rounded text-xs bg-purple-100 text-purple-800"><?= e($rem['estado']) ?></span>
                    </div>
                    <p class="text-xs text-gray-500">
                        <?= (int)($rem['interno'] ?? 0) === 1 ? 'INTERNA · ' : '' ?><?= e($rem['tipo']) ?> · decidida <?= e(format_date($rem['fecha_decision'])) ?>
                        <?php if (!empty($rem['unidad_interna_nombre'])): ?>· recibe: <?= e($rem['unidad_interna_nombre']) ?><?php endif; ?>
                        <?php if ($rem['radicado_destino']): ?>· radicado destino: <?= e($rem['radicado_destino']) ?><?php endif; ?>
                        <?= (int)$rem['relato_incluido'] === 1 ? '· CON relato (motivado)' : '· sin relato' ?>
                    </p>
                    <a href="?page=oficio-remision&id=<?= (int)$rem['id'] ?>" target="_blank" rel="noopener"
                       class="inline-block mt-2 px-3 py-1 border border-purple-600 text-purple-700 rounded-lg hover:bg-purple-50 text-xs">
                        📄 Generar oficio de remisión</a>
                    <?php $sig = TRANSICIONES_REMISION[$rem['estado']] ?? []; ?>
                    <?php if (!empty($sig)): ?>
                        <form method="POST" class="mt-2 flex flex-wrap items-end gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="transicion_remision">
                            <input type="hidden" name="remision_id" value="<?= (int)$rem['id'] ?>">
                            <select name="nuevo_estado" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                                <?php foreach ($sig as $s): ?>
                                    <option value="<?= e($s) ?>"><?= e(str_replace('_', ' ', $s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="medio_envio" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                                <option value="">medio…</option>
                                <option value="correo_institucional">Correo institucional</option>
                                <option value="oficio_fisico">Oficio físico</option>
                                <option value="plataforma_destino">Plataforma del destino</option>
                            </select>
                            <input type="text" name="radicado_destino" placeholder="Radicado destino"
                                   class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                            <button type="submit" class="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">Aplicar</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
