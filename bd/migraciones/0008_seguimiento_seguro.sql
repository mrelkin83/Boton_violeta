-- =============================================================================
-- Migración 0008 · Seguimiento público con segundo factor
-- =============================================================================
-- Fase:        2 · Seguimiento seguro
-- Hallazgo:    H-06 — el radicado por sí solo daba acceso a los datos del caso
-- Fundamento:  CLAUDE.md §8.3 y §10.2 · Ley 1581 de 2012 · Ac. 003 art. 47
--
-- Añade:
--   1. `codigos_seguimiento` — códigos de un solo uso, almacenados como hash
--      con sal. El código en claro NUNCA se persiste.
--   2. Columnas de contacto cifradas en `denuncias`, con índice ciego para
--      poder buscar sin descifrar, y canal preferido elegido por la persona.
--
-- Nota sobre `persona_id`: queda NULL y sin clave foránea. La tabla
-- `personas_caso` pertenece a la Fase 3; entonces se añadirá la restricción.
--
-- La columna heredada `denunciante_contacto` NO se elimina en esta migración.
-- Primero debe ejecutarse la migración 0009, verificar el resultado y recién
-- después retirarla (0010). Borrar el origen antes de validar el destino es
-- cómo se pierden los datos de contacto de una víctima.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Códigos de un solo uso
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `codigos_seguimiento` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `denuncia_id`  INT NOT NULL,
  `persona_id`   INT NULL COMMENT 'Fase 3: referencia a personas_caso',
  `codigo_hash`  CHAR(64) NOT NULL COMMENT 'SHA-256 de sal||codigo. El código en claro nunca se almacena',
  `sal`          CHAR(32) NOT NULL,
  `canal`        ENUM('correo','sms') NOT NULL DEFAULT 'correo',
  `intentos`     TINYINT NOT NULL DEFAULT 0 COMMENT 'Máximo 3; al agotarse el código muere',
  `expira_at`    DATETIME NOT NULL COMMENT 'Emisión + 10 minutos',
  `consumido_at` DATETIME NULL COMMENT 'No NULL = ya usado o invalidado',
  `ip_solicitud` VARCHAR(45) NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_denuncia` (`denuncia_id`, `created_at`),
  INDEX `idx_vigentes` (`denuncia_id`, `consumido_at`, `expira_at`),
  CONSTRAINT `fk_codigos_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Contacto separado y cifrado
-- ─────────────────────────────────────────────────────────────────────────────
-- Hoy el teléfono y el correo se concatenan en `denunciante_contacto` como
-- «3001234567 | persona@correo.com», lo que impide validarlos, cifrarlos y
-- enviarles un código de forma independiente.
ALTER TABLE `denuncias`
  ADD COLUMN `denunciante_correo_cifrado`   VARBINARY(512) NULL
      COMMENT 'AES-256-GCM en base64' AFTER `denunciante_contacto`,
  ADD COLUMN `denunciante_telefono_cifrado` VARBINARY(256) NULL
      COMMENT 'AES-256-GCM en base64' AFTER `denunciante_correo_cifrado`,
  ADD COLUMN `denunciante_correo_indice`    CHAR(64) NULL
      COMMENT 'HMAC-SHA256, índice ciego' AFTER `denunciante_telefono_cifrado`,
  ADD COLUMN `denunciante_telefono_indice`  CHAR(64) NULL
      COMMENT 'HMAC-SHA256, índice ciego' AFTER `denunciante_correo_indice`,
  ADD COLUMN `canal_preferido` ENUM('correo','sms') NOT NULL DEFAULT 'correo'
      COMMENT 'Elegido por la persona denunciante al radicar' AFTER `denunciante_telefono_indice`;

ALTER TABLE `denuncias`
  ADD INDEX `idx_correo_indice`   (`denunciante_correo_indice`),
  ADD INDEX `idx_telefono_indice` (`denunciante_telefono_indice`);

-- ─────────────────────────────────────────────────────────────────────────────
-- Verificación
-- ─────────────────────────────────────────────────────────────────────────────
--   SHOW COLUMNS FROM denuncias LIKE 'denunciante_%';
--   SHOW COLUMNS FROM denuncias LIKE 'canal_preferido';
--   SHOW CREATE TABLE codigos_seguimiento;
--
-- Siguiente paso obligatorio: bd/migraciones/0009_cifrar_contactos.php
