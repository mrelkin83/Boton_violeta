-- =============================================================================
-- Migración 0001 · Esquema inicial
-- =============================================================================
-- Botón Violeta · Universidad Distrital Francisco José de Caldas
--
-- Crea las seis tablas base. NO siembra datos de ejemplo y NO crea cuentas:
-- para eso están `herramientas/crear_administrador.php` (entornos reales) y
-- `herramientas/instalar_local.php` (desarrollo).
--
-- Compatible con MySQL 8.0+ y MariaDB 10.6+.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- QUÉ SE CORRIGIÓ Y POR QUÉ
-- ─────────────────────────────────────────────────────────────────────────────
--
-- 1. `DROP TABLE IF EXISTS` antes de cada CREATE.
--    Lo más peligroso del archivo: ejecutarlo contra una base con datos los
--    destruía sin preguntar. Ahora usa CREATE TABLE IF NOT EXISTS.
--
-- 2. Tres cuentas con contraseñas publicadas en los comentarios, cuyos hashes
--    además NO correspondían a esas contraseñas. Un entorno recién instalado
--    tenía tres cuentas a las que nadie podía entrar mientras la documentación
--    afirmaba lo contrario. Se eliminaron: las cuentas se crean con
--    herramientas que generan el hash en el momento.
--
-- 3. Tres denuncias de ejemplo con relatos ficticios. Están hoy en la base de
--    PRODUCCIÓN (BV-20260324-0001, -0002 y -0003): esta migración las metió
--    ahí. Un esquema no debe sembrar casos.
--
-- 4. Dos registros de auditoría de ejemplo que incluían un correo dentro de
--    `datos`, contra la prohibición 1 de CLAUDE.md.
--
-- 5. `DELIMITER $$` para crear disparadores. `DELIMITER` no es SQL: es una
--    instrucción del cliente de línea de comandos. Cualquier herramienta que
--    ejecute el archivo por otra vía —PDO, por ejemplo— se corta ahí y deja el
--    esquema a medias sin avisar. Los disparadores de `updated_at` se
--    sustituyen por `ON UPDATE CURRENT_TIMESTAMP`, que es estándar.
--
-- 6. Dos procedimientos almacenados que ningún código invoca, uno de los
--    cuales solo devolvía un mensaje de texto.
--
-- 7. `SET AUTOCOMMIT=0; START TRANSACTION; … COMMIT;` alrededor de DDL. En
--    MySQL el DDL provoca confirmación implícita: la transacción era
--    decorativa y daba una falsa sensación de atomicidad.
--
-- 8. `int(11)`: el ancho de visualización está obsoleto desde MySQL 8.0.17.
--
-- 9. Un `CREATE USER … IDENTIFIED BY 'TU_PASSWORD_SEGURO_AQUI'` comentado,
--    esperando a que alguien lo descomentara sin cambiarlo.
--
-- 10. Datos de contacto de relleno en `configuracion` (123-456-7890 y un
--     correo genérico).
-- =============================================================================

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. USUARIOS · el equipo que atiende los casos
-- ─────────────────────────────────────────────────────────────────────────────
-- El catálogo de roles se amplía en la migración 0011, que introduce la
-- separación entre instrucción y juzgamiento (RN-01) y el rol de unidad
-- académica (RN-08).
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `nombre`        VARCHAR(255) NOT NULL,
  `email`         VARCHAR(255) NOT NULL,
  `password`      VARCHAR(255) NOT NULL COMMENT 'Hash Argon2id, nunca la contraseña',
  `rol`           ENUM('admin','coordinador','psicologo','trabajador_social','abogado','consulta')
                  NOT NULL DEFAULT 'consulta',
  `activo`        TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Borrado lógico',
  `ultimo_acceso` DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_rol` (`rol`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. DENUNCIAS · el expediente
-- ─────────────────────────────────────────────────────────────────────────────
-- `usuario_id` es NOT NULL aquí porque en la versión inicial toda denuncia se
-- radicaba desde una sesión del equipo. La migración 0003 lo hace nulable al
-- abrir el canal público, donde no hay sesión.
CREATE TABLE IF NOT EXISTS `denuncias` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `codigo`         VARCHAR(50) NOT NULL COMMENT 'Radicado público',
  `tipo_violencia` ENUM('fisica','psicologica','sexual','economica','verbal','otro') NOT NULL,
  `descripcion`    TEXT NOT NULL COMMENT 'El relato · dato más sensible del sistema (RN-03)',
  `fecha_hechos`   DATE NOT NULL,
  `lugar_hechos`   VARCHAR(500) NULL,
  `estado`         ENUM('nueva','en_proceso','resuelta','archivada') NOT NULL DEFAULT 'nueva',
  `usuario_id`     INT NOT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_codigo` (`codigo`),
  KEY `idx_estado` (`estado`),
  KEY `idx_tipo_violencia` (`tipo_violencia`),
  KEY `idx_usuario_id` (`usuario_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_estado_created` (`estado`, `created_at`),
  KEY `idx_tipo_created` (`tipo_violencia`, `created_at`),
  CONSTRAINT `fk_denuncias_usuario` FOREIGN KEY (`usuario_id`)
      REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Búsqueda de texto completo sobre el relato. Se añade por separado porque un
-- FULLTEXT dentro del CREATE TABLE falla en algunas versiones de MariaDB.
ALTER TABLE `denuncias` ADD FULLTEXT KEY `idx_fulltext_descripcion` (`descripcion`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. AUDITORÍA · trazabilidad (Ac. 003 art. 45)
-- ─────────────────────────────────────────────────────────────────────────────
-- La migración 0006 añade el encadenamiento por hash que exige RN-13.
--
-- ⚠ `datos` NUNCA debe contener relato, nombres, correos ni teléfonos
--   (prohibición 1). `sanear_datos_auditoria()` lo garantiza en código.
CREATE TABLE IF NOT EXISTS `auditoria` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT NULL COMMENT 'NULL para actuaciones sin sesión',
  `accion`      VARCHAR(100) NOT NULL,
  `tabla`       VARCHAR(100) NULL,
  `registro_id` INT NULL,
  `datos`       TEXT NULL COMMENT 'JSON depurado, sin datos personales',
  `ip`          VARCHAR(45) NULL,
  `user_agent`  VARCHAR(500) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuario_id` (`usuario_id`),
  KEY `idx_accion` (`accion`),
  KEY `idx_tabla` (`tabla`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_usuario_accion` (`usuario_id`, `accion`),
  CONSTRAINT `fk_auditoria_usuario` FOREIGN KEY (`usuario_id`)
      REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. ARCHIVOS · evidencia adjunta
-- ─────────────────────────────────────────────────────────────────────────────
-- La 0005 hace `usuario_id` nulable, porque el canal público carga evidencia
-- sin sesión. La 0015 añade la cadena de custodia (hash SHA-256).
CREATE TABLE IF NOT EXISTS `archivos` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `denuncia_id`       INT NOT NULL,
  `nombre_original`   VARCHAR(255) NOT NULL,
  `nombre_almacenado` VARCHAR(255) NOT NULL COMMENT 'Nombre no adivinable en disco',
  `ruta`              VARCHAR(500) NOT NULL COMMENT 'Relativa a UPLOAD_PATH',
  `tipo_mime`         VARCHAR(100) NOT NULL,
  `tamano`            INT NOT NULL,
  `usuario_id`        INT NOT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_denuncia_id` (`denuncia_id`),
  KEY `idx_usuario_id` (`usuario_id`),
  CONSTRAINT `fk_archivos_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_archivos_usuario` FOREIGN KEY (`usuario_id`)
      REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. SEGUIMIENTOS · bitácora del caso
-- ─────────────────────────────────────────────────────────────────────────────
-- `privado = 1` marca las notas internas. La consulta pública filtra por
-- `privado = 0`: sin ese filtro, las notas reservadas se mostrarían a
-- cualquiera con un radicado (hallazgo H-05).
CREATE TABLE IF NOT EXISTS `seguimientos` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `denuncia_id` INT NOT NULL,
  `usuario_id`  INT NOT NULL,
  `tipo`        ENUM('nota','actualizacion','contacto','derivacion','resolucion')
                NOT NULL DEFAULT 'nota',
  `contenido`   TEXT NOT NULL,
  `privado`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = solo visible internamente',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_denuncia_id` (`denuncia_id`),
  KEY `idx_usuario_id` (`usuario_id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_denuncia_created` (`denuncia_id`, `created_at`),
  CONSTRAINT `fk_seguimientos_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_seguimientos_usuario` FOREIGN KEY (`usuario_id`)
      REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. CONFIGURACIÓN · parámetros clave/valor
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `configuracion` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `clave`       VARCHAR(100) NOT NULL,
  `valor`       TEXT NULL,
  `descripcion` VARCHAR(500) NULL,
  `tipo`        ENUM('string','number','boolean','json') NOT NULL DEFAULT 'string',
  `updated_at`  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_clave` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuración con valores por defecto — no son datos de ejemplo.
-- `INSERT IGNORE` evita pisar los que ya existan.
INSERT IGNORE INTO `configuracion` (`clave`, `valor`, `descripcion`, `tipo`) VALUES
('sistema_nombre',            'Botón Violeta',                    'Nombre del sistema',                        'string'),
('sistema_email',             'vbgbienestarud@udistrital.edu.co', 'Correo institucional de atención',          'string'),
('sistema_telefono',          '601 323 8309',                     'Línea de atención',                         'string'),
('denuncias_codigo_prefijo',  'BV',                               'Prefijo del radicado',                      'string'),
('sesion_duracion',           '120',                              'Duración de la sesión en minutos',          'number'),
('max_archivos_por_denuncia', '10',                               'Máximo de archivos por denuncia',           'number'),
('tamano_maximo_archivo',     '10485760',                         'Tamaño máximo de archivo en bytes (10 MB)', 'number');

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. VISTAS DE REPORTE
-- ─────────────────────────────────────────────────────────────────────────────
-- ⚠ Se enumeran las columnas explícitamente en lugar de usar `d.*`: con `*`,
--   MySQL expande la lista al CREAR la vista, de modo que las columnas que
--   añaden las migraciones posteriores no aparecen nunca. La vista queda
--   desfasada en silencio y quien la consulta no tiene cómo notarlo.
--
--   Ninguna consulta del sistema las usa hoy (hallazgo H-20). Se conservan
--   porque existen en producción.
CREATE OR REPLACE VIEW `vista_estadisticas_denuncias` AS
SELECT
    DATE_FORMAT(`created_at`, '%Y-%m')                          AS mes,
    COUNT(*)                                                    AS total,
    SUM(CASE WHEN `estado` = 'nueva'      THEN 1 ELSE 0 END)    AS nuevas,
    SUM(CASE WHEN `estado` = 'en_proceso' THEN 1 ELSE 0 END)    AS en_proceso,
    SUM(CASE WHEN `estado` = 'resuelta'   THEN 1 ELSE 0 END)    AS resueltas,
    SUM(CASE WHEN `estado` = 'archivada'  THEN 1 ELSE 0 END)    AS archivadas
FROM `denuncias`
GROUP BY DATE_FORMAT(`created_at`, '%Y-%m')
ORDER BY mes DESC;

-- La vista NO expone `descripcion`: el relato no debe viajar en una
-- proyección de reporte (RN-03).
CREATE OR REPLACE VIEW `vista_denuncias_completas` AS
SELECT
    d.`id`, d.`codigo`, d.`tipo_violencia`, d.`fecha_hechos`, d.`lugar_hechos`,
    d.`estado`, d.`usuario_id`, d.`created_at`, d.`updated_at`,
    u.`nombre` AS usuario_nombre,
    u.`email`  AS usuario_email,
    u.`rol`    AS usuario_rol,
    (SELECT COUNT(*) FROM `seguimientos` s WHERE s.`denuncia_id` = d.`id`) AS total_seguimientos,
    (SELECT COUNT(*) FROM `archivos`     a WHERE a.`denuncia_id` = d.`id`) AS total_archivos
FROM `denuncias` d
LEFT JOIN `usuarios` u ON d.`usuario_id` = u.`id`;

-- =============================================================================
-- Verificación
-- =============================================================================
--   SHOW TABLES;                          -- 6 tablas + 2 vistas
--   SELECT COUNT(*) FROM usuarios;        -- 0 · las cuentas se crean aparte
--   SELECT COUNT(*) FROM denuncias;       -- 0 · el esquema no siembra casos
--   SELECT COUNT(*) FROM configuracion;   -- 7
--
-- Siguiente paso:
--   php herramientas/crear_administrador.php          (entornos reales)
--   php herramientas/instalar_local.php --confirmar   (desarrollo)
-- =============================================================================
