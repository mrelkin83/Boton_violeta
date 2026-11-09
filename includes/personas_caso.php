<?php
/**
 * PERSONAS DEL CASO
 *
 * Fase 3 · CLAUDE.md §8.3 · Actualizada en v3.1 (migraciones 0018 y 0024):
 * doble correo (RN-15), menores de edad y contacto de emergencia (RN-20).
 *
 * Los datos de las personas dejan de vivir aplanados en columnas de
 * `denuncias` y pasan a una entidad propia con papel explícito.
 *
 * Terminología obligatoria (§2): `denunciante`, `afectada`, `denunciada`,
 * `testigo`. Nunca «agresor» ni «victimario» — rige la presunción de
 * inocencia (Ac. 003 art. 7).
 */

if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Acceso denegado');
}

/**
 * Registrar una persona vinculada a un caso
 *
 * Los datos identificatorios se cifran en reposo. El nombre también: en un
 * expediente de violencia de género, el nombre de la persona afectada es tan
 * sensible como su teléfono.
 *
 * RN-20: si `es_menor_de_edad` es verdadero, el contacto de emergencia
 * (familiar o acudiente) es obligatorio y el canal no puede ser 'ninguno'.
 *
 * @param int $denuncia_id
 * @param string $papel denunciante|afectada|denunciada|testigo
 * @param array $datos nombre, correo (personal), correo_institucional,
 *                     telefono, tipo_vinculacion, unidad_id, canal_preferido,
 *                     es_mismo_denunciante, es_menor_de_edad,
 *                     contacto_emergencia
 * @return int|false
 */
function registrar_persona_caso($denuncia_id, $papel, array $datos) {
    $papeles = ['denunciante', 'afectada', 'denunciada', 'testigo'];

    if (!in_array($papel, $papeles, true)) {
        log_error('Papel de persona no válido: ' . var_export($papel, true), 'error');
        return false;
    }

    $correo_personal      = $datos['correo'] ?? $datos['correo_personal'] ?? null;
    $correo_institucional = $datos['correo_institucional'] ?? null;
    $telefono             = $datos['telefono'] ?? null;
    $es_menor             = array_key_exists('es_menor_de_edad', $datos)
                          ? (!empty($datos['es_menor_de_edad']) ? 1 : 0)
                          : null;
    $contacto_emergencia  = $datos['contacto_emergencia'] ?? null;

    // RN-20 · menor de edad: contacto de emergencia obligatorio y canal activo
    if ($es_menor === 1 && in_array($papel, ['afectada', 'denunciante'], true)) {
        if ($contacto_emergencia === null || trim((string)$contacto_emergencia) === '') {
            log_error('RN-20: persona afectada menor de edad sin contacto de emergencia', 'error');
            return false;
        }
        if (($datos['canal_preferido'] ?? null) === 'ninguno') {
            log_error('RN-20: una persona menor de edad no puede quedar sin canal de contacto', 'error');
            return false;
        }
    }

    $fila = [
        'denuncia_id'                  => (int)$denuncia_id,
        'papel'                        => $papel,
        'nombre_cifrado'               => cifrar_contacto($datos['nombre'] ?? null),
        'correo_personal_cifrado'      => cifrar_contacto($correo_personal),
        'correo_institucional_cifrado' => cifrar_contacto($correo_institucional),
        'telefono_cifrado'             => cifrar_contacto($telefono),
        'correo_personal_indice'       => $correo_personal      ? indice_ciego($correo_personal, 'correo')      : null,
        'correo_institucional_indice'  => $correo_institucional ? indice_ciego($correo_institucional, 'correo') : null,
        'telefono_indice'              => $telefono             ? indice_ciego($telefono, 'telefono')           : null,
        'canal_preferido'              => $datos['canal_preferido'] ?? null,
        'tipo_vinculacion'             => $datos['tipo_vinculacion'] ?? null,
        'unidad_id'                    => isset($datos['unidad_id']) && $datos['unidad_id'] ? (int)$datos['unidad_id'] : null,
        'es_mismo_denunciante'         => !empty($datos['es_mismo_denunciante']) ? 1 : 0,
        'es_menor_de_edad'             => $es_menor,
        'contacto_emergencia_cifrado'  => cifrar_contacto($contacto_emergencia),
        'created_at'                   => date('Y-m-d H:i:s'),
    ];

    $id = db_insert_simple('personas_caso', $fila);

    if (!$id) {
        log_error('No se pudo registrar la persona del caso', 'error');
        return false;
    }

    return (int)$id;
}

/**
 * Obtener las personas de un caso, con los datos descifrados
 *
 * ⚠ Devuelve datos personales en claro. Quien la llame debe haber verificado
 * antes `puede_ver_campo(..., 'denunciante_nombre')` o equivalente, y dejar
 * constancia en la auditoría.
 *
 * @param int $denuncia_id
 * @param string|null $papel Filtrar por papel
 * @return array
 */
function personas_del_caso($denuncia_id, $papel = null) {
    $sql = 'SELECT * FROM personas_caso WHERE denuncia_id = ?';
    $params = [(int)$denuncia_id];

    if ($papel !== null) {
        $sql .= ' AND papel = ?';
        $params[] = $papel;
    }

    $filas = db_select($sql . ' ORDER BY id', $params) ?: [];

    foreach ($filas as &$fila) {
        $fila['nombre']               = descifrar_contacto($fila['nombre_cifrado']);
        $fila['correo']               = descifrar_contacto($fila['correo_personal_cifrado']);
        $fila['correo_personal']      = $fila['correo'];
        $fila['correo_institucional'] = descifrar_contacto($fila['correo_institucional_cifrado']);
        $fila['telefono']             = descifrar_contacto($fila['telefono_cifrado']);
        $fila['contacto_emergencia']  = descifrar_contacto($fila['contacto_emergencia_cifrado'] ?? null);

        // No se devuelven los campos cifrados ni los índices: quien consume
        // esta función necesita los valores, no el material criptográfico
        unset($fila['nombre_cifrado'], $fila['correo_personal_cifrado'],
              $fila['correo_institucional_cifrado'], $fila['telefono_cifrado'],
              $fila['correo_personal_indice'], $fila['correo_institucional_indice'],
              $fila['telefono_indice'], $fila['contacto_emergencia_cifrado']);
    }

    return $filas;
}

/**
 * Buscar casos asociados a un contacto, sin descifrar la tabla
 *
 * Usa el índice ciego. Sirve para detectar que una misma persona ha
 * denunciado varias veces, o que aparece en varios casos. El correo se busca
 * en ambos índices (personal e institucional).
 *
 * @param string $valor Correo o teléfono en claro
 * @param string $tipo 'correo' | 'telefono'
 * @return array Identificadores de denuncia
 */
function casos_por_contacto($valor, $tipo) {
    $indice = indice_ciego($valor, $tipo);

    if ($indice === null) {
        return [];
    }

    // El nombre de columna no puede viajar como marcador de posición. En vez de
    // interpolarlo desde una lista blanca —que es seguro, pero obliga a cada
    // lector y a cada analizador a demostrarlo— se escriben las consultas
    // completas. Solo son dos, y así no queda ninguna consulta construida.
    if ($tipo === 'correo') {
        $sql = 'SELECT DISTINCT denuncia_id FROM personas_caso
                 WHERE correo_personal_indice = ? OR correo_institucional_indice = ?';
        $params = [$indice, $indice];
    } elseif ($tipo === 'telefono') {
        $sql = 'SELECT DISTINCT denuncia_id FROM personas_caso WHERE telefono_indice = ?';
        $params = [$indice];
    } else {
        log_error('Tipo de contacto no admitido en la búsqueda: ' . var_export($tipo, true), 'error');
        return [];
    }

    $filas = db_select($sql, $params) ?: [];

    return array_column($filas, 'denuncia_id');
}
