-- =============================================================================
-- Migración 0017 · Recuperación de contraseña
-- =============================================================================
-- Hallazgo:   H-09 — las funciones existían desde el inicio, la tabla nunca
-- Fundamento: CLAUDE.md §11 (contraseñas y sesiones)
--
-- `includes/auth.php` trae desde la primera versión `generate_password_reset_token()`,
-- `validate_password_reset_token()` y `reset_password()`, escritas correctamente:
-- guardan el HASH del token, no el token; lo invalidan al usarlo; expiran en una
-- hora. Lo único que faltaba era la tabla sobre la que operan.
--
-- Consecuencia hasta hoy: quien olvidaba su contraseña quedaba bloqueado y
-- dependía de que alguien editara la base de datos a mano.
--
-- Sintaxis portable: MySQL 8.0+ y MariaDB 10.6+.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `email`      VARCHAR(255) NOT NULL,
  `token`      CHAR(64) NOT NULL COMMENT 'SHA-256 del token. El token en claro nunca se almacena',
  `intentos`   TINYINT NOT NULL DEFAULT 0,
  `ip`         VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL COMMENT 'Creación + 1 hora',
  `usado_at`   DATETIME NULL,
  INDEX `idx_token` (`token`),
  INDEX `idx_email` (`email`, `expires_at`),
  INDEX `idx_limpieza` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Verificación
-- =============================================================================
--   SHOW COLUMNS FROM password_resets;
--
--   -- Ningún token debe estar en claro (todos son SHA-256 de 64 hex):
--   SELECT COUNT(*) FROM password_resets WHERE CHAR_LENGTH(token) <> 64;  -- 0
