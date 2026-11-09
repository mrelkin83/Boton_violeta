<?php
/**
 * CONFIGURACIÓN OPERATIVA
 *
 * v3.1 · Fase E · Cierra H-20/H-26: la tabla `configuracion` existía sin uso.
 *
 * Las claves operativas (§6 · 0023) viven en base de datos y se editan desde
 * la consola de administración. Las constantes PHP del mismo nombre (definidas
 * en bv_config.php) actúan como valor por defecto si la clave no está en BD:
 * así ningún entorno queda sin valor durante la transición.
 *
 * Todo cambio se audita con el valor anterior y el nuevo.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Leer una clave de configuración
 *
 * Orden: tabla `configuracion` → constante PHP homónima → $por_defecto.
 * El tipo declarado en la tabla gobierna la conversión.
 *
 * @param string $clave
 * @param mixed $por_defecto
 * @return mixed
 */
function config_valor($clave, $por_defecto = null) {
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        $filas = db_select('SELECT clave, valor, tipo FROM configuracion') ?: [];
        foreach ($filas as $fila) {
            $valor = $fila['valor'];
            switch ($fila['tipo']) {
                case 'number':  $valor = $valor + 0; break;
                case 'boolean': $valor = in_array(strtolower((string)$valor), ['1', 'true', 'si'], true); break;
                case 'json':    $valor = json_decode((string)$valor, true); break;
            }
            $cache[$fila['clave']] = $valor;
        }
    }

    if (array_key_exists($clave, $cache)) {
        return $cache[$clave];
    }

    if (defined($clave)) {
        return constant($clave);
    }

    return $por_defecto;
}

/**
 * Cambiar una clave de configuración (consola admin)
 *
 * Se audita con el valor anterior y el nuevo (v3.1 §6 · 0023). Cambiar
 * RN14_BLOQUEA_ETAPA o ENCUENTRO_INICIAL_AUTOMATICO toca la compuerta G-15:
 * la pantalla lo advierte y el registro de auditoría lo deja rastreable.
 *
 * @return bool
 */
function config_establecer($clave, $valor_nuevo, $usuario_id) {
    $actual = db_select_one('SELECT id, valor FROM configuracion WHERE clave = ? LIMIT 1', [$clave]);

    if (!$actual) {
        log_error('Clave de configuración inexistente: ' . $clave, 'error');
        return false;
    }

    $ok = db_update_simple('configuracion', [
        'valor'      => (string)$valor_nuevo,
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => $actual['id']]);

    if ($ok === false) {
        return false;
    }

    audit_log($usuario_id, 'configuracion_cambiada', 'configuracion', [
        'clave'          => $clave,
        'valor_anterior' => $actual['valor'],
        'valor_nuevo'    => (string)$valor_nuevo,
    ], $actual['id']);

    return true;
}

/**
 * UUID v4 con el generador criptográfico (prohibición 9: nunca uniqid())
 *
 * @return string
 */
function uuid_v4() {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
