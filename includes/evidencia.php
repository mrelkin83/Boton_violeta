<?php
/**
 * CADENA DE CUSTODIA DE LA EVIDENCIA
 *
 * Fase 6 · CLAUDE.md §8.4 · Ac. 003 art. 45 (integridad, trazabilidad y reserva)
 *
 * La Fase 0 bloqueó el acceso HTTP directo a `uploads/` (hallazgo H-08). Desde
 * entonces la evidencia está protegida pero es inalcanzable: no existía forma
 * autorizada de verla. Este archivo la restituye con garantías.
 *
 *   Al recibir:  tipo real por magic bytes (no por extensión ni por el MIME
 *                declarado por el navegador)
 *                SHA-256 calculado y almacenado
 *                nombre en disco no adivinable
 *
 *   Al acceder:  verificación de autorización sobre el expediente (RN-02)
 *                verificación del hash ANTES de servir
 *                registro en auditoría: quién, cuándo, desde dónde
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Firmas de archivo admitidas (magic bytes)
 *
 * Un `.pdf` que empieza por `<?php` no es un PDF. La extensión y el MIME que
 * declara el navegador los controla quien sube el archivo; los primeros bytes
 * del contenido, no.
 */
const FIRMAS_ARCHIVO = [
    'pdf'  => ["\x25\x50\x44\x46"],                       // %PDF
    'jpg'  => ["\xFF\xD8\xFF"],
    'jpeg' => ["\xFF\xD8\xFF"],
    'png'  => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
    'doc'  => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"],       // OLE2
    'docx' => ["\x50\x4B\x03\x04"],                        // ZIP
];

/**
 * Verificar que el contenido corresponde a la extensión declarada
 *
 * @param string $ruta_temporal
 * @param string $nombre_original
 * @return bool
 */
function verificar_firma_archivo($ruta_temporal, $nombre_original) {
    $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));

    if (!isset(FIRMAS_ARCHIVO[$ext])) {
        return false;
    }

    $manejador = @fopen($ruta_temporal, 'rb');

    if ($manejador === false) {
        return false;
    }

    $cabecera = fread($manejador, 16);
    fclose($manejador);

    foreach (FIRMAS_ARCHIVO[$ext] as $firma) {
        if (strncmp($cabecera, $firma, strlen($firma)) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Registrar el hash de integridad de un archivo ya almacenado
 *
 * @param int $archivo_id
 * @return string|false
 */
function sellar_evidencia($archivo_id) {
    $archivo = db_select_one('SELECT ruta FROM archivos WHERE id = ? LIMIT 1', [(int)$archivo_id]);

    if (!$archivo) {
        return false;
    }

    $ruta = UPLOAD_PATH . '/' . $archivo['ruta'];

    if (!is_file($ruta)) {
        log_error('Evidencia no encontrada en disco: archivo ' . $archivo_id, 'error');
        return false;
    }

    $hash = hash_file('sha256', $ruta);
    $mime = function_exists('finfo_open')
        ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $ruta) ?: null)
        : null;

    db_update_simple('archivos', [
        'hash_sha256'     => $hash,
        'mime_verificado' => $mime,
        'integridad_ok'   => 1,
        'verificado_at'   => date('Y-m-d H:i:s'),
    ], ['id' => (int)$archivo_id]);

    return $hash;
}

/**
 * Verificar la integridad de una evidencia
 *
 * @return array ['integra'=>bool,'motivo'=>string]
 */
function verificar_integridad_evidencia($archivo_id) {
    $archivo = db_select_one(
        'SELECT ruta, hash_sha256 FROM archivos WHERE id = ? LIMIT 1', [(int)$archivo_id]
    );

    if (!$archivo) {
        return ['integra' => false, 'motivo' => 'La evidencia no existe.'];
    }

    if (empty($archivo['hash_sha256'])) {
        return ['integra' => false, 'motivo' => 'La evidencia no tiene hash de integridad registrado.'];
    }

    $ruta = UPLOAD_PATH . '/' . $archivo['ruta'];

    if (!is_file($ruta)) {
        db_update_simple('archivos', ['integridad_ok' => 0], ['id' => (int)$archivo_id]);
        return ['integra' => false, 'motivo' => 'El archivo no está en disco.'];
    }

    $actual = hash_file('sha256', $ruta);

    if (!hash_equals($archivo['hash_sha256'], $actual)) {
        db_update_simple('archivos', ['integridad_ok' => 0], ['id' => (int)$archivo_id]);

        log_error('ALERTA: la evidencia ' . $archivo_id . ' fue alterada en disco', 'error');

        return ['integra' => false, 'motivo' => 'El contenido del archivo no coincide con su hash original.'];
    }

    return ['integra' => true, 'motivo' => ''];
}

/**
 * Entregar una evidencia a quien está autorizado
 *
 * Reemplaza el acceso por URL directa que existía antes de la Fase 0. La
 * verificación de integridad ocurre ANTES de servir: si el archivo fue
 * alterado, no se entrega.
 *
 * @param int $archivo_id
 * @param int $usuario_id
 * @param string $rol
 * @return array ['autorizado'=>bool,'motivo'=>string,'ruta'=>string|null,'nombre'=>string|null,'mime'=>string|null]
 */
function entregar_evidencia($archivo_id, $usuario_id, $rol) {
    $denegado = ['autorizado' => false, 'ruta' => null, 'nombre' => null, 'mime' => null];

    $archivo = db_select_one(
        'SELECT * FROM archivos WHERE id = ? LIMIT 1', [(int)$archivo_id]
    );

    if (!$archivo) {
        // Mismo mensaje que si no existiera autorización: la existencia de una
        // evidencia también está bajo reserva
        return $denegado + ['motivo' => 'Evidencia no encontrada.'];
    }

    // RN-02 · RN-08 · RN-12
    if (!puede_ver_expediente($usuario_id, $rol, (int)$archivo['denuncia_id'])) {
        audit_log($usuario_id, 'evidencia_acceso_denegado', 'archivos', [
            'rol' => $rol,
        ], (int)$archivo['denuncia_id']);

        return $denegado + ['motivo' => 'Evidencia no encontrada.'];
    }

    $integridad = verificar_integridad_evidencia($archivo_id);

    if (!$integridad['integra']) {
        audit_log($usuario_id, 'evidencia_integridad_fallida', 'archivos', [
            'rol' => $rol,
        ], (int)$archivo['denuncia_id']);

        return $denegado + ['motivo' => $integridad['motivo']];
    }

    // Ac. 003 art. 45: se audita cada visualización y cada descarga
    audit_log($usuario_id, 'evidencia_descargada', 'archivos', [
        'rol' => $rol,
    ], (int)$archivo['denuncia_id']);

    return [
        'autorizado' => true,
        'motivo'     => '',
        'ruta'       => UPLOAD_PATH . '/' . $archivo['ruta'],
        'nombre'     => $archivo['nombre_original'],
        'mime'       => $archivo['mime_verificado'] ?: 'application/octet-stream',
    ];
}

/**
 * Evidencias de un expediente, con su estado de integridad
 *
 * @return array
 */
function evidencias_del_caso($denuncia_id, $usuario_id, $rol) {
    if (!puede_ver_expediente($usuario_id, $rol, (int)$denuncia_id)) {
        return [];
    }

    return db_select(
        'SELECT id, nombre_original, tamano, hash_sha256, integridad_ok, created_at
           FROM archivos WHERE denuncia_id = ? ORDER BY created_at',
        [(int)$denuncia_id]
    ) ?: [];
}

/**
 * Sellar toda evidencia que aún no tenga hash
 *
 * Para los archivos cargados antes de la Fase 6. Su hash se calcula ahora, lo
 * que garantiza integridad de aquí en adelante — no retroactivamente: nadie
 * puede afirmar que un archivo no fue alterado antes de sellarlo.
 *
 * @return array ['sellados' => int, 'fallidos' => int]
 */
function sellar_evidencias_pendientes($limite = 500) {
    $pendientes = db_select(
        'SELECT id FROM archivos WHERE hash_sha256 IS NULL ORDER BY id LIMIT ' . (int)$limite
    ) ?: [];

    $sellados = 0;
    $fallidos = 0;

    foreach ($pendientes as $archivo) {
        if (sellar_evidencia((int)$archivo['id']) !== false) {
            $sellados++;
        } else {
            $fallidos++;
        }
    }

    return ['sellados' => $sellados, 'fallidos' => $fallidos];
}
