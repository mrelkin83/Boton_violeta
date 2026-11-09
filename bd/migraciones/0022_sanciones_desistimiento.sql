-- =============================================================================
-- Migración 0022 · Sanciones y desistimiento (Ac. 003 arts. 24, 41)
-- =============================================================================
-- CLAUDE.md v3.1 §6 · Fase B
-- Aprobación G-01: orden de Elkin del 2 de septiembre de 2026.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE sanciones (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  denuncia_id     INT NOT NULL,
  autoridad       VARCHAR(255) NOT NULL,
  tipo            VARCHAR(120) NOT NULL COMMENT 'del régimen aplicable; catálogo en configuracion',
  acto_numero     VARCHAR(80) NULL,
  acto_fecha      DATE NOT NULL,
  en_firme        TINYINT(1) DEFAULT 0,
  en_firme_at     DATE NULL,
  registrada_por  INT NOT NULL,
  documento_ruta  VARCHAR(500) NULL,
  documento_hash  CHAR(64) NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (denuncia_id) REFERENCES denuncias(id) ON DELETE CASCADE,
  INDEX idx_denuncia_firme (denuncia_id, en_firme)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Solo rol `decisor` o `coordinacion_edheg` registra. Alimenta reportes art.31 y art.40.

-- ─────────────────────────────────────────────────────────────────────────────
-- DESISTIMIENTO · continuación de oficio (Ac. 003 art. 24)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE consentimientos
  ADD COLUMN continuacion_oficio      TINYINT(1) NULL COMMENT 'solo tipo=desistimiento',
  ADD COLUMN motivacion_continuacion  TEXT NULL,
  ADD COLUMN decidida_por             INT NULL;
