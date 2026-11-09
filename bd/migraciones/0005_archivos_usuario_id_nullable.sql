-- =============================================================================
-- Migración 0005 · archivos.usuario_id admite NULL
-- =============================================================================
-- Estado:      PENDIENTE DE APROBACIÓN — compuerta G-01 (CLAUDE.md §13)
-- Fase:        1 · Reparación
-- Hallazgo:    H-03 — la evidencia adjunta nunca se registra en base de datos
-- Fundamento:  Ac. 003 art. 45 (integridad y trazabilidad del expediente)
--
-- Problema
-- --------
-- `archivos.usuario_id` es NOT NULL con clave foránea hacia `usuarios`. El canal
-- público no tiene sesión, por lo que no existe un usuario al cual atribuir la
-- carga. El INSERT falla siempre: el archivo queda en disco y huérfano en la
-- base de datos. La evidencia aportada por la persona denunciante es hoy
-- invisible para el equipo.
--
-- Decisión que requiere Elkin
-- ---------------------------
-- Opción A (implementada aquí): permitir NULL. `usuario_id IS NULL` significa
--           "cargado por el canal público, sin sesión". Es explícito y no crea
--           una cuenta ficticia con capacidad de autenticarse.
-- Opción B: crear un usuario de sistema (`sistema@botonvioleta`, activo = 0) y
--           atribuirle las cargas públicas. Mantiene el NOT NULL a costa de una
--           fila en `usuarios` que no representa a una persona.
--
-- Reversión
-- ---------
--   UPDATE archivos SET usuario_id = <id_sistema> WHERE usuario_id IS NULL;
--   ALTER TABLE archivos MODIFY usuario_id INT(11) NOT NULL;
-- =============================================================================

ALTER TABLE `archivos`
    MODIFY `usuario_id` INT(11) NULL
    COMMENT 'NULL = carga por canal público sin sesión autenticada';

-- Verificación posterior a la migración:
--   SHOW COLUMNS FROM archivos LIKE 'usuario_id';   -- Null debe ser YES
