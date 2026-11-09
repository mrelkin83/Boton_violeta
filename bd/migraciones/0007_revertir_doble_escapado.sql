-- =============================================================================
-- Migración 0007 · Revertir el doble escapado de los datos existentes
-- =============================================================================
-- Estado:      PENDIENTE DE APROBACIÓN — compuerta G-01 (CLAUDE.md §13)
-- Fase:        1 · Reparación
-- Hallazgo:    H-14 — clean_input() escapaba en entrada y e() volvía a escapar
--
-- Contexto
-- --------
-- El código ya no escapa en entrada (CLAUDE.md prohibición 11). Los registros
-- creados ANTES de ese cambio quedaron guardados con entidades HTML:
--   «Ana & José dijo "no"»  →  «Ana &amp; Jos&eacute; dijo &quot;no&quot;»
-- Al imprimirlos con e() se escapan otra vez y la persona lectora ve las
-- entidades en pantalla. En un relato de violencia, un texto ilegible es
-- información perdida.
--
-- ⚠ ANTES DE EJECUTAR
-- -------------------
-- 1. Respaldo completo de la base de datos.
-- 2. Ejecutar primero el bloque de VERIFICACIÓN y revisar una muestra.
-- 3. El orden de los REPLACE es deliberado: `&amp;` va AL FINAL. Invertirlo
--    convierte «&amp;lt;» en «<» y corrompe el dato de forma irreversible.
-- 4. Ejecutar UNA SOLA VEZ. Una segunda pasada volvería a decodificar texto
--    que legítimamente contiene entidades escritas por la persona usuaria.
--
-- Nota: no se cubren entidades con nombre (&eacute;, &ntilde;, …) porque
-- htmlspecialchars() con ENT_QUOTES solo produce las cinco de abajo. Si el
-- bloque de verificación revela otras, hay que ampliar esta migración.
-- =============================================================================

-- ─── VERIFICACIÓN (ejecutar primero, no modifica nada) ──────────────────────
-- SELECT COUNT(*) AS afectadas FROM denuncias
--  WHERE descripcion LIKE '%&amp;%' OR descripcion LIKE '%&quot;%'
--     OR descripcion LIKE '%&#039;%' OR descripcion LIKE '%&lt;%';
--
-- SELECT id, LEFT(descripcion, 120) FROM denuncias
--  WHERE descripcion LIKE '%&%;%' LIMIT 20;

START TRANSACTION;

UPDATE `denuncias` SET
    `descripcion` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `descripcion`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
    `testigos` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `testigos`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
    `agresor_nombre` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `agresor_nombre`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
    `lugar_hechos` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `lugar_hechos`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
    `facultad` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `facultad`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
    `sede` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `sede`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
    `edificio` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `edificio`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
    `salon_area` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `salon_area`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
    `denunciante_nombre` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `denunciante_nombre`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
    `denunciante_contacto` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `denunciante_contacto`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&')
WHERE `id` > 0;

UPDATE `seguimientos` SET
    `contenido` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `contenido`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&')
WHERE `id` > 0;

UPDATE `usuarios` SET
    `nombre` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        `nombre`, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&')
WHERE `id` > 0;

-- Revisar el resultado antes de confirmar:
--   SELECT id, LEFT(descripcion, 120) FROM denuncias ORDER BY id DESC LIMIT 10;
COMMIT;
-- En caso de duda: ROLLBACK;
