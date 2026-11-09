-- =============================================================================
-- Migración 0004 · Persona denunciada, testigos y ubicación de los hechos
-- =============================================================================
-- Completa el formulario público: con quién ocurrió, si hubo testigos y dónde.
--
-- Compatible con MySQL 8.0+ y MariaDB 10.6+.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- QUÉ SE CORRIGIÓ Y POR QUÉ
-- ─────────────────────────────────────────────────────────────────────────────
--
-- 1. `DROP FOREIGN KEY IF EXISTS`, `ADD COLUMN IF NOT EXISTS` y
--    `ADD INDEX IF NOT EXISTS` son extensiones EXCLUSIVAS de MariaDB.
--    Producción corre MariaDB, así que allí funcionaron; en MySQL 8 el archivo
--    abortaba en la primera línea y dejaba el esquema sin ocho columnas. El
--    repositorio declaraba compatibilidad con ambos motores (CLAUDE.md §5) y
--    no la tenía.
--
-- 2. Repetía trabajo de la migración 0003: volvía a añadir `es_anonima`,
--    `denunciante_nombre` y `denunciante_contacto`, a hacer nulable
--    `usuario_id`, a recrear la clave foránea y a crear `idx_es_anonima`.
--    Ese solapamiento era justamente la razón de usar `IF NOT EXISTS`: se
--    tapaba un problema de orden con una extensión no portable. Ahora esta
--    migración solo añade lo que le corresponde.
--
-- 3. `int(11)` y `text DEFAULT NULL`: sintaxis heredada.
--
-- ⚠ Terminología · los nombres `tipo_agresor` y `agresor_nombre` se conservan
--   porque ya existen en producción y renombrarlos exige migrar datos. La
--   interfaz NO usa esa palabra: dice «persona denunciada» (CLAUDE.md §2).
--   Desde la Fase 3 esta información vive además en `personas_caso`, con la
--   terminología correcta; el renombrado queda para cuando se consolide.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- Persona denunciada
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `denuncias`
  ADD COLUMN `tipo_agresor` ENUM('docente','administrativo','estudiante','externo','otro')
      NULL COMMENT 'Vínculo de la persona denunciada con la Universidad'
      AFTER `tipo_violencia`,
  ADD COLUMN `agresor_nombre` VARCHAR(255) NULL
      COMMENT 'Nombre o datos de la persona denunciada, si se conocen'
      AFTER `tipo_agresor`,
  ADD COLUMN `agresor_identificacion` VARCHAR(100) NULL
      AFTER `agresor_nombre`;

-- ─────────────────────────────────────────────────────────────────────────────
-- Testigos
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `denuncias`
  ADD COLUMN `testigos` TEXT NULL AFTER `descripcion`;

-- ─────────────────────────────────────────────────────────────────────────────
-- Ubicación de los hechos
-- ─────────────────────────────────────────────────────────────────────────────
-- ⚠ ADR-04 · Esto describe DÓNDE OCURRIÓ, no quién es competente para
--   investigar. La competencia sigue al proyecto curricular de la persona
--   denunciada, no al lugar de los hechos. La migración 0011 separa ambos
--   conceptos con `unidad_hechos_id` y la tabla `competencias`; estos campos
--   de texto libre quedan como registro descriptivo.
ALTER TABLE `denuncias`
  ADD COLUMN `facultad`   VARCHAR(255) NULL AFTER `lugar_hechos`,
  ADD COLUMN `sede`       VARCHAR(255) NULL AFTER `facultad`,
  ADD COLUMN `edificio`   VARCHAR(255) NULL AFTER `sede`,
  ADD COLUMN `salon_area` VARCHAR(255) NULL AFTER `edificio`;

-- ─────────────────────────────────────────────────────────────────────────────
-- Índices de filtrado
-- ─────────────────────────────────────────────────────────────────────────────
-- `idx_es_anonima` ya lo crea la migración 0003.
ALTER TABLE `denuncias`
  ADD INDEX `idx_tipo_agresor` (`tipo_agresor`),
  ADD INDEX `idx_facultad` (`facultad`),
  ADD INDEX `idx_sede` (`sede`);

-- =============================================================================
-- Verificación
-- =============================================================================
--   SELECT COUNT(*) AS columnas FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'denuncias'
--      AND column_name IN ('tipo_agresor','agresor_nombre','agresor_identificacion',
--                          'testigos','facultad','sede','edificio','salon_area');
--   -- Resultado esperado: 8
-- =============================================================================
