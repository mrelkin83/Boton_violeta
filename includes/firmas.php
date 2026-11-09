<?php
/**
 * FIRMAS DIGITALIZADAS
 *
 * Migración 0029 · La imagen de la firma de una persona es dato sensible:
 * permite suplantarla en documentos. Por eso:
 *
 *   · Se valida por MAGIC BYTES (PNG o JPEG reales), no por extensión (§8.4).
 *   · Se guarda con nombre UUID bajo uploads/firmas/, directorio que el
 *     .htaccess niega por completo.
 *   · Nunca se sirve por URL: los oficios la incrustan como data-URI, de modo
 *     que solo viaja dentro de un documento cuyo acceso ya pasó por
 *     puede_ver_expediente() y quedó auditado.
 *   · Cargarla o reemplazarla queda auditado.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/** Peso máximo de la imagen de firma (las escaneadas rondan 30-200 KB) */
const FIRMA_MAX_BYTES = 512000;

/**
 * Guardar (o reemplazar) la firma escaneada de un usuario
 *
 * @param int $usuario_id
 * @param array $archivo Entrada de $_FILES
 * @return array ['exito' => bool, 'error' => string|null]
 */
function guardar_firma_usuario($usuario_id, array $archivo) {
    $usuario_id = (int)$usuario_id;

    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['exito' => false, 'error' => 'Seleccione una imagen válida.'];
    }

    if ((int)$archivo['size'] > FIRMA_MAX_BYTES) {
        return ['exito' => false, 'error' => 'La imagen no puede superar los 500 KB.'];
    }

    // Magic bytes: PNG (89 50 4E 47) o JPEG (FF D8 FF). La extensión y el
    // MIME declarado por el navegador no cuentan (§8.4).
    $cabecera = (string)file_get_contents($archivo['tmp_name'], false, null, 0, 4);

    if (str_starts_with($cabecera, "\x89PNG")) {
        $extension = 'png';
    } elseif (str_starts_with($cabecera, "\xFF\xD8\xFF")) {
        $extension = 'jpg';
    } else {
        return ['exito' => false, 'error' => 'Solo se admite una imagen PNG o JPG real.'];
    }

    $directorio = UPLOAD_PATH . '/firmas';

    if (!is_dir($directorio)) {
        mkdir($directorio, 0750, true);
        // Cinturón además de las tirantas: negación propia por si el árbol
        // de uploads quedara servido en algún despliegue (§5.1.3)
        file_put_contents($directorio . '/.htaccess', "Require all denied\n");
    }

    $nombre = uuid_v4() . '.' . $extension; // prohibición 9: nunca uniqid()
    $destino = $directorio . '/' . $nombre;

    if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
        return ['exito' => false, 'error' => 'No se pudo guardar la imagen.'];
    }

    // Se retira la firma anterior: no deben quedar copias huérfanas
    $anterior = db_select_one('SELECT firma_ruta FROM usuarios WHERE id = ? LIMIT 1', [$usuario_id]);

    if (!empty($anterior['firma_ruta'])) {
        @unlink(UPLOAD_PATH . '/' . $anterior['firma_ruta']);
    }

    $ok = db_update_simple('usuarios', ['firma_ruta' => 'firmas/' . $nombre], ['id' => $usuario_id]);

    if ($ok === false) {
        @unlink($destino);
        return ['exito' => false, 'error' => 'No se pudo registrar la firma.'];
    }

    audit_log($usuario_id, 'firma_cargada', 'usuarios', [
        'motivo' => $extension,
    ], $usuario_id);

    return ['exito' => true, 'error' => null];
}

/**
 * Firma de un usuario como data-URI, para incrustar en un oficio
 *
 * @return string|null null si no tiene firma cargada
 */
function firma_datauri($usuario_id) {
    $fila = db_select_one('SELECT firma_ruta FROM usuarios WHERE id = ? LIMIT 1', [(int)$usuario_id]);

    if (empty($fila['firma_ruta'])) {
        return null;
    }

    $ruta = UPLOAD_PATH . '/' . $fila['firma_ruta'];

    if (!is_file($ruta)) {
        return null;
    }

    $mime = str_ends_with($fila['firma_ruta'], '.png') ? 'image/png' : 'image/jpeg';

    return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($ruta));
}
