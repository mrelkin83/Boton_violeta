<?php
/**
 * MÓDULO: DENUNCIAS PÚBLICAS - VERSIÓN COMPLETA
 *
 * Formulario público para denuncias con TODOS los campos requeridos
 * NO permite denuncias anónimas - datos del denunciante obligatorios
 */

// Punto de entrada único: index.php ya definió BASE_PATH y cargó init.php
// CLAUDE.md §5.1.2 — guarda contra acceso HTTP directo
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

$errors = [];
$success = false;
$codigo_generado = null;

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    // ────────────────────────────────────────────────────────────────────
    // H-17 · Protección anti-spam (CLAUDE.md §10.1 · ADR-12)
    //
    // SIN CAPTCHA VISUAL: la fricción de un captcha es inaceptable para una
    // persona en crisis, y además excluye a quien usa lector de pantalla.
    // Se usan dos mecanismos invisibles para la persona usuaria:
    //
    //   1. Honeypot: un campo que ningún humano ve ni rellena. Si viene con
    //      contenido, es un robot.
    //   2. Límite por IP, deliberadamente holgado (10 por hora): una persona
    //      puede necesitar reenviar tras un error, y bloquear una denuncia
    //      real es peor que aceptar algún envío basura.
    // ────────────────────────────────────────────────────────────────────
    $honeypot = trim((string)($_POST['sitio_web'] ?? ''));

    if ($honeypot !== '') {
        // Se responde como si todo hubiera ido bien: informar al robot de que
        // fue detectado solo le enseña a evitar la trampa la próxima vez.
        log_error('Envío bloqueado por honeypot en el formulario público', 'warning');
        $success = true;
        $codigo_generado = 'BV-' . date('Ymd') . '-000000';
        $_POST = [];
    } elseif (!rate_limit_check('denuncia_publica:' . get_user_ip(), 10, 60)) {
        $errors['general'] = [
            'Hemos recibido varios envíos desde esta conexión. Si necesitas ayuda ahora, '
            . 'comunícate con la línea 601 323 8309 o llama al 123.'
        ];
    } else {

    // Obtener datos del incidente
    $tipo_violencia = trim(input('tipo_violencia', ''));
    $tipo_agresor = trim(input('tipo_agresor', ''));
    $agresor_nombre = trim(input('agresor_nombre', ''));
    $descripcion = trim(input('descripcion', ''));
    $testigos = trim(input('testigos', ''));
    $fecha_hechos = trim(input('fecha_hechos', ''));

    // Ubicación
    $facultad = trim(input('facultad', ''));
    $sede = trim(input('sede', ''));
    $edificio = trim(input('edificio', ''));
    $salon_area = trim(input('salon_area', ''));

    // Datos del denunciante
    $denunciante_nombre = trim(input('denunciante_nombre', ''));
    $denunciante_telefono = trim(input('denunciante_telefono', ''));
    $denunciante_email = trim(input('denunciante_email', ''));

    // v3.1 · Fase C · RN-15: correo institucional opcional, en columna aparte
    $denunciante_email_institucional = trim(input('denunciante_email_institucional', ''));
    if ($denunciante_email_institucional !== '' && !valid_email($denunciante_email_institucional)) {
        $denunciante_email_institucional = '';
    }

    // Canal por el que la persona quiere ser contactada (RN-15): lo elige
    // ella. Una cuenta institucional puede estar compartida o supervisada;
    // forzarla puede exponer a la persona.
    $canal_preferido = input('canal_preferido', 'personal');
    if ($canal_preferido === 'correo') {
        $canal_preferido = 'personal'; // valor heredado de formularios en caché
    }
    if (!in_array($canal_preferido, ['personal', 'institucional', 'ambos', 'sms', 'ninguno'], true)) {
        $canal_preferido = 'personal';
    }

    // v3.1 · §16 E1 · RN-20: menores de edad
    $es_menor = input('es_menor_de_edad') === '1';
    $contacto_emergencia = trim(input('contacto_emergencia', ''));

    // v3.1 · H-24: sede/espacio de los hechos desde el catálogo de unidades
    $unidad_hechos_id = input('unidad_hechos_id', 0, 'int') ?: null;
    if ($unidad_hechos_id !== null
        && !db_exists('unidades', ['id' => $unidad_hechos_id, 'activa' => 1])) {
        $unidad_hechos_id = null;
    }

    // Fase 3 · vinculación de la persona denunciada.
    // De ella depende TODA la derivación de competencia (ADR-04): quién
    // instruye, quién falla y ante quién se apela.
    $vinculacion_denunciada = input('tipo_vinculacion_denunciada', 'desconocido');
    if (!array_key_exists($vinculacion_denunciada, tipos_vinculacion())) {
        $vinculacion_denunciada = 'desconocido';
    }

    // Fase 3 · ¿quien denuncia es la persona afectada?
    // Activa RN-07 (Ac. 003 art. 44) cuando la respuesta es «no».
    $es_la_afectada = input('es_denunciante_la_afectada', 'si') !== 'no';

    // Validar campos requeridos
    $errors = validate([
        'tipo_violencia' => $tipo_violencia,
        'tipo_agresor' => $tipo_agresor,
        'descripcion' => $descripcion,
        'fecha_hechos' => $fecha_hechos,
        'facultad' => $facultad,
        'sede' => $sede,
        'denunciante_nombre' => $denunciante_nombre,
        'denunciante_telefono' => $denunciante_telefono,
        'denunciante_email' => $denunciante_email,
    ], [
        'tipo_violencia' => 'required',
        'tipo_agresor' => 'required',
        'descripcion' => 'required|min:20',
        'fecha_hechos' => 'required',
        'facultad' => 'required',
        'sede' => 'required',
        'denunciante_nombre' => 'required|min:3',
        'denunciante_telefono' => 'required|min:7',
        'denunciante_email' => 'required|email',
    ]);

    // RN-20 · si la persona afectada es menor de edad, el contacto del
    // familiar o acudiente es obligatorio y el canal no puede ser «ninguno»
    if ($es_menor && trim($contacto_emergencia) === '') {
        $errors['contacto_emergencia'] = ['Para una persona menor de edad debemos poder contactar a su familia o acudiente.'];
    }
    if ($es_menor && $canal_preferido === 'ninguno') {
        $errors['canal_preferido'] = ['Para una persona menor de edad necesitamos al menos un medio de contacto.'];
    }

    if (empty($errors)) {
        // El radicado puede lanzar excepción si no logra un código único.
        // Se resuelve aquí para que el fallo no llegue como error 500 a una
        // persona que acaba de escribir su relato.
        $codigo = null;
        try {
            $codigo = generate_codigo_denuncia();
        } catch (Throwable $e) {
            log_error('No se pudo generar radicado: ' . $e->getMessage(), 'error');
            $errors['general'] = ['No fue posible registrar la denuncia en este momento. Intente nuevamente o comuníquese con la línea de atención.'];
        }
    }

    if (empty($errors) && $codigo !== null) {
        // RN-17 · la unidad de ATENCIÓN es la sede a la que pertenece el
        // espacio de los hechos (subiendo por el árbol de unidades). Nunca
        // altera la competencia.
        $unidad_atencion_id = null;
        $cursor = $unidad_hechos_id;
        for ($salto = 0; $salto < 5 && $cursor !== null; $salto++) {
            $u = db_select_one('SELECT id, tipo, padre_id FROM unidades WHERE id = ? LIMIT 1', [$cursor]);
            if (!$u) { break; }
            if ($u['tipo'] === 'sede') { $unidad_atencion_id = (int)$u['id']; break; }
            $cursor = $u['padre_id'] !== null ? (int)$u['padre_id'] : null;
        }

        // Preparar ubicación completa
        $ubicacion_completa = trim("$facultad - $sede" .
            (!empty($edificio) ? " - Edificio: $edificio" : "") .
            (!empty($salon_area) ? " - $salon_area" : ""));

        // Preparar datos
        $data = [
            'codigo' => $codigo,
            'tipo_violencia' => $tipo_violencia,
            'tipo_agresor' => $tipo_agresor,
            'agresor_nombre' => !empty($agresor_nombre) ? $agresor_nombre : null,
            'descripcion' => $descripcion,
            'testigos' => !empty($testigos) ? $testigos : null,
            'fecha_hechos' => $fecha_hechos,
            'lugar_hechos' => $ubicacion_completa,
            'facultad' => $facultad,
            'sede' => $sede,
            'edificio' => !empty($edificio) ? $edificio : null,
            'salon_area' => !empty($salon_area) ? $salon_area : null,
            'estado' => 'nueva',
            'es_anonima' => 0,
            'denunciante_nombre' => $denunciante_nombre,

            // Fase 2 · el contacto se guarda cifrado y en columnas separadas.
            // La columna heredada `denunciante_contacto` concatenaba teléfono y
            // correo en claro; ya no se escribe (la retira la migración 0010).
            'denunciante_contacto'         => null,
            'denunciante_correo_cifrado'   => cifrar_contacto($denunciante_email),
            'denunciante_telefono_cifrado' => cifrar_contacto($denunciante_telefono),
            'denunciante_correo_indice'    => indice_ciego($denunciante_email, 'correo'),
            'denunciante_telefono_indice'  => indice_ciego($denunciante_telefono, 'telefono'),
            'canal_preferido'              => $canal_preferido,

            // v3.1 · H-24 y RN-17: la sede de los hechos viene del catálogo y
            // determina la unidad de ATENCIÓN (nunca la competencia)
            'unidad_hechos_id'   => $unidad_hechos_id,
            'unidad_atencion_id' => $unidad_atencion_id,
            'canal_origen'       => 'boton_violeta',

            'usuario_id' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // ────────────────────────────────────────────────────────────────
        // Denuncia + evidencia en una sola transacción (H-16)
        // Una denuncia sin su evidencia, o una evidencia sin su denuncia,
        // son estados inconsistentes que no pueden persistir.
        // ────────────────────────────────────────────────────────────────
        $denuncia_id = false;
        $archivos_movidos = [];

        try {
            db_begin_transaction();

            $denuncia_id = db_insert_simple('denuncias', $data);

            if (!$denuncia_id) {
                throw new RuntimeException('No se pudo insertar la denuncia');
            }

            // ── Fase 3 · personas del caso ──────────────────────────────
            $persona_denunciante = registrar_persona_caso($denuncia_id, 'denunciante', [
                'nombre'               => $denunciante_nombre,
                'correo'               => $denunciante_email,
                'correo_institucional' => $denunciante_email_institucional ?: null, // RN-15
                'telefono'             => $denunciante_telefono,
                'canal_preferido'      => $canal_preferido,
                'es_mismo_denunciante' => $es_la_afectada,
                'es_menor_de_edad'     => $es_la_afectada ? $es_menor : null,       // RN-20
                'contacto_emergencia'  => $es_la_afectada && $es_menor ? $contacto_emergencia : null,
            ]);

            if (!$persona_denunciante) {
                throw new RuntimeException('No se pudo registrar a la persona denunciante');
            }

            // Si quien denuncia NO es la persona afectada, se deja constancia
            // del papel separado. Sus datos no se piden aquí: nadie puede
            // entregar los datos de contacto de un tercero sin su voluntad.
            if (!$es_la_afectada) {
                registrar_persona_caso($denuncia_id, 'afectada', [
                    'es_mismo_denunciante' => 0,
                ]);
            }

            // Persona denunciada · terminología §2: nunca «agresor»
            registrar_persona_caso($denuncia_id, 'denunciada', [
                'nombre'           => !empty($agresor_nombre) ? $agresor_nombre : null,
                'tipo_vinculacion' => $vinculacion_denunciada,
            ]);

            // ── Fase 3 · competencia derivada (ADR-04) ──────────────────
            derivar_competencia($denuncia_id, $vinculacion_denunciada, null);

            // Procesar archivos adjuntos
            if (!empty($_FILES['archivos']['name'][0])) {
                $upload_dir = UPLOAD_PATH . '/' . $denuncia_id;

                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0750, true);
                }

                foreach ($_FILES['archivos']['tmp_name'] as $key => $tmp_name) {
                    if (empty($tmp_name)) continue;

                    if (($_FILES['archivos']['error'][$key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        continue;
                    }

                    $nombre_original = $_FILES['archivos']['name'][$key];
                    $tamano = (int)$_FILES['archivos']['size'][$key];

                    if (!valid_file_size($tamano)) continue;
                    if (!valid_file_extension($nombre_original)) continue;

                    // Tipo real por contenido, no por extensión ni por el MIME
                    // declarado por el navegador (CLAUDE.md §8.4)
                    $tipo_mime = function_exists('finfo_open')
                        ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp_name) ?: 'application/octet-stream')
                        : 'application/octet-stream';

                    // Nombre no adivinable (prohibición 9: nunca uniqid())
                    $nombre_almacenado = nombre_archivo_seguro($nombre_original);
                    $ruta_absoluta = $upload_dir . '/' . $nombre_almacenado;

                    if (!move_uploaded_file($tmp_name, $ruta_absoluta)) {
                        continue;
                    }

                    $archivos_movidos[] = $ruta_absoluta;

                    // H-03: la columna es `nombre_almacenado`, no `nombre_archivo`.
                    // `usuario_id` viaja NULL porque el canal público no tiene sesión
                    // (requiere la migración 0005).
                    $archivo_id = db_insert_simple('archivos', [
                        'denuncia_id'       => $denuncia_id,
                        'nombre_original'   => substr($nombre_original, 0, 255),
                        'nombre_almacenado' => $nombre_almacenado,
                        'ruta'              => $denuncia_id . '/' . $nombre_almacenado, // relativa a UPLOAD_PATH
                        'tipo_mime'         => $tipo_mime,
                        'tamano'            => $tamano,
                        'usuario_id'        => null,
                        'created_at'        => date('Y-m-d H:i:s'),
                    ]);

                    if (!$archivo_id) {
                        throw new RuntimeException('No se pudo registrar la evidencia adjunta');
                    }
                }
            }

            db_commit();

            // Fase 4 · se notifica a los responsables SIN datos del caso
            // (RN-09). Va por el Outbox: ninguna llamada de red puede
            // bloquear el registro de una denuncia (ADR-08).
            notificar_caso_nuevo($denuncia_id, $codigo);

            // v3.1 · Fase D · RN-18: encuentro inicial automático, DESPUÉS
            // del COMMIT y nunca síncrono contra Graph. Si no hay turno,
            // el caso cae a la bandeja «Por agendar»: un encuentro que no se
            // agenda es una persona que no fue escuchada.
            agendar_encuentro_inicial($denuncia_id);

            // Auditoría fuera de la transacción: nunca debe impedir que una
            // denuncia se registre. Sin tipo de violencia (prohibición 1).
            audit_log(null, 'denuncia_creada', 'denuncias', [
                'codigo' => $codigo,
                'canal'  => 'publico',
                'adjuntos' => count($archivos_movidos),
            ], $denuncia_id);

            $success = true;
            $codigo_generado = $codigo;

            // Limpiar formulario
            $_POST = [];

        } catch (Throwable $e) {
            if (db_in_transaction()) {
                db_rollback();
            }

            // Los archivos ya movidos quedarían huérfanos: se eliminan
            foreach ($archivos_movidos as $ruta_huerfana) {
                @unlink($ruta_huerfana);
            }

            log_error('Fallo al registrar denuncia pública: ' . $e->getMessage(), 'error');

            $errors['general'] = ['Error al registrar la denuncia. Por favor, intente nuevamente o contacte a nuestra línea de atención.'];
        }
    } else {
        save_old_input();
    }

    } // fin del bloque anti-spam (H-17)
}

// Tipos de violencia
$tipos_violencia = [
    'fisica' => 'Violencia Física',
    'psicologica' => 'Violencia Psicológica',
    'sexual' => 'Violencia Sexual',
    'economica' => 'Violencia Económica',
    'verbal' => 'Violencia Verbal',
    'laboral' => 'Acoso Laboral',
    'otro' => 'Otro tipo de violencia',
];

// Tipos de agresor
$tipos_agresor = [
    'docente' => 'Docente',
    'administrativo' => 'Personal Administrativo',
    'estudiante' => 'Estudiante',
    'externo' => 'Persona Externa',
    'otro' => 'Otro',
];

// Preparar contenido
$title = 'Registrar Denuncia';
ob_start();
?>

<!-- Hero Header -->
<div class="bg-gradient-to-r from-purple-600 to-purple-800 text-white py-12 mb-8 rounded-lg shadow-xl">
    <div class="max-w-4xl mx-auto text-center px-6">
        <svg class="w-20 h-20 mx-auto mb-4 opacity-90" fill="currentColor" viewBox="0 0 20 20">
            <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6z"></path>
            <path d="M10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"></path>
        </svg>
        <h1 class="text-4xl font-bold mb-3">Botón Violeta</h1>
        <p class="text-xl text-purple-100">Estamos Aquí Para Ayudarte</p>
        <p class="text-purple-200 mt-2">Espacio seguro y confidencial para reportar situaciones de violencia de género</p>
    </div>
</div>

<?php if ($success && $codigo_generado): ?>
    <!-- Mensaje de Éxito -->
    <div class="max-w-3xl mx-auto mb-8">
        <div class="bg-green-50 border-l-4 border-green-500 p-6 rounded-lg shadow">
            <div class="flex items-start">
                <svg class="w-8 h-8 text-green-500 mr-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                <div class="flex-1">
                    <h3 class="text-lg font-bold text-green-900 mb-2">¡Tu denuncia ha sido registrada exitosamente!</h3>
                    <p class="text-green-800 mb-4">
                        Gracias por confiar en nosotros. Tu caso será atendido con la mayor prioridad y confidencialidad.
                        Nuestro equipo se comunicará contigo pronto a través de los medios de contacto que proporcionaste.
                    </p>

                    <div class="bg-white p-4 rounded border border-green-200 mb-4">
                        <p class="text-sm text-gray-700 mb-2">Tu código de radicado es:</p>
                        <p class="text-3xl font-bold text-purple-600 tracking-wider"><?= e($codigo_generado) ?></p>
                        <p class="text-sm text-gray-600 mt-2">
                            <strong>MUY IMPORTANTE:</strong> Guarda este código de radicado. Podrás usar este código para consultar el estado y seguimiento de tu caso en cualquier momento.
                        </p>
                    </div>

                    <div class="bg-purple-50 p-4 rounded">
                        <p class="text-sm font-medium text-purple-900 mb-2">📞 ¿Qué sigue ahora?</p>
                        <ul class="text-sm text-purple-800 space-y-1 list-disc list-inside">
                            <li>Un profesional de nuestro equipo revisará tu caso en las próximas 24 horas</li>
                            <li>Te contactaremos a través del teléfono y email que proporcionaste</li>
                            <li>Si deseas agendar una cita, llama a nuestra línea: <strong>601 323 8309</strong></li>
                            <li>Tu seguridad es nuestra prioridad</li>
                        </ul>
                    </div>

                    <div class="mt-4 flex gap-3">
                        <a href="?page=home" class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
                            Volver al Inicio
                        </a>
                        <a href="?page=seguimiento&codigo=<?= e($codigo_generado) ?>" class="inline-flex items-center px-4 py-2 bg-white hover:bg-gray-50 text-purple-600 border border-purple-600 rounded-lg transition">
                            Ver Seguimiento
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Formulario de Denuncia -->
    <div class="max-w-3xl mx-auto">
        <!-- Alertas Informativas -->
        <div class="space-y-4 mb-6">
            <!-- Confidencialidad -->
            <div class="bg-purple-50 border-l-4 border-purple-600 p-4 rounded">
                <div class="flex items-start">
                    <svg class="w-6 h-6 text-purple-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <div>
                        <p class="font-medium text-purple-900">Confidencialidad Garantizada</p>
                        <p class="text-sm text-purple-700 mt-1">Toda la información será tratada con estricta confidencialidad. Solo el equipo profesional autorizado tendrá acceso a tu caso.</p>
                    </div>
                </div>
            </div>

            <!-- Importancia de datos -->
            <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded">
                <div class="flex items-start">
                    <svg class="w-6 h-6 text-blue-500 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                    </svg>
                    <div>
                        <p class="font-medium text-blue-900">Tus datos son necesarios</p>
                        <p class="text-sm text-blue-700 mt-1">Para brindarte el mejor acompañamiento y seguimiento a tu caso, necesitamos tus datos de contacto. Tu información será protegida con estricta confidencialidad.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Errores Generales -->
        <?php if (isset($errors['general'])): ?>
            <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded mb-6">
                <p class="text-red-800"><?= e($errors['general'][0]) ?></p>
            </div>
        <?php endif; ?>

        <!-- Formulario -->
        <div class="bg-white rounded-lg shadow-lg p-6 md:p-8">
            <form method="POST" action="" enctype="multipart/form-data" class="space-y-6" autocomplete="off">
                <?= csrf_field() ?>

                <!-- Honeypot (H-17) · invisible para las personas, irresistible
                     para los robots. Se oculta con posición absoluta y no con
                     display:none, porque algunos robots ignoran lo segundo.
                     aria-hidden y tabindex lo sacan del alcance del lector de
                     pantalla y del recorrido por teclado. -->
                <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
                    <label for="campo_sitio_web">No rellenes este campo</label>
                    <input type="text" id="campo_sitio_web" name="sitio_web"
                           tabindex="-1" autocomplete="off" value="">
                </div>

                <!-- SECCIÓN 1: INFORMACIÓN DEL INCIDENTE -->
                <div class="border-b pb-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4">📋 Información del Incidente</h3>
                </div>

                <!-- Tipo de Violencia -->
                <div>
                    <label for="campo_tipo_violencia" class="block text-sm font-medium text-gray-700 mb-2">
                        Tipo de Situación que Deseas Reportar <span class="text-red-600">*</span>
                    </label>
                    <select id="campo_tipo_violencia" name="tipo_violencia" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent">
                        <option value="">Seleccione el tipo...</option>
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

                <!-- Tipo de Agresor -->
                <div>
                    <label for="campo_tipo_agresor" class="block text-sm font-medium text-gray-700 mb-2">
                        Tipo de vínculo de la persona denunciada <span class="text-red-600">*</span>
                    </label>
                    <select id="campo_tipo_agresor" name="tipo_agresor" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent">
                        <option value="">Seleccione...</option>
                        <?php foreach ($tipos_agresor as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= old('tipo_agresor') === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['tipo_agresor'])): ?>
                        <p class="text-red-600 text-sm mt-1"><?= e($errors['tipo_agresor'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Vinculación de la persona denunciada -->
                <div>
                    <label for="campo_tipo_vinculacion_denunciada" class="block text-sm font-medium text-gray-700 mb-2">
                        ¿Qué vínculo tiene con la Universidad la persona que deseas denunciar? <span class="text-red-600">*</span>
                    </label>
                    <select id="campo_tipo_vinculacion_denunciada" name="tipo_vinculacion_denunciada" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent">
                        <?php foreach (tipos_vinculacion() as $valor => $etiqueta): ?>
                            <option value="<?= e($valor) ?>" <?= old('tipo_vinculacion_denunciada') === $valor ? 'selected' : '' ?>>
                                <?= e($etiqueta) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-sm text-gray-500 mt-1">
                        Este dato determina qué instancia de la Universidad debe atender el caso.
                        Si no lo sabes, elige la última opción: el equipo lo verificará.
                    </p>
                </div>

                <!-- Nombre de la persona denunciada (opcional) -->
                <div>
                    <label for="campo_agresor_nombre" class="block text-sm font-medium text-gray-700 mb-2">
                        Nombre o datos de la persona denunciada (si los conoces)
                    </label>
                    <input id="campo_agresor_nombre"
                        type="text"
                        name="agresor_nombre"
                        value="<?= old('agresor_nombre') ?>"
                        placeholder="Nombre, apodo o datos que ayuden a identificarla"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <p class="text-sm text-gray-500 mt-1">Opcional. Cualquier información que ayude a identificarla.</p>
                </div>

                <!-- Descripción -->
                <div>
                    <label for="campo_descripcion" class="block text-sm font-medium text-gray-700 mb-2">
                        Descripción de los Hechos <span class="text-red-600">*</span>
                    </label>
                    <textarea id="campo_descripcion"
                        name="descripcion"
                        rows="6"
                        required
                        placeholder="Describe lo que sucedió de la manera más detallada posible. Incluye información relevante como fechas, lugares, personas involucradas, etc."
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    ><?= old('descripcion') ?></textarea>
                    <p class="text-sm text-gray-500 mt-1">Mínimo 20 caracteres. Cuanta más información proporciones, mejor podremos ayudarte.</p>
                    <?php if (isset($errors['descripcion'])): ?>
                        <p class="text-red-600 text-sm mt-1"><?= e($errors['descripcion'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Testigos (Opcional) -->
                <div>
                    <label for="campo_testigos" class="block text-sm font-medium text-gray-700 mb-2">
                        Testigos (si los hay)
                    </label>
                    <textarea id="campo_testigos"
                        name="testigos"
                        rows="3"
                        placeholder="Nombres o descripción de personas que presenciaron los hechos"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    ><?= old('testigos') ?></textarea>
                    <p class="text-sm text-gray-500 mt-1">Opcional. Si hubo testigos, proporciona sus nombres o una descripción.</p>
                </div>

                <!-- Fecha de los Hechos -->
                <div>
                    <label for="campo_fecha_hechos" class="block text-sm font-medium text-gray-700 mb-2">
                        Fecha del Incidente <span class="text-red-600">*</span>
                    </label>
                    <input id="campo_fecha_hechos"
                        type="date"
                        name="fecha_hechos"
                        value="<?= old('fecha_hechos', date('Y-m-d')) ?>"
                        max="<?= date('Y-m-d') ?>"
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <?php if (isset($errors['fecha_hechos'])): ?>
                        <p class="text-red-600 text-sm mt-1"><?= e($errors['fecha_hechos'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <!-- SECCIÓN 2: UBICACIÓN -->
                <div class="border-t pt-6 mt-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4">📍 Ubicación del Incidente</h3>
                </div>

                <!-- Facultad/Departamento -->
                <div>
                    <label for="campo_facultad" class="block text-sm font-medium text-gray-700 mb-2">
                        Facultad o Departamento <span class="text-red-600">*</span>
                    </label>
                    <input id="campo_facultad"
                        type="text"
                        name="facultad"
                        value="<?= old('facultad') ?>"
                        placeholder="Ej: Facultad de Ingeniería, Departamento de Recursos Humanos"
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <?php if (isset($errors['facultad'])): ?>
                        <p class="text-red-600 text-sm mt-1"><?= e($errors['facultad'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Sede -->
                <div>
                    <label for="campo_sede" class="block text-sm font-medium text-gray-700 mb-2">
                        Sede <span class="text-red-600">*</span>
                    </label>
                    <input id="campo_sede"
                        type="text"
                        name="sede"
                        value="<?= old('sede') ?>"
                        placeholder="Ej: Sede Norte, Sede Principal, Campus Central"
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <?php if (isset($errors['sede'])): ?>
                        <p class="text-red-600 text-sm mt-1"><?= e($errors['sede'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Sede/espacio del catálogo (H-24 · RN-17) -->
                <?php
                $unidades_catalogo = db_select(
                    "SELECT id, nombre, tipo FROM unidades
                      WHERE activa = 1 AND tipo IN ('sede', 'espacio') ORDER BY tipo, nombre") ?: [];
                ?>
                <?php if (!empty($unidades_catalogo)): ?>
                <div>
                    <label for="campo_unidad_hechos" class="block text-sm font-medium text-gray-700 mb-2">
                        ¿En cuál de estos espacios ocurrió?
                    </label>
                    <select id="campo_unidad_hechos" name="unidad_hechos_id"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent">
                        <option value="">Prefiero describirlo arriba</option>
                        <?php foreach ($unidades_catalogo as $uc): ?>
                            <option value="<?= (int)$uc['id'] ?>" <?= old('unidad_hechos_id') == $uc['id'] ? 'selected' : '' ?>>
                                <?= e($uc['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-sm text-gray-500 mt-1">Incluye el entorno virtual y los alrededores de las sedes.
                        Nos ayuda a asignarte el equipo de acompañamiento más cercano.</p>
                </div>
                <?php endif; ?>

                <!-- Edificio (Opcional) -->
                <div>
                    <label for="campo_edificio" class="block text-sm font-medium text-gray-700 mb-2">
                        Edificio
                    </label>
                    <input id="campo_edificio"
                        type="text"
                        name="edificio"
                        value="<?= old('edificio') ?>"
                        placeholder="Ej: Edificio A, Torre de Administración"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <p class="text-sm text-gray-500 mt-1">Opcional. Si conoces el edificio específico.</p>
                </div>

                <!-- Salón/Área (Opcional) -->
                <div>
                    <label for="campo_salon_area" class="block text-sm font-medium text-gray-700 mb-2">
                        Salón, Oficina o Área Específica
                    </label>
                    <input id="campo_salon_area"
                        type="text"
                        name="salon_area"
                        value="<?= old('salon_area') ?>"
                        placeholder="Ej: Salón 301, Oficina de Decano, Cafetería"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <p class="text-sm text-gray-500 mt-1">Opcional. Salón, oficina o área donde ocurrió el incidente.</p>
                </div>

                <!-- SECCIÓN 3: TUS DATOS DE CONTACTO -->
                <div class="border-t pt-6 mt-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-2">👤 Tus Datos de Contacto <span class="text-red-600">*</span></h3>
                    <p class="text-sm text-gray-600 mb-4">Para poder brindarte acompañamiento y hacer seguimiento a tu caso, necesitamos los siguientes datos:</p>
                </div>

                <!-- ¿Quien denuncia es la persona afectada? -->
                <div>
                    <label for="campo_es_denunciante_la_afectada" class="block text-sm font-medium text-gray-700 mb-2">
                        ¿Los hechos te ocurrieron a ti? <span class="text-red-600">*</span>
                    </label>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <label class="flex items-start p-4 border border-gray-300 rounded-lg cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition">
                            <input id="campo_es_denunciante_la_afectada" type="radio" name="es_denunciante_la_afectada" value="si" class="mt-1 mr-3 text-purple-600 focus:ring-purple-500"
                                <?= old('es_denunciante_la_afectada') === 'no' ? '' : 'checked' ?>>
                            <span>
                                <span class="block font-medium text-gray-900">Sí, me ocurrieron a mí</span>
                                <span class="block text-sm text-gray-500">Podrás consultar el avance completo de tu caso</span>
                            </span>
                        </label>
                        <label for="campo_denunciante_nombre" class="flex items-start p-4 border border-gray-300 rounded-lg cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition">
                            <input type="radio" name="es_denunciante_la_afectada" value="no" class="mt-1 mr-3 text-purple-600 focus:ring-purple-500"
                                <?= old('es_denunciante_la_afectada') === 'no' ? 'checked' : '' ?>>
                            <span>
                                <span class="block font-medium text-gray-900">No, reporto por otra persona</span>
                                <span class="block text-sm text-gray-500">Solo verás el estado del trámite, no su contenido</span>
                            </span>
                        </label>
                    </div>
                    <p class="text-sm text-gray-500 mt-2">
                        Puedes denunciar aunque los hechos le hayan ocurrido a otra persona
                        (Acuerdo 003 de 2026, art. 4). Para proteger su intimidad, en ese caso
                        el acceso al expediente queda reservado.
                    </p>
                </div>

                <!-- Nombre Completo -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Nombre Completo <span class="text-red-600">*</span>
                    </label>
                    <input id="campo_denunciante_nombre"
                        type="text"
                        name="denunciante_nombre"
                        value="<?= old('denunciante_nombre') ?>"
                        placeholder="Tu nombre completo"
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <?php if (isset($errors['denunciante_nombre'])): ?>
                        <p class="text-red-600 text-sm mt-1"><?= e($errors['denunciante_nombre'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Teléfono -->
                <div>
                    <label for="campo_denunciante_telefono" class="block text-sm font-medium text-gray-700 mb-2">
                        Teléfono de Contacto <span class="text-red-600">*</span>
                    </label>
                    <input id="campo_denunciante_telefono"
                        type="tel"
                        name="denunciante_telefono"
                        value="<?= old('denunciante_telefono') ?>"
                        placeholder="Número de teléfono o WhatsApp"
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <?php if (isset($errors['denunciante_telefono'])): ?>
                        <p class="text-red-600 text-sm mt-1"><?= e($errors['denunciante_telefono'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Email -->
                <div>
                    <label for="campo_denunciante_email" class="block text-sm font-medium text-gray-700 mb-2">
                        Correo Electrónico <span class="text-red-600">*</span>
                    </label>
                    <input id="campo_denunciante_email"
                        type="email"
                        name="denunciante_email"
                        value="<?= old('denunciante_email') ?>"
                        placeholder="tu.correo@ejemplo.com"
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <?php if (isset($errors['denunciante_email'])): ?>
                        <p class="text-red-600 text-sm mt-1"><?= e($errors['denunciante_email'][0]) ?></p>
                    <?php endif; ?>
                    <p class="text-sm text-gray-500 mt-1">Tu correo personal. Si prefieres, es el único que usaremos.</p>
                </div>

                <!-- Correo institucional (opcional, RN-15) -->
                <div>
                    <label for="campo_email_institucional" class="block text-sm font-medium text-gray-700 mb-2">
                        Correo institucional (opcional)
                    </label>
                    <input id="campo_email_institucional"
                        type="email"
                        name="denunciante_email_institucional"
                        value="<?= old('denunciante_email_institucional') ?>"
                        placeholder="tu.nombre@udistrital.edu.co"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <p class="text-sm text-gray-500 mt-1">Solo te escribiremos ahí si tú lo eliges abajo.</p>
                </div>

                <!-- Canal preferido (RN-15: lo elige la persona) -->
                <div>
                    <label for="campo_canal_preferido" class="block text-sm font-medium text-gray-700 mb-2">
                        ¿Por dónde prefieres que te contactemos? <span class="text-red-600">*</span>
                    </label>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <label class="flex items-start p-4 border border-gray-300 rounded-lg cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition">
                            <input id="campo_canal_preferido" type="radio" name="canal_preferido" value="personal" class="mt-1 mr-3 text-purple-600 focus:ring-purple-500"
                                <?= in_array(old('canal_preferido'), ['sms', 'institucional', 'ambos'], true) ? '' : 'checked' ?>>
                            <span>
                                <span class="block font-medium text-gray-900">Correo personal</span>
                                <span class="block text-sm text-gray-500">Al correo que escribiste arriba</span>
                            </span>
                        </label>
                        <label class="flex items-start p-4 border border-gray-300 rounded-lg cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition">
                            <input type="radio" name="canal_preferido" value="institucional" class="mt-1 mr-3 text-purple-600 focus:ring-purple-500"
                                <?= old('canal_preferido') === 'institucional' ? 'checked' : '' ?>>
                            <span>
                                <span class="block font-medium text-gray-900">Correo institucional</span>
                                <span class="block text-sm text-gray-500">Solo si lo registraste arriba</span>
                            </span>
                        </label>
                        <label class="flex items-start p-4 border border-gray-300 rounded-lg cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition">
                            <input type="radio" name="canal_preferido" value="ambos" class="mt-1 mr-3 text-purple-600 focus:ring-purple-500"
                                <?= old('canal_preferido') === 'ambos' ? 'checked' : '' ?>>
                            <span>
                                <span class="block font-medium text-gray-900">Ambos correos</span>
                                <span class="block text-sm text-gray-500">Personal e institucional</span>
                            </span>
                        </label>
                        <label class="flex items-start p-4 border border-gray-300 rounded-lg cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition">
                            <input type="radio" name="canal_preferido" value="sms" class="mt-1 mr-3 text-purple-600 focus:ring-purple-500"
                                <?= old('canal_preferido') === 'sms' ? 'checked' : '' ?>>
                            <span>
                                <span class="block font-medium text-gray-900">Mensaje de texto</span>
                                <span class="block text-sm text-gray-500">Al número que escribiste arriba</span>
                            </span>
                        </label>
                    </div>
                    <p class="text-sm text-gray-500 mt-2">
                        Elige el medio al que solo tengas acceso tú. Una cuenta compartida o supervisada
                        puede ponerte en riesgo. Podrás cambiarlo llamando a la línea de atención.
                    </p>
                    <?php if (isset($errors['canal_preferido'])): ?>
                        <p class="text-red-600 text-sm mt-1"><?= e($errors['canal_preferido'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Menor de edad (RN-20) -->
                <div class="border border-gray-200 rounded-lg p-4">
                    <label class="flex items-start space-x-3">
                        <input type="checkbox" name="es_menor_de_edad" value="1" class="mt-1 text-purple-600 focus:ring-purple-500"
                            <?= old('es_menor_de_edad') === '1' ? 'checked' : '' ?>>
                        <span class="text-sm text-gray-700">Soy menor de 18 años</span>
                    </label>
                    <div class="mt-3">
                        <label for="campo_contacto_emergencia" class="block text-sm font-medium text-gray-700 mb-2">
                            Contacto de tu familia o acudiente (obligatorio si eres menor de edad)
                        </label>
                        <input id="campo_contacto_emergencia"
                            type="text"
                            name="contacto_emergencia"
                            value="<?= old('contacto_emergencia') ?>"
                            placeholder="Nombre y teléfono o correo"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                        >
                        <?php if (isset($errors['contacto_emergencia'])): ?>
                            <p class="text-red-600 text-sm mt-1"><?= e($errors['contacto_emergencia'][0]) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- SECCIÓN 4: EVIDENCIA -->
                <div class="border-t pt-6 mt-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-2">📎 Adjuntar Evidencia (Opcional)</h3>
                    <p class="text-sm text-gray-600 mb-4">
                        Puedes adjuntar capturas de pantalla, fotos, documentos u otra evidencia que respalde tu denuncia.
                    </p>

                    <label for="campo_archivos" class="block text-sm font-medium text-gray-700 mb-2">
                        Archivos de evidencia
                    </label>
                    <input id="campo_archivos"
                        type="file"
                        name="archivos[]"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-600 focus:border-transparent"
                    >
                    <p class="text-sm text-gray-500 mt-2">
                        <strong>Formatos permitidos:</strong> PDF, JPG, PNG, DOC, DOCX<br>
                        <strong>Tamaño máximo:</strong> 10MB por archivo
                    </p>
                </div>

                <!-- Botones -->
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-6 border-t">
                    <a href="?page=home" class="w-full sm:w-auto text-center px-6 py-3 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition">
                        Volver al Inicio
                    </a>
                    <button type="submit" class="w-full sm:w-auto px-8 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition shadow-lg">
                        Enviar Denuncia
                    </button>
                </div>
            </form>
        </div>

        <!-- Información Adicional -->
        <div class="mt-8 bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
            <div class="flex items-start">
                <svg class="w-6 h-6 text-yellow-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <p class="text-sm font-medium text-yellow-900">¿Estás en Peligro Inmediato?</p>
                    <p class="text-sm text-yellow-800 mt-1">
                        Si estás en peligro inminente, llama al <strong>123</strong> (Línea de emergencias) o acude a la estación de policía más cercana.
                        También puedes llamar a la Línea Nacional: <strong>155</strong>
                    </p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
clear_old_input();
require BASE_PATH . '/views/layouts/guest.php';
?>
