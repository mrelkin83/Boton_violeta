-- =============================================================================
-- Migración 0015 · Cadena de custodia, reportes y retención
-- =============================================================================
-- Fase:       6 · Cumplimiento
-- Reglas:     RN-10 (supresión estadística) · RN-13 (integridad de la auditoría)
-- Fundamento: Ac. 002 arts. 31, 40, 48 · Ac. 003 art. 45 · Ley 2365/2024 art. 11.7
--             Ley 1581/2012 · T-210/2023
--
-- Se ejecuta DESPUÉS de 0006_auditoria_cadena_hash.sql, que activa el
-- encadenamiento por hash de la tabla `auditoria`.
--
-- Sintaxis portable: MySQL 8.0+ y MariaDB 10.6+.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. CADENA DE CUSTODIA DE LA EVIDENCIA  ·  Ac. 003 art. 45
-- ─────────────────────────────────────────────────────────────────────────────
-- Hoy `archivos` guarda nombre, ruta y tamaño. Para que la evidencia sea
-- defendible hace falta poder demostrar que el archivo servido es el mismo que
-- se recibió, y quién lo ha visto.
ALTER TABLE `archivos`
  ADD COLUMN `hash_sha256`     CHAR(64) NULL
      COMMENT 'Calculado al recibir; verificado en cada entrega' AFTER `tamano`,
  ADD COLUMN `mime_verificado` VARCHAR(100) NULL
      COMMENT 'Tipo real por magic bytes, no por extensión ni por el MIME declarado' AFTER `hash_sha256`,
  ADD COLUMN `integridad_ok`   TINYINT(1) NULL
      COMMENT 'NULL = sin verificar · 0 = el hash no coincide' AFTER `mime_verificado`,
  ADD COLUMN `verificado_at`   DATETIME NULL AFTER `integridad_ok`;

ALTER TABLE `archivos`
  ADD INDEX `idx_integridad` (`integridad_ok`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. REPORTES GENERADOS
-- ─────────────────────────────────────────────────────────────────────────────
-- Los informes del art. 40 se remiten al CSU y a la Corte Constitucional. Debe
-- poder demostrarse qué se remitió y cuándo: el hash del contenido permite
-- probar que el documento entregado es el que el sistema generó.
CREATE TABLE IF NOT EXISTS `reportes_generados` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `tipo`             ENUM('trimestral','semestral_rectoria','semestral_sivige','anual_art40') NOT NULL,
  `periodo`          VARCHAR(20) NOT NULL COMMENT 'Ej. 2026-T2, 2026-S1, 2026',
  `generado_por`     INT NULL,
  `celdas_suprimidas` SMALLINT NOT NULL DEFAULT 0 COMMENT 'RN-10',
  `umbral_aplicado`  SMALLINT NOT NULL,
  `hash_contenido`   CHAR(64) NOT NULL,
  `contenido_json`   MEDIUMTEXT NOT NULL COMMENT 'Ya suprimido. Nunca contiene datos identificables',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_reporte` (`tipo`, `periodo`),
  INDEX `idx_tipo` (`tipo`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. RETENCIÓN Y SUPRESIÓN  ·  Ley 1581 de 2012
-- ─────────────────────────────────────────────────────────────────────────────
-- ⚠ `politicas_retencion` se entrega VACÍA.
--
--   Los períodos de retención por tipo de dato y el momento de anonimización
--   irreversible son la decisión 2 del §13.1 de CLAUDE.md, y corresponden a la
--   Oficina Asesora Jurídica junto con las tablas de retención documental de
--   la Universidad.
--
--   Fijar un plazo por cuenta propia significaría decidir cuándo se borra el
--   expediente de una víctima. No es una decisión técnica.
CREATE TABLE IF NOT EXISTS `politicas_retencion` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `entidad`            VARCHAR(60) NOT NULL COMMENT 'denuncias, archivos, auditoria…',
  `meses_retencion`    SMALLINT NOT NULL,
  `accion_al_vencer`   ENUM('anonimizar','eliminar','archivar_historico') NOT NULL,
  `excepcion_proceso_activo` TINYINT(1) NOT NULL DEFAULT 1
                       COMMENT 'No se aplica mientras haya proceso disciplinario en curso',
  `fundamento`         VARCHAR(255) NOT NULL,
  `aprobada_por`       VARCHAR(255) NOT NULL,
  `aprobada_at`        DATETIME NOT NULL,
  `activa`             TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_entidad` (`entidad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solicitudes de supresión del titular (hábeas data)
CREATE TABLE IF NOT EXISTS `solicitudes_supresion` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `denuncia_id`    INT NULL,
  `correo_indice`  CHAR(64) NULL COMMENT 'Índice ciego de quien solicita',
  `estado`         ENUM('recibida','en_analisis','procedente','improcedente','ejecutada') NOT NULL DEFAULT 'recibida',
  `motivo_decision` TEXT NULL COMMENT 'Obligatorio si es improcedente por retención legal',
  `decidida_por`   INT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ejecutada_at`   DATETIME NULL,
  INDEX `idx_estado` (`estado`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. AVISO DE PRIVACIDAD VERSIONADO  ·  Ley 1581, Decreto 1377
-- ─────────────────────────────────────────────────────────────────────────────
-- Los enlaces del pie apuntaban a `#` (hallazgo H-19). Debe poder demostrarse
-- QUÉ versión del aviso aceptó cada persona.
CREATE TABLE IF NOT EXISTS `avisos_privacidad` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `version`     VARCHAR(20) NOT NULL,
  `texto`       MEDIUMTEXT NOT NULL,
  `texto_hash`  CHAR(64) NOT NULL,
  `vigente`     TINYINT(1) NOT NULL DEFAULT 0,
  `publicado_at` DATETIME NOT NULL,
  UNIQUE KEY `uk_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `denuncias`
  ADD COLUMN `aviso_privacidad_version` VARCHAR(20) NULL
      COMMENT 'Versión del aviso aceptada al radicar' AFTER `canal_preferido`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. ACUERDOS DE CONFIDENCIALIDAD Y CERTIFICACIONES
-- ─────────────────────────────────────────────────────────────────────────────
-- Las comprobaciones existen en autorizacion.php desde la Fase 3, guardadas
-- tras EXIGIR_ACUERDO_CONFIDENCIALIDAD y EXIGIR_CERTIFICACION_VIGENTE. Faltaban
-- las tablas.
CREATE TABLE IF NOT EXISTS `acuerdos_confidencialidad` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id`  INT NOT NULL,
  `version`     VARCHAR(20) NOT NULL,
  `texto_hash`  CHAR(64) NOT NULL,
  `aceptado_at` DATETIME NOT NULL,
  `ip`          VARCHAR(45) NOT NULL,
  INDEX `idx_usuario` (`usuario_id`, `aceptado_at`),
  CONSTRAINT `fk_acuerdos_usuario` FOREIGN KEY (`usuario_id`)
      REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `certificaciones_equipo` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id`      INT NOT NULL,
  `nombre_curso`    VARCHAR(255) NOT NULL,
  `emitida_at`      DATE NOT NULL,
  `vence_at`        DATE NOT NULL COMMENT 'emitida + 2 años (Ac. 002 art. 20)',
  `constancia_ruta` VARCHAR(500) NULL,
  INDEX `idx_vigencia` (`usuario_id`, `vence_at`),
  CONSTRAINT `fk_certif_usuario` FOREIGN KEY (`usuario_id`)
      REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Verificación
-- =============================================================================
--   SHOW COLUMNS FROM archivos LIKE 'hash_sha256';
--   SHOW COLUMNS FROM auditoria LIKE 'hash_%';   -- requiere 0006
--   SELECT COUNT(*) FROM politicas_retencion;    -- 0 hasta que Jurídica las defina
--
--   -- Ningún reporte debe conservar celdas por debajo del umbral:
--   SELECT id, celdas_suprimidas, umbral_aplicado FROM reportes_generados;
