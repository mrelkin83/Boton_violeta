-- ==============================================================================
-- ACTUALIZAR TABLA DENUNCIAS PARA SOPORTAR DENUNCIAS PÚBLICAS/ANÓNIMAS
-- ==============================================================================
-- IMPORTANTE: Este script modifica la tabla para permitir denuncias sin usuario
-- ==============================================================================

-- PASO 1: Eliminar la restricción de clave foránea (que impide usuario_id NULL)
ALTER TABLE `denuncias`
DROP FOREIGN KEY `fk_denuncias_usuario`;

-- PASO 2: Hacer el campo usuario_id NULLABLE (permitir denuncias anónimas sin usuario)
ALTER TABLE `denuncias`
MODIFY COLUMN `usuario_id` int(11) DEFAULT NULL;

-- PASO 3: Agregar nuevos campos a la tabla denuncias
ALTER TABLE `denuncias`
ADD COLUMN `es_anonima` tinyint(1) NOT NULL DEFAULT 0 AFTER `estado`,
ADD COLUMN `denunciante_nombre` varchar(255) DEFAULT NULL AFTER `es_anonima`,
ADD COLUMN `denunciante_contacto` varchar(255) DEFAULT NULL AFTER `denunciante_nombre`;

-- PASO 4: Modificar el campo tipo_violencia para incluir 'laboral'
ALTER TABLE `denuncias`
MODIFY COLUMN `tipo_violencia` enum('fisica','psicologica','sexual','economica','verbal','laboral','otro') NOT NULL;

-- PASO 5: Agregar la clave foránea de nuevo con SET NULL (permite usuario_id NULL)
ALTER TABLE `denuncias`
ADD CONSTRAINT `fk_denuncias_usuario`
FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
ON DELETE SET NULL ON UPDATE CASCADE;

-- PASO 6: Agregar índices para los nuevos campos
ALTER TABLE `denuncias`
ADD INDEX `idx_es_anonima` (`es_anonima`);

-- PASO 7: Actualizar denuncias existentes que tengan usuario_id para marcarlas como no anónimas
UPDATE `denuncias`
SET `es_anonima` = 0,
    `denunciante_nombre` = CONCAT('Usuario #', usuario_id)
WHERE `usuario_id` IS NOT NULL AND `es_anonima` = 0;

-- ==============================================================================
-- VERIFICACIÓN
-- ==============================================================================
-- Para verificar que los cambios se aplicaron correctamente:
-- DESCRIBE denuncias;

-- ==============================================================================
-- FIN
-- ==============================================================================
