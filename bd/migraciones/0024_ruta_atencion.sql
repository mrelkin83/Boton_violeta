-- =============================================================================
-- Migración 0024 · Ruta de Atención Integral (Res. 027/2023 art. 9 ·
--                  Res. 142/2026 · Protocolo E1–E5)
-- =============================================================================
-- CLAUDE.md v3.1 §16 · RN-19, RN-20, RN-21
-- Aprobación G-01: orden de Elkin del 2 de septiembre de 2026.
--
-- Adiciones respecto del SQL del documento, exigidas por su propio texto:
--   · `denuncias.canal_origen`: la v3.0 §3.4 lo exige y RN-21 lo usa
--     ('anonimo'), pero la columna nunca se creó. Se crea aquí.
--   · `denuncias.relato_completado_at` / `relato_completado_por`: §16 E2(b),
--     relato único en dos momentos (RN-03).
--   · Campos de decisión RN-21 en `reportes_anonimos` (§16.5).
--   · `catalogo_medidas.categoria` admite 'acompanamiento' (§16 E3).
-- =============================================================================

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. RUTA DE ATENCIÓN · eje separado de la etapa procesal (§16)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE denuncias
  ADD COLUMN etapa_ruta ENUM('conocimiento','encuentro_inicial','atencion_integral','investigacion','seguimiento')
    NOT NULL DEFAULT 'conocimiento' AFTER etapa_procesal,
  ADD COLUMN canal_origen ENUM('boton_violeta','correo','telefono_whatsapp','presencial','oqrac','remision_dependencia','anonimo')
    NOT NULL DEFAULT 'boton_violeta' COMMENT 'v3.0 §3.4 · RN-21' AFTER etapa_ruta,
  ADD COLUMN relato_completado_at DATETIME NULL COMMENT 'RN-03 · §16 E2(b): el relato se completa una sola vez',
  ADD COLUMN relato_completado_por INT NULL,
  ADD INDEX idx_etapa_ruta (etapa_ruta);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. ACTA DEL ENCUENTRO INICIAL (§16 E2) · checklist del Protocolo, con hash
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE actas_encuentro (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  encuentro_id          INT NOT NULL,
  denuncia_id           INT NOT NULL,
  socializo_ruta        TINYINT(1) NOT NULL DEFAULT 0,
  presento_profesionales TINYINT(1) NOT NULL DEFAULT 0,
  verifico_contactos    TINYINT(1) NOT NULL DEFAULT 0,
  ratifico_confidencialidad TINYINT(1) NOT NULL DEFAULT 0,
  recibio_descripcion   TINYINT(1) NOT NULL DEFAULT 0,
  acordo_seguimientos   TINYINT(1) NOT NULL DEFAULT 0,
  sibud_referencia      VARCHAR(120) NULL COMMENT 'solo el identificador externo, nunca contenido clínico',
  observaciones         TEXT NULL COMMENT 'NUNCA el relato: el acta lo referencia (RN-03)',
  cerrada               TINYINT(1) NOT NULL DEFAULT 0,
  cerrada_por           INT NULL,
  cerrada_at            DATETIME NULL,
  hash_acta             CHAR(64) NULL COMMENT 'sello al cerrar',
  created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (encuentro_id) REFERENCES encuentros(id) ON DELETE CASCADE,
  FOREIGN KEY (denuncia_id)  REFERENCES denuncias(id) ON DELETE CASCADE,
  UNIQUE KEY uk_encuentro (encuentro_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. PLAN DE ACOMPAÑAMIENTO POR ÁREA (§16 E3 · indicador 2.5)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE plan_acompanamiento (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  denuncia_id            INT NOT NULL,
  area                   ENUM('psicologia','trabajo_social','juridica','salud','academica') NOT NULL,
  requiere_acompanamiento TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'denominador del indicador 2.5',
  frecuencia_dias        SMALLINT NULL COMMENT 'periodicidad acordada',
  proxima_sesion         DATE NULL,
  responsable_id         INT NULL,
  activo                 TINYINT(1) NOT NULL DEFAULT 1,
  observaciones          TEXT NULL,
  created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (denuncia_id) REFERENCES denuncias(id) ON DELETE CASCADE,
  UNIQUE KEY uk_denuncia_area (denuncia_id, area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. MENORES DE EDAD Y CONTACTO DE EMERGENCIA (§16 E1 · RN-20)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE personas_caso
  ADD COLUMN es_menor_de_edad TINYINT(1) NULL COMMENT 'RN-20 · NULL = sin registrar',
  ADD COLUMN contacto_emergencia_cifrado VARBINARY(1024) NULL COMMENT 'familiar o acudiente',
  MODIFY COLUMN tipo_vinculacion ENUM('estudiante_pregrado','estudiante_posgrado','docente_carrera',
       'docente_vinculacion_especial','administrativo','trabajador_oficial',
       'contratista','tercerizado','egresado','visitante','externo','desconocido') NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. ESPACIOS NO FÍSICOS (Res. 142/2026 art. 2)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE unidades
  MODIFY COLUMN tipo ENUM('sede','facultad','proyecto_curricular','dependencia','espacio') NOT NULL;

INSERT IGNORE INTO unidades (tipo, padre_id, codigo, nombre, activa) VALUES
('espacio', NULL, 'ENTORNO_VIRTUAL', 'Entorno virtual (ámbito digital institucional)', 1),
('espacio', NULL, 'FUERA_DE_SEDE',   'Fuera de las sedes (con ocasión de la misión institucional)', 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. ROL COMISIÓN DE SEGUIMIENTO (Res. 142/2026 art. 13)
--    Equivalente a `reportes`: solo agregados con RN-10, jamás un expediente.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE usuarios
  MODIFY COLUMN rol ENUM('administrador','admin','coordinador','coordinacion_edheg',
                         'psicologo','trabajador_social','abogado',
                         'instructor','decisor','unidad_academica',
                         'talento_humano','reportes','comision_seguimiento')
      NOT NULL DEFAULT 'reportes';

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. DECISIÓN SOBRE REPORTES ANÓNIMOS (RN-21 · §16.5 · indicador 3.2)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE reportes_anonimos
  ADD COLUMN tiene_pruebas TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'trae pruebas o hechos concretos con autor identificable',
  ADD COLUMN decision ENUM('convertir_en_expediente_oficio','archivar_sin_merito') NULL,
  ADD COLUMN decision_motivacion TEXT NULL,
  ADD COLUMN decidida_por INT NULL,
  ADD COLUMN decidida_at DATETIME NULL,
  ADD COLUMN denuncia_id INT NULL COMMENT 'expediente de oficio creado desde este reporte',
  ADD INDEX idx_decision (estado, decision);

-- ─────────────────────────────────────────────────────────────────────────────
-- 8. CATEGORÍA DE ACOMPAÑAMIENTO EN EL CATÁLOGO (§16 E3)
--    El catálogo real de apoyos socioeconómicos lo carga Bienestar (G-02).
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE catalogo_medidas
  MODIFY COLUMN categoria ENUM('accion_inmediata','proteccion','restaurativa',
                               'pedagogica','no_revictimizacion','acompanamiento') NOT NULL;
