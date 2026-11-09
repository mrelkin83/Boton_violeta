-- =============================================================================
-- Migración 0018 · Doble correo (RN-15 · Ac. 003 art. 43 · Ley 1581)
-- =============================================================================
-- CLAUDE.md v3.1 §6 · Fase C
-- Aprobación G-01: orden de Elkin del 2 de septiembre de 2026 (ejecución
-- autónoma de las fases A–G sobre el SQL especificado en el documento).
--
-- Desviaciones respecto del SQL del documento, obligadas por el esquema real:
--   1. `personas_caso.canal_preferido` hoy es ENUM('correo','sms','ninguno').
--      El MODIFY directo al ENUM final perdería los valores 'correo'. Se
--      transita en tres pasos: ampliar → migrar datos ('correo'→'personal',
--      el correo capturado hasta hoy es el que la persona eligió dar) →
--      reducir al ENUM final.
--   2. `denuncias.canal_preferido` (ENUM('correo','sms')) no figura en el
--      documento pero lo usa el OTP de seguimiento público; se armoniza con
--      los mismos valores para que «solo al canal preferido» siga siendo
--      calculable.
-- =============================================================================

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. USUARIOS · correo personal obligatorio (RN-15)
--    `email` existente = correo institucional (validación de dominio en PHP).
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE usuarios
  ADD COLUMN correo_personal_cifrado VARBINARY(512) NULL AFTER email,
  ADD COLUMN correo_personal_indice  CHAR(64) NULL AFTER correo_personal_cifrado,
  ADD INDEX idx_correo_personal (correo_personal_indice);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. PERSONAS DEL CASO · dos correos y canal elegido por la persona
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE personas_caso
  CHANGE COLUMN correo_cifrado correo_personal_cifrado VARBINARY(512) NULL,
  CHANGE COLUMN correo_indice  correo_personal_indice  CHAR(64) NULL,
  ADD COLUMN correo_institucional_cifrado VARBINARY(512) NULL AFTER correo_personal_indice,
  ADD COLUMN correo_institucional_indice  CHAR(64) NULL AFTER correo_institucional_cifrado,
  MODIFY COLUMN canal_preferido ENUM('correo','institucional','personal','ambos','sms','ninguno') NULL,
  ADD INDEX idx_correo_inst (correo_institucional_indice);

UPDATE personas_caso SET canal_preferido = 'personal' WHERE canal_preferido = 'correo';

ALTER TABLE personas_caso
  MODIFY COLUMN canal_preferido ENUM('institucional','personal','ambos','sms','ninguno') NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. DENUNCIAS · armonizar el canal del código de seguimiento
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE denuncias
  MODIFY COLUMN canal_preferido ENUM('correo','institucional','personal','ambos','sms','ninguno')
    NOT NULL DEFAULT 'personal';

UPDATE denuncias SET canal_preferido = 'personal' WHERE canal_preferido = 'correo';

ALTER TABLE denuncias
  MODIFY COLUMN canal_preferido ENUM('institucional','personal','ambos','sms','ninguno')
    NOT NULL DEFAULT 'personal';

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. OUTBOX · una fila por dirección, agrupadas por envío (RN-15)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE notificaciones_salientes
  ADD COLUMN destinatario_tipo ENUM('institucional','personal','sms') NOT NULL DEFAULT 'institucional',
  ADD COLUMN grupo_envio CHAR(36) NULL COMMENT 'UUID que agrupa los despachos de una misma notificación',
  ADD INDEX idx_grupo (grupo_envio);
