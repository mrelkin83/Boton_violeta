-- =============================================================================
-- Migración 0006 · Auditoría encadenada por hash
-- =============================================================================
-- Estado:      PENDIENTE DE APROBACIÓN — compuerta G-01 (CLAUDE.md §13)
-- Fase:        6 · Cumplimiento  ← NO ejecutar durante la Fase 1
-- Regla:       RN-13 (integridad de la auditoría)
-- Fundamento:  Ac. 003 art. 45 · seguimiento de la Corte Constitucional (T-210/2023)
--
-- Se deja preparada porque la Fase 1 ya restaura la escritura en `auditoria`
-- (hallazgo H-02) y conviene que el encadenamiento se active sobre una tabla
-- que ya recibe registros reales. La activación en código es de la Fase 6.
--
-- Semántica de la cadena
-- ----------------------
--   hash_actual = SHA256( hash_anterior || usuario_id || accion || tabla ||
--                         registro_id || datos || ip || created_at )
--   La primera fila usa hash_anterior = 64 ceros (registro génesis).
--   Cualquier alteración retroactiva rompe la cadena y es detectable.
--
-- Consecuencia operativa: la tabla pasa a ser SOLO DE INSERCIÓN.
-- Ningún código puede ejecutar UPDATE ni DELETE sobre `auditoria`
-- (CLAUDE.md prohibición 5).
-- =============================================================================

-- Nota: el valor por defecto se escribe como literal y no como `REPEAT('0',64)`.
-- MySQL no admite expresiones en DEFAULT para columnas CHAR salvo entre
-- paréntesis y desde 8.0.13; el literal funciona en MySQL y en MariaDB.
ALTER TABLE `auditoria`
    ADD COLUMN `hash_anterior` CHAR(64) NOT NULL
        DEFAULT '0000000000000000000000000000000000000000000000000000000000000000'
        COMMENT 'Hash del registro anterior. 64 ceros = registro génesis' AFTER `user_agent`,
    ADD COLUMN `hash_actual`   CHAR(64) NULL AFTER `hash_anterior`,
    MODIFY `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6);

-- Índice para verificación de la cadena
ALTER TABLE `auditoria`
    ADD INDEX `idx_cadena` (`id`, `hash_actual`);

-- Los registros previos a esta migración no forman parte de la cadena.
-- Se marcan explícitamente para no dar una garantía de integridad que no existe.
UPDATE `auditoria`
SET `hash_actual` = NULL
WHERE `hash_actual` IS NULL;
