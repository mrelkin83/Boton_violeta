-- =============================================================================
-- Migración 0028 · Talento Humano como dependencia del catálogo
-- =============================================================================
-- v3.0 §3.3: «Talento Humano — Medidas laborales». A diferencia de OAAD/OCID
-- (0026) y el Comité de Convivencia (0027), NO se registra como destino de
-- remisión: su papel en el modelo es de UNIDAD EJECUTORA de medidas (recibe
-- compromisos de origen `medida` en su bandeja, RN-08/ADR-05).
-- Aprobación G-01: solicitud de Elkin del 2 de septiembre de 2026.
-- =============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO unidades (tipo, padre_id, codigo, nombre, activa) VALUES
('dependencia', NULL, 'TALENTO_HUMANO', 'Talento Humano', 1);
