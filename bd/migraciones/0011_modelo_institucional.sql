-- =============================================================================
-- Migración 0011 · Modelo institucional
-- =============================================================================
-- Fase:       3 · Modelo institucional
-- Reglas:     RN-01, RN-02, RN-07, RN-08, RN-12
-- Hallazgo:   H-18 (el rol `consulta` veía todos los casos) — queda sustituido
--             por la reserva por etapa procesal
-- Fundamento: Ac. 002 art. 25 lit. c · Ac. 003 arts. 23, 44, 47 ·
--             Ac. 027/1993 art. 86 · Ac. 011/2002 · Ley 1437 art. 11
--
-- Sintaxis portable: probada en MySQL 8.4 y compatible con MariaDB 10.6+.
-- No usa `IF NOT EXISTS` en ADD COLUMN ni `DROP FOREIGN KEY IF EXISTS`,
-- que son extensiones exclusivas de MariaDB (ver hallazgo del informe Fase 2).
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. CATÁLOGO DE UNIDADES  ·  sede → facultad → proyecto curricular
-- ─────────────────────────────────────────────────────────────────────────────
-- ADR-04: «dónde ocurrió» ≠ «quién investiga». La competencia sigue al
-- proyecto curricular de la persona denunciada, no al lugar de los hechos.
-- Por eso la unidad deja de ser texto libre y pasa a catálogo jerárquico.
--
-- ⚠ Esta migración NO siembra unidades. La estructura académica real de la
--   Universidad es un dato institucional: inventarla sería fabricar normativa.
--   Ver bd/migraciones/PLANTILLA_unidades.sql.
CREATE TABLE IF NOT EXISTS `unidades` (
  `id`       INT AUTO_INCREMENT PRIMARY KEY,
  `tipo`     ENUM('sede','facultad','proyecto_curricular','dependencia') NOT NULL,
  `padre_id` INT NULL,
  `codigo`   VARCHAR(30) NOT NULL,
  `nombre`   VARCHAR(255) NOT NULL,
  `activa`   TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_codigo` (`codigo`),
  INDEX `idx_tipo_padre` (`tipo`, `padre_id`),
  CONSTRAINT `fk_unidades_padre` FOREIGN KEY (`padre_id`) REFERENCES `unidades` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. PERSONAS DEL CASO
-- ─────────────────────────────────────────────────────────────────────────────
-- Separa los papeles que hoy están aplanados en columnas de `denuncias`.
-- Permite además distinguir a la persona afectada del quejoso que no lo es,
-- distinción sin la cual RN-07 (Ac. 003 art. 44) no puede aplicarse.
--
-- Terminología §2: nunca «agresor» ni «victimario». Es `denunciada`.
CREATE TABLE IF NOT EXISTS `personas_caso` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `denuncia_id`          INT NOT NULL,
  `papel`                ENUM('denunciante','afectada','denunciada','testigo') NOT NULL,
  `nombre_cifrado`       VARBINARY(1024) NULL,
  `correo_cifrado`       VARBINARY(512) NULL,
  `telefono_cifrado`     VARBINARY(256) NULL,
  `correo_indice`        CHAR(64) NULL,
  `telefono_indice`      CHAR(64) NULL,
  `canal_preferido`      ENUM('correo','sms','ninguno') NULL,
  `tipo_vinculacion`     ENUM('estudiante_pregrado','estudiante_posgrado','docente_carrera',
                              'docente_vinculacion_especial','administrativo','trabajador_oficial',
                              'contratista','tercerizado','externo','desconocido') NULL,
  `unidad_id`            INT NULL COMMENT 'Proyecto curricular o dependencia de la persona',
  `es_mismo_denunciante` TINYINT(1) NOT NULL DEFAULT 0
                         COMMENT '1 = quien denuncia es la persona afectada',
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_papel` (`denuncia_id`, `papel`),
  INDEX `idx_correo` (`correo_indice`),
  CONSTRAINT `fk_personas_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_personas_unidad` FOREIGN KEY (`unidad_id`) REFERENCES `unidades` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. COMPETENCIA DERIVADA
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `competencias` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `denuncia_id`          INT NOT NULL,
  `unidad_competente_id` INT NULL,
  `autoridad_instruye`   VARCHAR(255) NOT NULL,
  `autoridad_falla`      VARCHAR(255) NULL,
  `autoridad_segunda`    VARCHAR(255) NULL,
  `via`                  ENUM('disciplinaria_estudiantil','disciplinaria_docente',
                              'disciplinaria_administrativa','administrativa_especial',
                              'contractual','traslado_externo','sin_competencia') NOT NULL,
  `fundamento`           VARCHAR(255) NOT NULL COMMENT 'Artículo que sustenta la derivación',
  `derivada_automatica`  TINYINT(1) NOT NULL DEFAULT 1,
  `ajustada_por`         INT NULL,
  `motivacion_ajuste`    TEXT NULL COMMENT 'Obligatoria si derivada_automatica = 0',
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_denuncia` (`denuncia_id`),
  CONSTRAINT `fk_competencias_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_competencias_unidad` FOREIGN KEY (`unidad_competente_id`)
      REFERENCES `unidades` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. ASIGNACIONES  ·  soporte de RN-01
-- ─────────────────────────────────────────────────────────────────────────────
-- Ac. 003 art. 23 par.: «En ningún caso la misma autoridad podrá instruir y
-- fallar el proceso.» La restricción UNIQUE no basta para expresarlo —una
-- persona puede tener dos funciones distintas—, así que se verifica también
-- en la capa de dominio, que es donde CLAUDE.md §7 exige comprobarlo.
CREATE TABLE IF NOT EXISTS `asignaciones` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `denuncia_id` INT NOT NULL,
  `usuario_id`  INT NOT NULL,
  `funcion`     ENUM('instruccion','juzgamiento','coordinacion',
                     'psicologia','trabajo_social','juridica') NOT NULL,
  `activa`      TINYINT(1) NOT NULL DEFAULT 1,
  `asignada_por` INT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revocada_at` DATETIME NULL,
  UNIQUE KEY `uk_asignacion` (`denuncia_id`, `usuario_id`, `funcion`),
  INDEX `idx_usuario` (`usuario_id`, `activa`),
  CONSTRAINT `fk_asignaciones_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asignaciones_usuario` FOREIGN KEY (`usuario_id`)
      REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. IMPEDIMENTOS  ·  RN-12
-- ─────────────────────────────────────────────────────────────────────────────
-- Ac. 002 art. 48.3 + Ley 1437 art. 11. Declararse impedido bloquea el acceso
-- de forma irreversible para ese expediente: no existe operación que lo
-- deshaga desde la aplicación.
CREATE TABLE IF NOT EXISTS `impedimentos` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id`  INT NOT NULL,
  `denuncia_id` INT NOT NULL,
  `declarado`   TINYINT(1) NOT NULL COMMENT '1 = se declara impedido',
  `causal`      VARCHAR(255) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_usuario_denuncia` (`usuario_id`, `denuncia_id`),
  CONSTRAINT `fk_impedimentos_usuario` FOREIGN KEY (`usuario_id`)
      REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_impedimentos_denuncia` FOREIGN KEY (`denuncia_id`)
      REFERENCES `denuncias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. ETAPA PROCESAL Y UNIDAD DE LOS HECHOS EN `denuncias`
-- ─────────────────────────────────────────────────────────────────────────────
-- RN-02: la reserva rige HASTA la formulación de cargos o el archivo
-- definitivo (Ac. 003 art. 47). Sin esta columna, la reserva no es calculable.
ALTER TABLE `denuncias`
  ADD COLUMN `etapa_procesal` ENUM('recepcion','indagacion_previa','investigacion',
                                   'cargos_formulados','juzgamiento','archivo_definitivo','cerrada')
      NOT NULL DEFAULT 'recepcion' AFTER `estado`,
  ADD COLUMN `unidad_hechos_id` INT NULL
      COMMENT 'Dónde ocurrió. NO determina la competencia (ADR-04)' AFTER `salon_area`;

ALTER TABLE `denuncias`
  ADD INDEX `idx_etapa` (`etapa_procesal`),
  ADD CONSTRAINT `fk_denuncias_unidad_hechos`
      FOREIGN KEY (`unidad_hechos_id`) REFERENCES `unidades` (`id`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. ROLES  ·  §9.1
-- ─────────────────────────────────────────────────────────────────────────────
-- Se añaden `instructor`, `decisor`, `unidad_academica`, `talento_humano` y
-- `reportes`. El rol `consulta` desaparece: violaba RN-02 al dar visión
-- completa sin asignación ni etapa.
UPDATE `usuarios` SET `rol` = 'reportes' WHERE `rol` = 'consulta';

ALTER TABLE `usuarios`
  MODIFY COLUMN `rol` ENUM('administrador','admin','coordinador','coordinacion_edheg',
                           'psicologo','trabajador_social','abogado',
                           'instructor','decisor','unidad_academica',
                           'talento_humano','reportes')
      NOT NULL DEFAULT 'reportes';

-- Alcance territorial del rol `unidad_academica` (RN-08)
ALTER TABLE `usuarios`
  ADD COLUMN `unidad_id` INT NULL
      COMMENT 'Unidad a la que se restringe el rol unidad_academica' AFTER `rol`;

ALTER TABLE `usuarios`
  ADD CONSTRAINT `fk_usuarios_unidad` FOREIGN KEY (`unidad_id`) REFERENCES `unidades` (`id`);

-- =============================================================================
-- Verificación
-- =============================================================================
--   SHOW TABLES LIKE 'unidades';
--   SHOW TABLES LIKE 'personas_caso';
--   SHOW COLUMNS FROM denuncias LIKE 'etapa_procesal';
--   SHOW COLUMNS FROM usuarios LIKE 'rol';
--   SELECT rol, COUNT(*) FROM usuarios GROUP BY rol;
--
-- Siguiente paso: cargar el catálogo de unidades reales de la Universidad
-- (ver PLANTILLA_unidades.sql) y ejecutar 0012_backfill_personas_caso.php
