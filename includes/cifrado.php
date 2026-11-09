<?php
/**
 * CIFRADO EN REPOSO E ÍNDICE CIEGO
 *
 * Fase 2 · CLAUDE.md §11 (cifrado en reposo) y §8.3 (columnas *_cifrado / *_indice)
 *
 * Los datos de contacto de la persona denunciante son el vínculo entre un
 * relato y una persona identificable. Se guardan cifrados con AES-256-GCM,
 * y para poder buscarlos sin descifrar toda la tabla se acompaña un
 * "índice ciego": un HMAC-SHA256 determinista del valor normalizado.
 *
 * Un índice ciego permite responder «¿existe este correo?» sin permitir
 * «¿cuál es el correo de esta denuncia?». Quien obtenga una copia de la base
 * no puede revertirlo sin la clave.
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Derivar una subclave a partir de ENCRYPTION_KEY
 *
 * No se reutiliza la clave maestra directamente para dos propósitos
 * distintos (cifrado e indexación): son operaciones separadas y deben usar
 * material de clave separado.
 *
 * @param string $proposito Etiqueta del uso
 * @return string Subclave binaria de 32 bytes
 */
function derivar_subclave($proposito) {
    static $cache = [];

    if (isset($cache[$proposito])) {
        return $cache[$proposito];
    }

    $maestra = base64_decode(ENCRYPTION_KEY, true);

    if ($maestra === false || strlen($maestra) !== 32) {
        log_error('ENCRYPTION_KEY inválida al derivar subclave', 'error');
        return '';
    }

    $cache[$proposito] = hash_hmac('sha256', $proposito, $maestra, true);

    return $cache[$proposito];
}

/**
 * Normalizar un valor antes de indexarlo
 *
 * Dos personas que escriben «Ana@UD.edu.co» y «ana@ud.edu.co» deben producir
 * el mismo índice. Los teléfonos se reducen a sus dígitos.
 *
 * @param string $valor
 * @param string $tipo 'correo' | 'telefono'
 * @return string
 */
function normalizar_para_indice($valor, $tipo) {
    $valor = trim((string)$valor);

    if ($tipo === 'telefono') {
        $digitos = preg_replace('/\D+/', '', $valor);
        // Se descarta el indicativo de país para que +573001234567 y
        // 3001234567 produzcan el mismo índice
        if (strlen($digitos) > 10) {
            $digitos = substr($digitos, -10);
        }
        return $digitos;
    }

    return mb_strtolower($valor, 'UTF-8');
}

/**
 * Calcular el índice ciego de un valor
 *
 * @param string $valor Valor en claro
 * @param string $tipo 'correo' | 'telefono'
 * @return string|null 64 caracteres hexadecimales, o null si el valor está vacío
 */
function indice_ciego($valor, $tipo) {
    $normalizado = normalizar_para_indice($valor, $tipo);

    if ($normalizado === '') {
        return null;
    }

    $clave = derivar_subclave('indice_ciego_v1');

    if ($clave === '') {
        return null;
    }

    return hash_hmac('sha256', $tipo . ':' . $normalizado, $clave);
}

/**
 * Cifrar un dato de contacto
 *
 * @param string|null $valor
 * @return string|null Texto cifrado en base64, o null si no hay valor
 */
function cifrar_contacto($valor) {
    if ($valor === null || trim((string)$valor) === '') {
        return null;
    }

    $cifrado = encrypt_data(trim((string)$valor));

    if ($cifrado === false) {
        log_error('Fallo al cifrar dato de contacto', 'error');
        return null;
    }

    return $cifrado;
}

/**
 * Descifrar un dato de contacto
 *
 * @param string|null $cifrado
 * @return string|null Valor en claro, o null si no se puede descifrar
 */
function descifrar_contacto($cifrado) {
    if ($cifrado === null || $cifrado === '') {
        return null;
    }

    $plano = decrypt_data($cifrado);

    if ($plano === false) {
        log_error('Fallo al descifrar dato de contacto', 'error');
        return null;
    }

    return $plano;
}

/**
 * Enmascarar un dato de contacto para mostrarlo sin revelarlo
 *
 * Se usa en la pantalla que confirma el envío del código: la persona debe
 * reconocer su propio medio de contacto sin que un tercero pueda leerlo.
 *
 *   ana.perez@udistrital.edu.co  →  an*******@udistrital.edu.co
 *   3001234567                   →  ******4567
 *
 * @param string $valor
 * @param string $tipo 'correo' | 'telefono'
 * @return string
 */
function enmascarar_contacto($valor, $tipo) {
    $valor = (string)$valor;

    if ($tipo === 'telefono') {
        $digitos = preg_replace('/\D+/', '', $valor);
        $ultimos = substr($digitos, -4);
        return str_repeat('*', max(0, strlen($digitos) - 4)) . $ultimos;
    }

    $arroba = strpos($valor, '@');

    if ($arroba === false || $arroba < 1) {
        return str_repeat('*', max(1, strlen($valor)));
    }

    $usuario = substr($valor, 0, $arroba);
    $dominio = substr($valor, $arroba);
    $visibles = min(2, strlen($usuario));

    return substr($usuario, 0, $visibles)
         . str_repeat('*', max(1, strlen($usuario) - $visibles))
         . $dominio;
}
