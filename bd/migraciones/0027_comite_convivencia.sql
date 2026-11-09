-- =============================================================================
-- Migración 0027 · Comité de Convivencia Laboral como autoridad interna
-- =============================================================================
-- v3.0 §3.3: «Comité de Convivencia Laboral — Casos administrativos y
-- contratistas». Mismo patrón de la 0026: dependencia del catálogo (recibe
-- compromisos en su bandeja, §16 E5, sin acceso al expediente RN-08) +
-- destino de remisión interno.
-- Aprobación G-01/G-13: solicitud de Elkin del 2 de septiembre de 2026.
-- =============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO unidades (tipo, padre_id, codigo, nombre, activa) VALUES
('dependencia', NULL, 'COMITE_CONVIVENCIA', 'Comité de Convivencia Laboral', 1);

INSERT INTO destinos_remision (codigo, nombre, ambito, fundamento, activo, interno) VALUES
('comite_convivencia', 'Comité de Convivencia Laboral',
 'disciplinario', 'v3.0 §3.3 · casos administrativos y contratistas', 1, 1);
