-- =============================================================================
-- Migración 0021 · Compromisos (Ac. 002 arts. 25, 30, 37b, 44)
-- =============================================================================
-- CLAUDE.md v3.1 §6 · Fase A · Entidad transversal: toda medida, remisión y
-- revisión de seguimiento produce compromisos con responsable y plazo.
-- Aprobación G-01: orden de Elkin del 2 de septiembre de 2026.
--
-- Adición respecto del SQL del documento, exigida por su propio §16 (etapa 5):
--   · `respuesta_texto` y `respondido_at`: las solicitudes trimestrales de
--     estado a las dependencias se responden sobre el compromiso mismo, sin
--     que la dependencia acceda al expediente.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE compromisos (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  denuncia_id             INT NOT NULL,
  origen                  ENUM('medida','restaurativo','remision','seguimiento') NOT NULL,
  medida_id               INT NULL,
  remision_id             INT NULL,
  descripcion             TEXT NOT NULL,
  responsable_tipo        ENUM('usuario','unidad','persona_caso','externo') NOT NULL,
  responsable_usuario_id  INT NULL,
  responsable_unidad_id   INT NULL,
  responsable_persona_id  INT NULL,
  responsable_externo     VARCHAR(255) NULL,
  fecha_limite            DATE NOT NULL,
  estado                  ENUM('pendiente','en_curso','cumplido','incumplido','prorrogado','cancelado')
                            NOT NULL DEFAULT 'pendiente',
  constancia_texto        TEXT NULL,
  constancia_ruta         VARCHAR(500) NULL,
  constancia_hash         CHAR(64) NULL,
  verificado_por          INT NULL,
  verificado_at           DATETIME NULL,
  respuesta_texto         TEXT NULL COMMENT 'origen=seguimiento: respuesta de la dependencia (§16 E5)',
  respondido_at           DATETIME NULL,
  -- solo origen='restaurativo' (RN-16):
  consentimiento_id       INT NULL,
  sancion_id              INT NULL,
  concepto_favorable_por  INT NULL,
  concepto_favorable_at   DATETIME NULL,
  created_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (denuncia_id) REFERENCES denuncias(id) ON DELETE CASCADE,
  FOREIGN KEY (medida_id)   REFERENCES medidas(id),
  INDEX idx_vencimiento (fecha_limite, estado),
  INDEX idx_responsable_usuario (responsable_usuario_id, estado),
  INDEX idx_responsable_unidad  (responsable_unidad_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compromiso_transiciones (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  compromiso_id  INT NOT NULL,
  desde          VARCHAR(20) NOT NULL,
  hasta          VARCHAR(20) NOT NULL,
  usuario_id     INT NOT NULL,
  motivacion     TEXT NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (compromiso_id) REFERENCES compromisos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
