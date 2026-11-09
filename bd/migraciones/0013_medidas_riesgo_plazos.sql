-- =============================================================================
-- Migración 0013 · Medidas, consentimientos, riesgo y plazos
-- =============================================================================
-- Fase:       5 · Medidas y riesgo  ★ NÚCLEO DEL ACUERDO 002
-- Reglas:     RN-05 (prohibición de contacto) · RN-06 (consentimiento previo)
-- Fundamento: Ac. 002 arts. 22-30 · Ac. 003 arts. 17, 24 · Est. Est. art. 86
-- ADR:        ADR-03 (la medida es la entidad central del dominio)
--
-- ADR-03 explica por qué esta fase es la más grande: el Acuerdo 002 completo
-- regula MEDIDAS. La denuncia es su origen, no su objeto. Hasta aquí el
-- sistema modelaba `estado ∈ {nueva, en_proceso, resuelta, archivada}`, que no
-- expresa nada de lo que el Acuerdo obliga a registrar.
--
-- Sintaxis portable: MySQL 8.0+ y MariaDB 10.6+.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. CATÁLOGO DE MEDIDAS
-- ─────────────────────────────────────────────────────────────────────────────
-- El contenido se siembra en 0014, tomado LITERALMENTE de los Acuerdos.
CREATE TABLE IF NOT EXISTS `catalogo_medidas` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `codigo`      VARCHAR(40) NOT NULL,
  `categoria`   ENUM('accion_inmediata','proteccion','restaurativa',
                     'pedagogica','no_revictimizacion') NOT NULL,
  `nombre`      VARCHAR(255) NOT NULL,
  `descripcion` TEXT NOT NULL,
  `fundamento`  VARCHAR(120) NOT NULL COMMENT 'Artículo exacto. Sin esto la medida no es defendible',
  `aplica_a`    SET('estudiante','docente','administrativo','contratista','externo') NOT NULL,
  `dirigida_a`  ENUM('afectada','denunciada','ambas','institucional') NOT NULL DEFAULT 'afectada',
  `requiere_consentimiento` TINYINT(1) NOT NULL DEFAULT 1,
  `dias_habiles_ejecucion`  SMALLINT NOT NULL DEFAULT 5,
  `activo`      TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_codigo` (`codigo`),
  INDEX `idx_categoria` (`categoria`, `activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. CONSENTIMIENTOS  ·  RN-06
-- ─────────────────────────────────────────────────────────────────────────────
-- Ac. 002 arts. 22, 24, 25 lit. a, 26, 28 y 30: la voluntad de la víctima es
-- CONDICIÓN DE VALIDEZ de la medida. Sin registro de consentimiento, cualquier
-- medida adoptada es jurídicamente frágil y expone a la Universidad.
--
-- Incluye el desistimiento del Ac. 003 art. 24 y la revocación: una persona
-- puede aceptar una medida hoy y retirarla en tres semanas, y el sistema debe
-- permitirlo sin fricción.
CREATE TABLE IF NOT EXISTS `consentimientos` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `denuncia_id`     INT NOT NULL,
  `tipo`            ENUM('tratamiento_datos','medida_especifica','remision_externa',
                         'grabacion','contacto_familiar','desistimiento') NOT NULL,
  `referencia_id`   INT NULL COMMENT 'medida_id cuando aplique',
  `alcance_texto`   TEXT NOT NULL COMMENT 'Lo que se explicó, literal',
  `version_texto`   VARCHAR(20) NOT NULL,
  `otorgado`        TINYINT(1) NOT NULL,
  `medio`           ENUM('presencial','virtual','telefonico','escrito') NOT NULL,
  `registrado_por`  INT NOT NULL,
  `hash_registro`   CHAR(64) NOT NULL COMMENT 'Integridad del registro',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revocado_at`     DATETIME NULL,
  `motivo_revocacion` TEXT NULL,
  INDEX `idx_denuncia` (`denuncia_id`, `tipo`),
  INDEX `idx_vigentes` (`denuncia_id`, `otorgado`, `revocado_at`),
  CONSTRAINT `fk_consent_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. MEDIDAS  ·  máquina de estados de los 5 pasos del art. 25
-- ─────────────────────────────────────────────────────────────────────────────
--   solicitada ─(consentimiento RN-06)─► notificada ─► en_ejecucion ─► implementada
--        │                                                  │              │
--        └─► rechazada                                      └─► incumplida │
--                                                                          ▼
--                                          revisión trimestral (art. 30) ─► levantada
CREATE TABLE IF NOT EXISTS `medidas` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `denuncia_id`          INT NOT NULL,
  `catalogo_id`          INT NOT NULL,
  `solicitada_por`       ENUM('victima','equipo','oficio') NOT NULL,
  `motivacion_oficio`    TEXT NULL COMMENT 'OBLIGATORIA si solicitada_por = oficio',
  `consentimiento_id`    INT NULL,
  `unidad_ejecutora_id`  INT NULL,
  `responsable_id`       INT NULL,
  `estado`               ENUM('solicitada','notificada','en_ejecucion','implementada',
                              'incumplida','levantada','rechazada') NOT NULL DEFAULT 'solicitada',
  `fecha_solicitud`      DATETIME NOT NULL,
  `fecha_limite`         DATE NOT NULL COMMENT 'Calculada en días hábiles',
  `fecha_implementacion` DATETIME NULL,
  `constancia_texto`     TEXT NULL COMMENT 'Lo escribe la unidad ejecutora',
  `motivo_levantamiento` TEXT NULL COMMENT 'OBLIGATORIO para levantar (art. 30)',
  `motivo_rechazo`       TEXT NULL,
  `proxima_revision`     DATE NULL COMMENT '+3 meses (Ac. 002 art. 30)',
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME NULL,
  INDEX `idx_denuncia` (`denuncia_id`, `estado`),
  INDEX `idx_revision` (`proxima_revision`, `estado`),
  INDEX `idx_vencimiento` (`fecha_limite`, `estado`),
  INDEX `idx_unidad` (`unidad_ejecutora_id`, `estado`),
  CONSTRAINT `fk_medidas_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_medidas_catalogo` FOREIGN KEY (`catalogo_id`)
      REFERENCES `catalogo_medidas` (`id`),
  CONSTRAINT `fk_medidas_consent` FOREIGN KEY (`consentimiento_id`)
      REFERENCES `consentimientos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de transiciones: el art. 25 lit. d exige trazabilidad de CADA paso
CREATE TABLE IF NOT EXISTS `medida_transiciones` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `medida_id`     INT NOT NULL,
  `estado_desde`  VARCHAR(20) NULL,
  `estado_hasta`  VARCHAR(20) NOT NULL,
  `usuario_id`    INT NULL,
  `motivacion`    TEXT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_medida` (`medida_id`, `created_at`),
  CONSTRAINT `fk_transiciones_medida` FOREIGN KEY (`medida_id`)
      REFERENCES `medidas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. INSTRUMENTO DE RIESGO  ·  Ac. 002 art. 23
-- ─────────────────────────────────────────────────────────────────────────────
-- ⚠ Estas tablas se entregan VACÍAS.
--
--   «la Oficina de Bienestar Universitario diseñará un instrumento de
--    detección y evaluación que contenga factores que determinen riesgos
--    altos, medios y moderados»
--
-- Los factores, pesos y umbrales son una DEFINICIÓN DE POLÍTICA INSTITUCIONAL
-- con consecuencias directas sobre personas: determinan qué casos se priorizan.
-- Es la decisión 1 del §13.1 de CLAUDE.md. Inventarlos sería inventar el
-- criterio con que la Universidad decide a quién atiende primero.
--
-- El instrumento es VERSIONADO: cuando cambien los pesos, las valoraciones
-- históricas no se reinterpretan con la vara nueva.
CREATE TABLE IF NOT EXISTS `instrumentos_riesgo` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `version`      VARCHAR(20) NOT NULL,
  `vigente`      TINYINT(1) NOT NULL DEFAULT 0,
  `umbral_alto`  DECIMAL(6,3) NOT NULL COMMENT 'Puntaje >= umbral_alto → banda alta',
  `umbral_medio` DECIMAL(6,3) NOT NULL,
  `aprobado_por` VARCHAR(255) NOT NULL,
  `aprobado_at`  DATETIME NOT NULL,
  UNIQUE KEY `uk_version` (`version`),
  INDEX `idx_vigente` (`vigente`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `instrumento_items` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `instrumento_id` INT NOT NULL,
  `codigo`         VARCHAR(30) NOT NULL,
  `enunciado`      TEXT NOT NULL,
  `peso`           DECIMAL(6,3) NOT NULL COMMENT 'Configurable en BD, NUNCA en código',
  `orden`          SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY `uk_item` (`instrumento_id`, `codigo`),
  CONSTRAINT `fk_items_instrumento` FOREIGN KEY (`instrumento_id`)
      REFERENCES `instrumentos_riesgo` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `valoraciones_riesgo` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `denuncia_id`       INT NOT NULL,
  `instrumento_id`    INT NOT NULL,
  `respuestas_json`   TEXT NULL,
  `puntaje`           DECIMAL(6,3) NOT NULL,
  `banda_sugerida`    ENUM('alto','medio','moderado') NOT NULL,
  `banda_final`       ENUM('alto','medio','moderado') NOT NULL,
  `motivacion_ajuste` TEXT NULL COMMENT 'OBLIGATORIA si banda_final != banda_sugerida',
  `items_omitidos`    SMALLINT NOT NULL DEFAULT 0 COMMENT 'Puntaje renormalizado, no penalizado',
  `valorada_por`      INT NOT NULL,
  `es_revision`       TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_denuncia` (`denuncia_id`, `created_at`),
  CONSTRAINT `fk_valoraciones_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_valoraciones_instrumento` FOREIGN KEY (`instrumento_id`)
      REFERENCES `instrumentos_riesgo` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. PLAZOS  ·  días hábiles
-- ─────────────────────────────────────────────────────────────────────────────
-- La constante BUSINESS_DAYS_CALCULATION existía en config.php desde el inicio
-- y nunca se usó. Un plazo vencido sin alerta es, en el marco de un fallo de
-- tutela con seguimiento de la Corte, un incumplimiento documentado.
CREATE TABLE IF NOT EXISTS `festivos` (
  `fecha`       DATE NOT NULL PRIMARY KEY,
  `nombre`      VARCHAR(120) NOT NULL,
  `origen`      ENUM('ley_51_1983','institucional') NOT NULL DEFAULT 'ley_51_1983'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ⚠ Se entrega VACÍA: el calendario académico es un dato institucional que
--   cambia cada semestre. Ac. 002 art. 26 lit. c habla de cancelación
--   «sin importar el calendario académico», lo que exige conocerlo.
CREATE TABLE IF NOT EXISTS `calendario_academico` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `periodo`     VARCHAR(20) NOT NULL COMMENT 'Ej. 2026-1',
  `hito`        VARCHAR(120) NOT NULL,
  `fecha_desde` DATE NOT NULL,
  `fecha_hasta` DATE NULL,
  UNIQUE KEY `uk_hito` (`periodo`, `hito`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de plazos normativos. Contenido sembrado en 0014.
CREATE TABLE IF NOT EXISTS `catalogo_plazos` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `codigo`        VARCHAR(50) NOT NULL,
  `nombre`        VARCHAR(160) NOT NULL,
  `cantidad`      SMALLINT NOT NULL,
  `unidad`        ENUM('dias_habiles','dias_calendario','meses') NOT NULL,
  `fundamento`    VARCHAR(160) NOT NULL,
  `activo`        TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Verificación
-- =============================================================================
--   SELECT categoria, COUNT(*) FROM catalogo_medidas GROUP BY categoria;
--   SELECT COUNT(*) FROM instrumentos_riesgo WHERE vigente = 1;  -- 0 hasta que
--                                                                -- Bienestar lo defina
--   SELECT * FROM catalogo_plazos ORDER BY codigo;
