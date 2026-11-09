-- =============================================================================
-- Migración 0020 · Remisiones externas (RN-14 · Ac. 003 arts. 3 n.13, 4, 79)
-- =============================================================================
-- CLAUDE.md v3.1 §6 y §7.2 · Fase B
-- Aprobación G-01: orden de Elkin del 2 de septiembre de 2026.
--
-- Adiciones respecto del SQL del documento, exigidas por su propio §7.2:
--   · `anexos_json` (cada anexo elegido queda sellado y auditado)
--   · `relato_incluido` (el relato viaja solo por decisión motivada — RN-03)
--   · `informado_facultad_fiscalia` (Ley 2365 art. 11 n.4: registro de que se
--     informó a la víctima su facultad de acudir a la Fiscalía)
-- =============================================================================

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- CATÁLOGO DE DESTINOS · solo con fundamento verificado (G-13 para añadir)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE destinos_remision (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  codigo      VARCHAR(40) NOT NULL UNIQUE,
  nombre      VARCHAR(255) NOT NULL,
  ambito      ENUM('disciplinario','penal','proteccion','contractual','otro') NOT NULL,
  fundamento  VARCHAR(160) NOT NULL,
  activo      TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO destinos_remision (codigo, nombre, ambito, fundamento, activo) VALUES
('procuraduria',        'Procuraduría General de la Nación',        'disciplinario', 'Ac.003 art.3 n.13, art.79', 1),
('personeria',          'Personería de Bogotá',                     'disciplinario', 'Ac.003 art.79',             1),
('fiscalia',            'Fiscalía General de la Nación',            'penal',         'Ac.003 art.4 (traslado) · Ley 2365 art.11 n.4', 1),
('entidad_contratista', 'Entidad contratante (personal tercerizado)','contractual',  'Ac.003 art.10',             1),
('institucion_origen',  'Institución de origen o destino (movilidad, práctica, pasantía)', 'otro', 'Ac.003 arts.20-22', 1),
-- Sin fundamento en los Acuerdos: INACTIVOS hasta aprobación de Jurídica
-- (v3.1 §12.4 punto 1). RN-20 habilita `icbf` caso a caso si hay menor de edad.
('comisaria_familia',   'Comisaría de Familia',                     'proteccion',    'Pendiente de fundamento — v3.1 §12.4', 0),
('icbf',                'Instituto Colombiano de Bienestar Familiar','proteccion',   'Pendiente de fundamento — v3.1 §12.4 · se habilita por caso si hay menor (RN-20)', 0),
('secretaria_mujer',    'Secretaría Distrital de la Mujer',         'proteccion',    'Pendiente de fundamento — v3.1 §12.4', 0);

-- ─────────────────────────────────────────────────────────────────────────────
-- REMISIONES
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE remisiones_externas (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  denuncia_id         INT NOT NULL,
  destino_id          INT NOT NULL,
  tipo                ENUM('traslado','informacion','coordinacion') NOT NULL,
  motivacion          TEXT NOT NULL,
  decidida_por        INT NOT NULL,
  fecha_decision      DATETIME NOT NULL,
  fecha_envio         DATETIME NULL,
  medio_envio         ENUM('oficio_fisico','correo_institucional','plataforma_destino') NULL,
  radicado_destino    VARCHAR(120) NULL,
  acuse_ruta          VARCHAR(500) NULL,
  acuse_hash          CHAR(64) NULL,
  estado              ENUM('decidida','enviada','acusada','asumida_por_destino','devuelta','cerrada')
                        NOT NULL DEFAULT 'decidida',
  relato_incluido     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'solo por decisión motivada y auditada (RN-03, §7.2)',
  motivacion_relato   TEXT NULL COMMENT 'obligatoria si relato_incluido = 1',
  anexos_json         TEXT NULL COMMENT 'anexos elegidos, cada uno con su hash (§7.2)',
  informado_facultad_fiscalia TINYINT(1) NULL COMMENT 'Ley 2365 art.11 n.4 · solo destino fiscalia',
  observaciones       TEXT NULL,
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (denuncia_id) REFERENCES denuncias(id) ON DELETE CASCADE,
  FOREIGN KEY (destino_id)  REFERENCES destinos_remision(id),
  INDEX idx_denuncia (denuncia_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- DECISIÓN RN-14 · ¿se informa a la Procuraduría?
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE competencias
  ADD COLUMN informar_procuraduria   TINYINT(1) NULL COMMENT 'NULL = decisión pendiente (RN-14)',
  ADD COLUMN motivacion_procuraduria TEXT NULL,
  ADD COLUMN decidida_por            INT NULL,
  ADD COLUMN decidida_at             DATETIME NULL;
