-- =============================================================================
-- Migración 0030 · OAAD y OCID son la MISMA oficina: queda solo OCID
-- =============================================================================
-- Precisión institucional de Elkin (2/09/2026): la Oficina Asesora de
-- Asuntos Disciplinarios (OAAD) fue renombrada por el manual de funciones
-- —posterior al Protocolo— como Oficina de Control Interno Disciplinario
-- (OCID). No son dos oficinas: el Protocolo (etapa E4, direccionamiento en
-- 5 días hábiles) usa el nombre antiguo.
--
-- Qué hace:
--   1. Reapunta a OCID todo lo que señalaba a OAAD (compromisos, remisiones,
--      cuentas) y elimina la dependencia y el destino OAAD.
--   2. OCID asume el fundamento del direccionamiento del Protocolo E4.
--   3. El código del plazo del catálogo (DIRECCIONAMIENTO_OAAD) se conserva
--      —el Protocolo lo nombra así— pero su nombre visible aclara el cambio.
-- =============================================================================

SET NAMES utf8mb4;

SET @unidad_oaad := (SELECT id FROM unidades WHERE codigo = 'OAAD' LIMIT 1);
SET @unidad_ocid := (SELECT id FROM unidades WHERE codigo = 'OCID' LIMIT 1);
SET @destino_oaad := (SELECT id FROM destinos_remision WHERE codigo = 'oaad' LIMIT 1);
SET @destino_ocid := (SELECT id FROM destinos_remision WHERE codigo = 'ocid' LIMIT 1);

-- 1. Reapuntar referencias de la unidad
UPDATE compromisos SET responsable_unidad_id = @unidad_ocid
 WHERE @unidad_oaad IS NOT NULL AND responsable_unidad_id = @unidad_oaad;

UPDATE remisiones_externas SET unidad_interna_id = @unidad_ocid
 WHERE @unidad_oaad IS NOT NULL AND unidad_interna_id = @unidad_oaad;

UPDATE usuarios SET unidad_id = @unidad_ocid
 WHERE @unidad_oaad IS NOT NULL AND unidad_id = @unidad_oaad;

UPDATE turnos_atencion SET unidad_id = @unidad_ocid
 WHERE @unidad_oaad IS NOT NULL AND unidad_id = @unidad_oaad;

-- 2. Reapuntar y eliminar el destino duplicado
UPDATE remisiones_externas SET destino_id = @destino_ocid
 WHERE @destino_oaad IS NOT NULL AND destino_id = @destino_oaad;

DELETE FROM destinos_remision WHERE codigo = 'oaad';

-- 3. Eliminar la dependencia OAAD (ya sin referencias)
DELETE FROM unidades WHERE codigo = 'OAAD';

-- 4. OCID asume el direccionamiento del Protocolo E4
UPDATE destinos_remision
   SET nombre = 'Oficina de Control Interno Disciplinario (OCID)',
       fundamento = 'v3.0 §3.3 · Ley 1952/2019 · Protocolo E4 (direcciona en 5 días hábiles; antes OAAD)'
 WHERE codigo = 'ocid';

UPDATE unidades
   SET nombre = 'Oficina de Control Interno Disciplinario'
 WHERE codigo = 'OCID';

UPDATE catalogo_plazos
   SET nombre = 'Direccionamiento del caso por la OCID (el Protocolo la nombra OAAD)'
 WHERE codigo = 'DIRECCIONAMIENTO_OAAD';
