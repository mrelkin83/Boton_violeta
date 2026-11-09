-- =============================================================================
-- Migración 0019 · Unidad de hechos y de atención · turnos (RN-17 · RN-18)
-- =============================================================================
-- CLAUDE.md v3.1 §6 · Fases A y D
-- Aprobación G-01: orden de Elkin del 2 de septiembre de 2026.
--
-- Desviación respecto del SQL del documento: `denuncias.unidad_hechos_id` YA
-- existe desde la migración 0011 (con su FK). Aquí solo se añade lo que falta:
-- `unidad_atencion_id` y `espacio_hechos`.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE denuncias
  ADD COLUMN unidad_atencion_id INT NULL COMMENT 'sede cuyo equipo EDHEG acompaña (RN-17)' AFTER unidad_hechos_id,
  ADD COLUMN espacio_hechos VARCHAR(255) NULL COMMENT 'edificio, salón, entorno; texto libre no identificante' AFTER unidad_atencion_id,
  ADD CONSTRAINT fk_denuncias_unidad_atencion
      FOREIGN KEY (unidad_atencion_id) REFERENCES unidades (id);

-- ─────────────────────────────────────────────────────────────────────────────
-- TURNOS DE ATENCIÓN · qué profesional EDHEG atiende en cada sede (RN-18)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE turnos_atencion (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  unidad_id     INT NOT NULL COMMENT 'sede',
  usuario_id    INT NOT NULL COMMENT 'profesional EDHEG',
  dia_semana    TINYINT NOT NULL COMMENT '1=lunes … 7=domingo',
  hora_inicio   TIME NOT NULL,
  hora_fin      TIME NOT NULL,
  activo        TINYINT(1) DEFAULT 1,
  FOREIGN KEY (unidad_id)  REFERENCES unidades(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  INDEX idx_unidad_dia (unidad_id, dia_semana, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
