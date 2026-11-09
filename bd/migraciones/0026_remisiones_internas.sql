-- =============================================================================
-- Migración 0026 · Remisiones internas: OAAD, OCID y decanaturas
-- =============================================================================
-- v3.1 §7.2 (régimen interno a OAAD · Protocolo E4) · v3.0 §3.3 (actores)
-- Aprobación G-01/G-13: solicitud de Elkin del 2 de septiembre de 2026
-- («remisiones internas que se puedan enviar a la OCID y a las decanaturas»).
--
-- Diseño:
--   · La tabla `remisiones_externas` pasa a alojar TAMBIÉN el régimen interno
--     (mismo ciclo de estados y acuse); el destino se marca `interno = 1`.
--     No se renombra la tabla: el costo de migrar referencias supera el
--     beneficio semántico, y el catálogo deja claro el régimen.
--   · OAAD y OCID se registran como `unidades` de tipo dependencia: así el
--     compromiso del receptor cae en su bandeja (§16 E5) y una cuenta con esa
--     unidad puede responder sin acceder al expediente (RN-08).
--   · «Decanatura» es un destino genérico: exige señalar la facultad concreta
--     (`unidad_interna_id`), porque hay una decanatura por facultad.
-- =============================================================================

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Marca de régimen interno en el catálogo de destinos
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE destinos_remision
  ADD COLUMN interno TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = autoridad interna de la Universidad (régimen del Protocolo E4)';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Unidad interna receptora (solo destinos internos que la exigen)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE remisiones_externas
  ADD COLUMN unidad_interna_id INT NULL
    COMMENT 'facultad (decanatura) u otra unidad interna receptora',
  ADD CONSTRAINT fk_remision_unidad_interna
      FOREIGN KEY (unidad_interna_id) REFERENCES unidades (id);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. OAAD y OCID como dependencias del catálogo institucional
--    (v3.0 §3.3 · reciben compromisos en su bandeja)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO unidades (tipo, padre_id, codigo, nombre, activa) VALUES
('dependencia', NULL, 'OAAD', 'Oficina Asesora de Asuntos Disciplinarios', 1),
('dependencia', NULL, 'OCID', 'Oficina de Control Interno Disciplinario', 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Destinos internos (fundamentos de la v3.0 §3.3 y el Protocolo E4)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO destinos_remision (codigo, nombre, ambito, fundamento, activo, interno) VALUES
('oaad',       'Oficina Asesora de Asuntos Disciplinarios (OAAD)',
               'disciplinario', 'Protocolo VBG etapa E4 · direcciona en 5 días hábiles', 1, 1),
('ocid',       'Oficina de Control Interno Disciplinario (OCID)',
               'disciplinario', 'v3.0 §3.3 · Ley 1952/2019 · servidores públicos', 1, 1),
('decanatura', 'Decanatura de facultad (señalar la facultad)',
               'disciplinario', 'Protocolo E4 paso 4 · Ac. 027/1993 (vía estudiantil)', 1, 1);
