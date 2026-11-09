<?php
/**
 * REPORTE ANÓNIMO · CANAL PÚBLICO PREVENTIVO
 *
 * v3.1 · Fase F · Ac. 002 art. 33 · Ac. 003 art. 5 · ADR-09
 *
 * NO genera radicado ni abre trámite disciplinario por defecto. Activa
 * medidas preventivas y análisis de patrones. RN-21: si trae pruebas o
 * hechos concretos con autor identificable, coordinación decide de forma
 * motivada si abre actuación de oficio.
 *
 * El canal se entrega desactivado (CANAL_ANONIMO_ACTIVO): su apertura es
 * decisión del Comité de Equidad (§13.1 punto 4).
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

$mensaje = null;
$enviado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    // Honeypot: un campo que las personas no ven y los robots llenan
    if (trim((string)input('sitio_web')) !== '') {
        $enviado = true; // respuesta indistinguible
    } else {
        $r = registrar_reporte_anonimo(input('descripcion'), [
            'tipo'             => input('tipo', 'anonimo'),
            'espacio_senalado' => input('espacio_senalado'),
            'tiene_pruebas'    => input('tiene_pruebas') === '1',
        ]);

        if ($r['exito']) {
            $enviado = true;
        } else {
            $mensaje = $r['motivo'];
        }
    }
}

$activo = canal_anonimo_activo();

$title = 'Reporte preventivo';

ob_start();
// La salida rápida la incluye el layout guest.php: no se duplica aquí
?>
<div class="max-w-2xl mx-auto px-4 py-8">

    <h1 class="text-2xl font-bold text-gray-800 mb-2">Reporte preventivo anónimo</h1>
    <p class="text-sm text-gray-600 mb-6">
        Este canal no te pide nombre ni contacto. No genera un radicado consultable ni abre un
        trámite disciplinario: sirve para que la Universidad detecte espacios y situaciones de
        riesgo y active medidas preventivas. Si quieres atención y acompañamiento para un caso
        concreto, usa el <a href="?page=denuncia-publica" class="text-purple-700 underline">canal de atención</a>.
    </p>

    <?php if (!$activo): ?>
        <div class="p-4 rounded-lg bg-yellow-50 border border-yellow-300 text-yellow-800 text-sm">
            El canal de reporte preventivo aún no está habilitado por la Universidad.
            Puedes comunicarte con Bienestar: <strong>vbgbienestarud@udistrital.edu.co</strong> ·
            línea <strong>6013238309</strong>. En emergencia: <strong>123</strong> ·
            orientación a mujeres: <strong>155</strong>.
        </div>
    <?php elseif ($enviado): ?>
        <div class="p-4 rounded-lg bg-green-50 border border-green-300 text-green-800 text-sm">
            Recibimos tu reporte. Gracias por ayudar a proteger a la comunidad. Esta información
            se usa para activar medidas preventivas y detectar patrones; no genera un radicado
            consultable.
        </div>
    <?php else: ?>
        <?php if ($mensaje): ?>
            <div class="mb-4 p-3 rounded-lg bg-red-100 text-red-800 text-sm" role="alert"><?= e($mensaje) ?></div>
        <?php endif; ?>

        <form method="POST" class="bg-white rounded-lg shadow p-6 space-y-4" autocomplete="off">
            <?= csrf_field() ?>
            <!-- Honeypot -->
            <div class="hidden" aria-hidden="true">
                <label>No llenes este campo<input type="text" name="sitio_web" tabindex="-1"></label>
            </div>

            <div>
                <label class="block text-sm text-gray-600" for="tipo">¿Qué quieres reportar?</label>
                <select name="tipo" id="tipo" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="anonimo">Una situación que me preocupa</option>
                    <option value="escrache">Un señalamiento público (escrache)</option>
                    <option value="denuncia_publica">Una denuncia pública en redes o medios</option>
                </select>
            </div>

            <div>
                <label class="block text-sm text-gray-600" for="descripcion">Cuéntanos qué está pasando</label>
                <textarea name="descripcion" id="descripcion" rows="5" required minlength="20"
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
            </div>

            <div>
                <label class="block text-sm text-gray-600" for="espacio_senalado">¿En qué espacio o lugar? (opcional)</label>
                <input type="text" name="espacio_senalado" id="espacio_senalado"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>

            <label class="flex items-start space-x-2 text-sm text-gray-700">
                <input type="checkbox" name="tiene_pruebas" value="1" class="mt-1">
                <span>Tengo pruebas o puedo señalar hechos concretos con una persona identificable.
                    (La coordinación evaluará si corresponde abrir una actuación de oficio.)</span>
            </label>

            <button type="submit" class="w-full px-4 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                Enviar reporte anónimo</button>
        </form>
    <?php endif; ?>

    <p class="text-xs text-gray-500 mt-6 text-center">
        Emergencias: <strong>123</strong> · Orientación a mujeres víctimas de violencia: <strong>155</strong>
    </p>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/views/layouts/guest.php';
