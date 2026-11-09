-- =============================================================================
-- Migración 0016 · Prevención, patrones y portal
-- =============================================================================
-- Fase:       7 · Prevención y portal
-- Fundamento: Ac. 002 arts. 19, 33, 39 · Ac. 003 art. 5
--
-- ADR-09 · Dos canales separados, no uno:
--
--   `SISTEMA_REPLANTEADO.md` documenta que se eliminaron las denuncias
--   anónimas. La razón declarada —sin contacto no hay acompañamiento— es
--   sensata para el TRÁMITE DISCIPLINARIO. Pero los Acuerdos de 2026 obligan
--   a la Universidad a responder institucionalmente a quejas anónimas y
--   escraches (Ac. 002 art. 33; Ac. 003 art. 5).
--
--   La conciliación: un canal formal que abre trámite y exige contacto, y un
--   canal preventivo anónimo que NO abre trámite disciplinario ni genera
--   radicado consultable, pero sí activa medidas preventivas y alimenta el
--   análisis de patrones de riesgo colectivo del art. 33.4.
--
-- ⚠ El canal anónimo se entrega DESACTIVADO (`CANAL_ANONIMO_ACTIVO = false`).
--   Su apertura es la decisión 4 del §13.1 de CLAUDE.md y corresponde al
--   Comité para la Equidad de Géneros y Diversidades Sexuales.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. REPORTES ANÓNIMOS Y ESCRACHE
-- ─────────────────────────────────────────────────────────────────────────────
-- Ac. 003 art. 5: el escrache «no es censurable, no es prueba plena, SÍ es
-- información inicial que activa actuación institucional».
CREATE TABLE IF NOT EXISTS `reportes_anonimos` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `tipo`             ENUM('anonimo','escrache','denuncia_publica') NOT NULL DEFAULT 'anonimo',
  `descripcion`      TEXT NOT NULL,
  `unidad_id`        INT NULL,
  `espacio_senalado` VARCHAR(255) NULL COMMENT 'Lugar o contexto señalado, sin identificar personas',
  `fuente`           VARCHAR(255) NULL COMMENT 'Origen del escrache, si es público',
  `estado`           ENUM('recibido','en_analisis','medidas_activadas','archivado') NOT NULL DEFAULT 'recibido',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_estado` (`estado`, `created_at`),
  INDEX `idx_unidad` (`unidad_id`, `created_at`),
  CONSTRAINT `fk_anonimos_unidad` FOREIGN KEY (`unidad_id`) REFERENCES `unidades` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ⚠ NO genera radicado consultable · NO abre trámite disciplinario.
--   Activa medidas preventivas (art. 33.1), comunicación institucional (33.2),
--   sistematización (33.3) y análisis de patrones (33.4).

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. PATRONES DE RIESGO COLECTIVO  ·  Ac. 002 art. 33.4
-- ─────────────────────────────────────────────────────────────────────────────
-- «identificar patrones y posibles situaciones de riesgo colectivo»
--
-- ⚠ PRESUNCIÓN DE INOCENCIA (Ac. 003 art. 7). Un patrón señala ESPACIOS y
--   CONTEXTOS, nunca personas. La tabla no tiene ninguna columna que apunte a
--   una persona denunciada, y es deliberado: un sistema que acumula señales
--   contra un individuo sin proceso es una lista negra, no una herramienta de
--   prevención.
CREATE TABLE IF NOT EXISTS `patrones_detectados` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `tipo_patron`      ENUM('espacio_recurrente','unidad_recurrente','franja_horaria') NOT NULL,
  `clave`            VARCHAR(255) NOT NULL COMMENT 'Espacio o unidad señalada',
  `unidad_id`        INT NULL,
  `ocurrencias`      SMALLINT NOT NULL,
  `ventana_desde`    DATE NOT NULL,
  `ventana_hasta`    DATE NOT NULL,
  `estado`           ENUM('detectado','en_revision','medidas_activadas','descartado') NOT NULL DEFAULT 'detectado',
  `nota_revision`    TEXT NULL,
  `revisado_por`     INT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_estado` (`estado`, `created_at`),
  INDEX `idx_clave` (`tipo_patron`, `clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. GUARDADO PARCIAL DEL RELATO  ·  CLAUDE.md §10.1
-- ─────────────────────────────────────────────────────────────────────────────
-- «un relato abandonado a la mitad es una denuncia que no existió»
--
-- Los formularios largos se abandonan: la persona se interrumpe, alguien entra
-- en la habitación, se le acaba la batería. El borrador se cifra en reposo y
-- se recupera con un token que solo ella tiene.
CREATE TABLE IF NOT EXISTS `borradores_denuncia` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `token_hash`      CHAR(64) NOT NULL COMMENT 'SHA-256 del token de retorno',
  `contenido_cifrado` VARBINARY(16384) NOT NULL COMMENT 'AES-256-GCM',
  `expira_at`       DATETIME NOT NULL COMMENT 'Creación + 24 h',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_token` (`token_hash`),
  INDEX `idx_expira` (`expira_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. PORTAL · caja de herramientas y memorial  ·  Ac. 002 arts. 19 y 39
-- ─────────────────────────────────────────────────────────────────────────────
-- Se entregan VACÍAS: el contenido pedagógico y el memorial son producción
-- institucional, no técnica.
CREATE TABLE IF NOT EXISTS `recursos_portal` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `seccion`     ENUM('caja_herramientas','memorial','normativa','ruta_atencion') NOT NULL,
  `titulo`      VARCHAR(255) NOT NULL,
  `descripcion` TEXT NULL,
  `url`         VARCHAR(500) NULL,
  `archivo`     VARCHAR(500) NULL,
  `orden`       SMALLINT NOT NULL DEFAULT 0,
  `publicado`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_seccion` (`seccion`, `publicado`, `orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Verificación
-- =============================================================================
--   SELECT tipo, estado, COUNT(*) FROM reportes_anonimos GROUP BY tipo, estado;
--   SELECT * FROM patrones_detectados WHERE estado = 'detectado';
--
--   -- Ningún patrón debe apuntar a una persona:
--   SHOW COLUMNS FROM patrones_detectados LIKE '%persona%';   -- 0 filas
