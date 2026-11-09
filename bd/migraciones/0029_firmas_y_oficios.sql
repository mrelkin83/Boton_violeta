-- =============================================================================
-- Migración 0029 · Firmas digitalizadas y datos de ejecución de los oficios
-- =============================================================================
-- Solicitud de Elkin del 2/09/2026, con base en los formatos institucionales
-- reales DBI-811-2026 (cambio de grupo) y DBI-477-2026 (no ingreso a sede),
-- ambos firmados y con tabla «Proyectó · NOMBRE / CARGO / FIRMA».
-- Aprobación G-01 y G-02 (medida nueva con fundamento verificado).
--
--   · `usuarios.firma_ruta`: imagen escaneada de la firma, guardada con
--     nombre UUID bajo uploads/firmas/ (directorio negado por .htaccess).
--     Se incrusta en los oficios como data-URI: jamás se sirve por URL.
--   · `usuarios.cargo_oficio`: cargo con el que la persona firma o proyecta
--     (p. ej. «Jefe Oficina de Bienestar Universitario», «CPS Bienestar»).
--   · `medidas.detalle_ejecucion`: datos operativos que el oficio necesita
--     (asignatura, grupo y horario en un cambio de grupo; señas del espacio
--     en una restricción). Viaja en POST, nunca en la URL (RN-09).
--   · Medida de protección «Restricción de ingreso a sede» (formato DBI-477):
--     fundamento Res. 142/2026 (medidas de protección) y Ac. 002 arts. 24 y
--     34 (acción inmediata / alrededores de las sedes). Sin consentimiento:
--     protege a la comunidad y suele adoptarse de oficio motivado (RN-06).
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE usuarios
  ADD COLUMN firma_ruta VARCHAR(500) NULL COMMENT 'imagen de firma, nombre UUID bajo uploads/firmas/',
  ADD COLUMN cargo_oficio VARCHAR(150) NULL COMMENT 'cargo con el que firma o proyecta oficios';

ALTER TABLE medidas
  ADD COLUMN detalle_ejecucion TEXT NULL COMMENT 'datos operativos para el oficio (asignatura/grupo/horario, señas del espacio…)';

INSERT IGNORE INTO catalogo_medidas
  (codigo, categoria, nombre, descripcion, fundamento, aplica_a, requiere_consentimiento, dias_habiles_ejecucion, activo)
VALUES
  ('R142_RESTRICCION_INGRESO', 'proteccion', 'Restricción de ingreso a sede',
   'Bloqueo del ingreso de la persona señalada a la sede indicada; instrucción al personal de vigilancia para verificación estricta de carnés y socialización confidencial de los datos identificativos remitidos.',
   'Res. 142/2026 · Ac.002 arts.24 y 34',
   'estudiante,docente,administrativo,contratista,externo', 0, 1, 1);
