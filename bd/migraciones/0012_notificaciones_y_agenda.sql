-- =============================================================================
-- Migración 0012 · Notificaciones (Outbox) y agenda de encuentros
-- =============================================================================
-- Fase:       4 · Microsoft 365
-- Reglas:     RN-04 (no confrontación) · RN-09 (notificación sin carga)
-- Fundamento: Ac. 002 art. 25 lit. c y art. 47.5 · Ac. 003 arts. 43, 47 y 53
-- ADR:        ADR-06 (el correo solo transporta el radicado) · ADR-08 (Outbox)
--
-- Sintaxis portable: MySQL 8.0+ y MariaDB 10.6+.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. OUTBOX DE NOTIFICACIONES
-- ─────────────────────────────────────────────────────────────────────────────
-- ADR-08: ninguna llamada de red bloquea el envío de una denuncia. Si Graph
-- tarda ocho segundos o falla, la persona vería un error DESPUÉS de haber
-- escrito su relato. Inaceptable. Se encola y un cron despacha.
--
-- ⚠ `payload` NO puede contener datos del caso (RN-09). La validación es dura
--   y ocurre en la capa de dominio: `encolar_notificacion()` rechaza cualquier
--   clave fuera de la lista blanca.
CREATE TABLE IF NOT EXISTS `notificaciones_salientes` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `tipo`            ENUM('correo','teams_evento','teams_cancelacion','sms') NOT NULL,
  `destinatario`    VARCHAR(255) NOT NULL,
  `plantilla`       VARCHAR(60) NOT NULL COMMENT 'Identificador de plantilla fija',
  `payload`         TEXT NOT NULL COMMENT 'JSON. Solo claves de la lista blanca (RN-09)',
  `payload_cifrado` VARBINARY(2048) NULL
                    COMMENT 'Para secretos de un solo uso (código OTP). Se borra tras el envío',
  `denuncia_id`     INT NULL,
  `estado`          ENUM('pendiente','enviado','fallido','descartado') NOT NULL DEFAULT 'pendiente',
  `intentos`        TINYINT NOT NULL DEFAULT 0,
  `proximo_intento` DATETIME NOT NULL,
  `ultimo_error`    VARCHAR(500) NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `enviado_at`      DATETIME NULL,
  INDEX `idx_pendientes` (`estado`, `proximo_intento`),
  INDEX `idx_denuncia` (`denuncia_id`),
  CONSTRAINT `fk_notif_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. AGENDA DE ENCUENTROS
-- ─────────────────────────────────────────────────────────────────────────────
-- Protocolo etapas E2 y E3 · Ac. 002 art. 30 (revisión trimestral) ·
-- Ac. 003 art. 53 (audiencias por medios tecnológicos).
CREATE TABLE IF NOT EXISTS `encuentros` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `denuncia_id`       INT NOT NULL,
  `etapa`             ENUM('encuentro_inicial','atencion_psicologica','atencion_juridica',
                           'trabajo_social','salud','revision_trimestral','audiencia') NOT NULL,
  `modalidad`         ENUM('presencial','virtual','telefonico') NOT NULL,
  `inicia_at`         DATETIME NOT NULL,
  `termina_at`        DATETIME NOT NULL,
  `profesional_id`    INT NOT NULL,
  `evento_graph_id`   VARCHAR(255) NULL,
  `ical_uid`          VARCHAR(255) NULL,
  `join_url_cifrado`  VARBINARY(1024) NULL COMMENT 'Dato sensible: acceso al espacio de atención',
  `grabacion_permitida` TINYINT(1) NOT NULL DEFAULT 0,
  `consentimiento_grabacion_id` INT NULL COMMENT 'Fase 5: referencia a consentimientos',
  `estado_sync`       ENUM('pendiente','sincronizado','error','cancelado') NOT NULL DEFAULT 'pendiente',
  `asistencia_json`   TEXT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_denuncia` (`denuncia_id`, `inicia_at`),
  INDEX `idx_sync` (`estado_sync`),
  CONSTRAINT `fk_encuentros_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ⚠ RN-04 · Ac. 002 art. 47.5 «no confrontación obligatoria»
--   Un mismo encuentro NO puede reunir a la persona afectada y a la persona
--   denunciada. La base de datos no puede expresar esa restricción por sí
--   sola, así que se verifica en la capa de dominio, en `crear_encuentro()`,
--   con validación dura y sin opción de forzar.
CREATE TABLE IF NOT EXISTS `encuentro_participantes` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `encuentro_id` INT NOT NULL,
  `persona_id`   INT NULL COMMENT 'personas_caso',
  `usuario_id`   INT NULL COMMENT 'usuarios (equipo)',
  `papel`        ENUM('afectada','denunciada','profesional','apoyo','defensor') NOT NULL,
  INDEX `idx_encuentro` (`encuentro_id`, `papel`),
  CONSTRAINT `fk_participantes_encuentro` FOREIGN KEY (`encuentro_id`)
      REFERENCES `encuentros` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. RESPONSABLES POR UNIDAD  ·  enrutamiento del destinatario
-- ─────────────────────────────────────────────────────────────────────────────
-- El destinatario de una notificación NO es un correo fijo en config.php: un
-- caso de la Facultad de Artes ASAB debe llegar a quien corresponde en ASAB,
-- no a un buzón único que se vuelve cuello de botella.
--
-- ⚠ Tabla creada VACÍA. Su contenido es la «matriz definitiva de responsables»
--   del §13.1 punto 3 de CLAUDE.md: la define Bienestar Universitario.
CREATE TABLE IF NOT EXISTS `responsables_notificacion` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `unidad_id`        INT NULL COMMENT 'NULL = responsable por defecto',
  `via`              ENUM('disciplinaria_estudiantil','disciplinaria_docente',
                          'disciplinaria_administrativa','administrativa_especial',
                          'contractual','traslado_externo','sin_competencia') NULL,
  `rol_destino`      VARCHAR(60) NOT NULL COMMENT 'coordinacion_edheg, unidad_academica…',
  `correo`           VARCHAR(255) NOT NULL,
  `activo`           TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ruta` (`unidad_id`, `via`, `activo`),
  CONSTRAINT `fk_responsables_unidad` FOREIGN KEY (`unidad_id`) REFERENCES `unidades` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Verificación
-- =============================================================================
--   SHOW TABLES LIKE 'notificaciones_salientes';
--   SHOW TABLES LIKE 'encuentros';
--   SELECT estado, COUNT(*) FROM notificaciones_salientes GROUP BY estado;
--
--   -- Ninguna notificación debe contener datos del caso:
--   SELECT id FROM notificaciones_salientes
--    WHERE payload REGEXP 'descripcion|relato|violencia|denunciante_nombre';
--   (debe devolver 0 filas)
