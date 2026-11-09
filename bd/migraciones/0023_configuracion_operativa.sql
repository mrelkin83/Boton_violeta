-- =============================================================================
-- Migración 0023 · Configuración operativa (cierra H-20 / H-26)
-- =============================================================================
-- CLAUDE.md v3.1 §6 · Fase E · Reutiliza la tabla `configuracion` existente.
-- Todo cambio posterior se audita con valor anterior y nuevo (consola admin).
-- Aprobación G-01: orden de Elkin del 2 de septiembre de 2026.
--
-- INSERT IGNORE: si una clave ya existe, se respeta su valor actual.
-- =============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO configuracion (clave, valor, descripcion, tipo) VALUES
('UMBRAL_SUPRESION',              '5',     'RN-10 · frecuencia mínima de una celda en reportes agregados', 'number'),
('DIAS_AVISO_VENCIMIENTO',        '3',     'Días hábiles antes del vencimiento para avisar', 'number'),
('DIAS_ESCALAMIENTO',             '2',     'Días hábiles tras el vencimiento para escalar a coordinación', 'number'),
('ENCUENTRO_INICIAL_AUTOMATICO',  '1',     'RN-18 · crear el encuentro inicial al radicar denuncia formal', 'boolean'),
('ENCUENTRO_INICIAL_DURACION_MIN','60',    'Duración del encuentro inicial en minutos (pendiente Bienestar §12.4)', 'number'),
('CANAL_ANONIMO_ACTIVO',          '0',     'Ac.002 art.33 · canal de reporte anónimo (pendiente Comité §13.1)', 'boolean'),
('GRAPH_ACTIVO',                  '0',     'Integración Microsoft Graph', 'boolean'),
('CORREO_REMITENTE',              'vbgbienestarud@udistrital.edu.co', 'Buzón institucional de salida', 'string'),
('DOMINIO_INSTITUCIONAL',         'udistrital.edu.co', 'RN-15 · dominio exigido al correo institucional', 'string'),
('RN14_BLOQUEA_ETAPA',            '1',     'RN-14 · sin decisión sobre Procuraduría no se pasa a investigación (§12.2, desactivable con auditoría, G-15)', 'boolean'),
('DIAS_ENVIO_REMISION',           '5',     'Días hábiles para enviar una remisión decidida (§7.2)', 'number'),
('DIAS_RESPUESTA_SOLICITUD_ESTADO','10',   'Días hábiles para que una dependencia responda una solicitud de estado (§16 E5)', 'number'),
('DIAS_DECISION_REPORTE_ANONIMO', '10',    'RN-21 · días hábiles para decidir sobre un reporte anónimo con mérito', 'number');
