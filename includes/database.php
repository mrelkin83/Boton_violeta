<?php
/**
 * CONEXIÓN A BASE DE DATOS - PDO
 *
 * Manejo de conexión centralizado con PDO y prepared statements
 * para prevenir SQL Injection
 */

// Variable global para la conexión
$db = null;

/**
 * Obtener conexión a base de datos (Singleton)
 *
 * @return PDO|null
 */
function db() {
    global $db;

    // Si ya existe conexión, retornarla
    if ($db !== null) {
        return $db;
    }

    try {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
            PDO::ATTR_PERSISTENT => false,
        ];

        $db = new PDO($dsn, DB_USER, DB_PASS, $options);

        return $db;
    } catch (PDOException $e) {
        // Log el error
        log_error('Database connection failed: ' . $e->getMessage());

        // Mostrar error solo en desarrollo
        if (APP_DEBUG) {
            die('Database connection failed: ' . $e->getMessage());
        } else {
            die('Error al conectar con la base de datos. Por favor contacte al administrador.');
        }
    }
}

/**
 * Ejecutar consulta SELECT
 *
 * @param string $query SQL query
 * @param array $params Parámetros para prepared statement
 * @return array|false Resultados o false en error
 */
function db_select($query, $params = []) {
    try {
        $stmt = db()->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        log_error('Query error: ' . $e->getMessage() . ' | Query: ' . $query);
        return false;
    }
}

/**
 * Ejecutar consulta SELECT y retornar primera fila
 *
 * @param string $query SQL query
 * @param array $params Parámetros para prepared statement
 * @return array|false Primera fila o false en error
 */
function db_select_one($query, $params = []) {
    try {
        $stmt = db()->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ? $result : false;
    } catch (PDOException $e) {
        log_error('Query error: ' . $e->getMessage() . ' | Query: ' . $query);
        return false;
    }
}

/**
 * Ejecutar consulta INSERT
 *
 * @param string $query SQL query
 * @param array $params Parámetros para prepared statement
 * @return int|false ID insertado o false en error
 */
function db_insert($query, $params = []) {
    try {
        $stmt = db()->prepare($query);
        $stmt->execute($params);
        return db()->lastInsertId();
    } catch (PDOException $e) {
        log_error('Insert error: ' . $e->getMessage() . ' | Query: ' . $query);
        return false;
    }
}

/**
 * Ejecutar consulta UPDATE o DELETE
 *
 * @param string $query SQL query
 * @param array $params Parámetros para prepared statement
 * @return int|false Número de filas afectadas o false en error
 */
function db_execute($query, $params = []) {
    try {
        $stmt = db()->prepare($query);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        log_error('Execute error: ' . $e->getMessage() . ' | Query: ' . $query);
        return false;
    }
}

/**
 * Iniciar transacción
 *
 * @return bool
 */
function db_begin_transaction() {
    return db()->beginTransaction();
}

/**
 * Confirmar transacción
 *
 * @return bool
 */
function db_commit() {
    return db()->commit();
}

/**
 * Revertir transacción
 *
 * @return bool
 */
function db_rollback() {
    return db()->rollBack();
}

/**
 * Verificar si está en transacción
 *
 * @return bool
 */
function db_in_transaction() {
    return db()->inTransaction();
}

/**
 * Cerrar conexión (opcional, se cierra automáticamente al fin del script)
 */
function db_close() {
    global $db;
    $db = null;
}

/**
 * Verificar conexión a base de datos
 *
 * @return bool
 */
function db_check_connection() {
    try {
        $result = db_select_one("SELECT 1 as test");
        return $result !== false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Helper: Construir WHERE dinámico desde array
 *
 * @param array $conditions ['campo' => 'valor']
 * @param string $operator 'AND' | 'OR'
 * @return array ['sql' => 'campo = ? AND campo2 = ?', 'params' => [valor1, valor2]]
 */
function db_build_where($conditions, $operator = 'AND') {
    if (empty($conditions)) {
        return ['sql' => '1=1', 'params' => []];
    }

    $clauses = [];
    $params = [];

    foreach ($conditions as $field => $value) {
        if (is_null($value)) {
            $clauses[] = "`$field` IS NULL";
        } else {
            $clauses[] = "`$field` = ?";
            $params[] = $value;
        }
    }

    $sql = implode(" $operator ", $clauses);

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Helper: Insertar registro simple
 *
 * @param string $table Nombre de la tabla
 * @param array $data ['campo' => 'valor']
 * @return int|false ID insertado o false
 */
function db_insert_simple($table, $data) {
    if (empty($data)) {
        return false;
    }

    $fields = array_keys($data);
    $values = array_values($data);

    $fields_sql = '`' . implode('`, `', $fields) . '`';
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));

    $query = "INSERT INTO `$table` ($fields_sql) VALUES ($placeholders)";

    return db_insert($query, $values);
}

/**
 * Helper: Actualizar registro simple
 *
 * @param string $table Nombre de la tabla
 * @param array $data ['campo' => 'valor']
 * @param array $where ['campo' => 'valor']
 * @return int|false Filas afectadas o false
 */
function db_update_simple($table, $data, $where) {
    if (empty($data) || empty($where)) {
        return false;
    }

    $set_clauses = [];
    $params = [];

    foreach ($data as $field => $value) {
        $set_clauses[] = "`$field` = ?";
        $params[] = $value;
    }

    $set_sql = implode(', ', $set_clauses);

    $where_build = db_build_where($where);
    $params = array_merge($params, $where_build['params']);

    $query = "UPDATE `$table` SET $set_sql WHERE {$where_build['sql']}";

    return db_execute($query, $params);
}

/**
 * Helper: Eliminar registro simple
 *
 * @param string $table Nombre de la tabla
 * @param array $where ['campo' => 'valor']
 * @return int|false Filas afectadas o false
 */
function db_delete_simple($table, $where) {
    if (empty($where)) {
        return false; // Prevenir DELETE sin WHERE
    }

    $where_build = db_build_where($where);

    $query = "DELETE FROM `$table` WHERE {$where_build['sql']}";

    return db_execute($query, $where_build['params']);
}

/**
 * Helper: Contar registros
 *
 * @param string $table Nombre de la tabla
 * @param array $where Condiciones (opcional)
 * @return int|false Contador o false
 */
function db_count($table, $where = []) {
    $where_build = db_build_where($where);

    $query = "SELECT COUNT(*) as total FROM `$table` WHERE {$where_build['sql']}";

    $result = db_select_one($query, $where_build['params']);

    return $result ? (int)$result['total'] : false;
}

/**
 * Helper: Verificar si existe registro
 *
 * @param string $table Nombre de la tabla
 * @param array $where Condiciones
 * @return bool
 */
function db_exists($table, $where) {
    return db_count($table, $where) > 0;
}
?>
