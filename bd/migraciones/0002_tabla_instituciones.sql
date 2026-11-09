-- ==============================================================================
-- CREAR TABLA INSTITUCIONES FALTANTE
-- ==============================================================================

DROP TABLE IF EXISTS `instituciones`;
CREATE TABLE `instituciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `tipo` enum('policia','fiscalia','comisaria','hospital','centro_apoyo','otro') NOT NULL,
  `direccion` varchar(500) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `municipio` varchar(100) DEFAULT NULL,
  `departamento` varchar(100) DEFAULT NULL,
  `horario_atencion` varchar(255) DEFAULT NULL,
  `servicios_ofrecidos` text DEFAULT NULL,
  `contacto_responsable` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `notas` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_municipio` (`municipio`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar instituciones de ejemplo
INSERT INTO `instituciones` (`nombre`, `tipo`, `direccion`, `telefono`, `email`, `municipio`, `departamento`, `horario_atencion`, `servicios_ofrecidos`, `contacto_responsable`, `activo`) VALUES
('Policía Nacional - Estación Central', 'policia', 'Calle 1 # 2-3', '123', 'policia@ejemplo.com', 'Bogotá', 'Cundinamarca', '24/7', 'Atención de denuncias, protección policial', 'Comandante Pérez', 1),
('Fiscalía General de la Nación', 'fiscalia', 'Carrera 10 # 15-20', '456', 'fiscalia@ejemplo.com', 'Bogotá', 'Cundinamarca', 'Lunes a Viernes 8am-5pm', 'Recepción de denuncias penales, investigación', 'Dr. González', 1),
('Comisaría de Familia', 'comisaria', 'Avenida 5 # 8-12', '789', 'comisaria@ejemplo.com', 'Bogotá', 'Cundinamarca', 'Lunes a Viernes 7am-4pm', 'Medidas de protección, orientación legal y psicológica', 'Dra. Martínez', 1),
('Hospital San José', 'hospital', 'Calle 20 # 30-40', '321', 'hospital@ejemplo.com', 'Bogotá', 'Cundinamarca', '24/7', 'Atención médica, valoración médico-legal', 'Dr. Ramírez', 1),
('Centro de Atención a Víctimas', 'centro_apoyo', 'Carrera 7 # 12-34', '654', 'centro@ejemplo.com', 'Bogotá', 'Cundinamarca', 'Lunes a Sábado 8am-6pm', 'Apoyo psicológico, orientación legal, trabajo social', 'Psic. López', 1);

-- ==============================================================================
-- FIN
-- ==============================================================================
