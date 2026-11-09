-- =============================================================================
-- Migración 0025 · Retaliaciones (Ac. 003 art. 18 · RN de prohibición)
-- =============================================================================
-- La v3.1 §3.1 afirma que esta tabla existía «desde la migración 0013»; es
-- incorrecto — ninguna migración la creó (solo existe la medida
-- A003_18_NO_RETALIACION del catálogo, sembrada en 0014). Se crea aquí con el
-- esquema de la v3.0 §8.3, más el verificador que el art. 18 exige («especial
-- rigor» en la verificación).
-- Aprobación G-01: orden de Elkin del 2 de septiembre de 2026.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE retaliaciones (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  denuncia_id     INT NOT NULL COMMENT 'radicado original',
  descripcion     TEXT NOT NULL,
  reportada_por   ENUM('victima','testigo','equipo') NOT NULL,
  estado          ENUM('reportada','en_verificacion','acreditada','no_acreditada') DEFAULT 'reportada',
  verificada_por  INT NULL,
  verificada_at   DATETIME NULL,
  observaciones_verificacion TEXT NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (denuncia_id) REFERENCES denuncias(id),
  INDEX idx_denuncia_estado (denuncia_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
