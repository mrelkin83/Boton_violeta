-- =============================================================================
-- Migración 0014 · Catálogo de medidas y de plazos
-- =============================================================================
-- Fase: 5 · Medidas y riesgo
--
-- Cada medida cita el artículo del que proviene. Ninguna es invención: si un
-- supuesto no está en los Acuerdos, no está aquí (CLAUDE.md §1 y prohibición 13).
--
-- ⚠ VACÍO CONOCIDO — Ac. 002 art. 27
--   El artículo 27 regula «medidas cuando la persona denunciada es estudiante»,
--   pero el detalle de ese listado NO consta en las fuentes disponibles
--   (CLAUDE.md §4 solo lo enuncia). NO se siembra: inventarlo sería fabricar
--   normativa que después se aplica a personas.
--   → Pendiente: solicitar el texto del art. 27 y añadirlo con el prefijo A27_.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- ART. 24 · Medidas generales de acción inmediata  (las 7 del Definition of Done)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `catalogo_medidas`
  (`codigo`, `categoria`, `nombre`, `descripcion`, `fundamento`, `aplica_a`, `dirigida_a`, `requiere_consentimiento`, `dias_habiles_ejecucion`)
VALUES
('A24_ACTIVACION_RUTA', 'accion_inmediata', 'Activación de la ruta de atención',
 'Activación inmediata de la ruta institucional de atención integral para la persona afectada.',
 'Ac. 002/2026 art. 24', 'estudiante,docente,administrativo,contratista,externo', 'afectada', 1, 1),

('A24_REMISION_EXTERNA', 'accion_inmediata', 'Remisión externa prioritaria',
 'Remisión prioritaria a entidades externas competentes: salud, policía, fiscalía, comisaría o centros de apoyo.',
 'Ac. 002/2026 art. 24', 'estudiante,docente,administrativo,contratista,externo', 'afectada', 1, 1),

('A24_BIENESTAR_PRIORITARIO', 'accion_inmediata', 'Acceso prioritario a servicios de Bienestar',
 'Acceso prioritario a los servicios de Bienestar Universitario, sin condicionamiento al calendario académico.',
 'Ac. 002/2026 art. 24', 'estudiante,docente,administrativo,contratista', 'afectada', 1, 3),

('A24_TELETRABAJO_TIC', 'accion_inmediata', 'Teletrabajo o actividades mediadas por TIC',
 'Autorización de teletrabajo o de desarrollo de actividades mediadas por tecnologías de la información.',
 'Ac. 002/2026 art. 24 · Res. 589/2023', 'docente,administrativo,contratista', 'afectada', 1, 5),

('A24_LINEA_ACOMPANAMIENTO', 'accion_inmediata', 'Línea telefónica y WhatsApp de acompañamiento',
 'Habilitación de la línea telefónica y del canal de WhatsApp de acompañamiento permanente.',
 'Ac. 002/2026 art. 24', 'estudiante,docente,administrativo,contratista,externo', 'afectada', 0, 1),

('A24_PUNTO_SEGURO', 'accion_inmediata', 'Punto seguro en campus',
 'Habilitación de un punto seguro dentro del campus al que la persona afectada pueda acudir.',
 'Ac. 002/2026 art. 24', 'estudiante,docente,administrativo,contratista', 'afectada', 0, 3),

('A24_VEEDURIA_PREVENTIVA', 'accion_inmediata', 'Veeduría preventiva en aulas y oficinas',
 'Veeduría preventiva en aulas, oficinas y espacios señalados, sin exponer la identidad de la persona afectada.',
 'Ac. 002/2026 art. 24', 'estudiante,docente,administrativo,contratista', 'institucional', 0, 5);

-- ─────────────────────────────────────────────────────────────────────────────
-- ART. 26 · Medidas para estudiante víctima
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `catalogo_medidas`
  (`codigo`, `categoria`, `nombre`, `descripcion`, `fundamento`, `aplica_a`, `dirigida_a`, `requiere_consentimiento`, `dias_habiles_ejecucion`)
VALUES
('A26_CAMBIO_GRUPO', 'proteccion', 'Cambio de grupo',
 'Cambio de grupo de la persona afectada, sin afectación de su situación académica.',
 'Ac. 002/2026 art. 26', 'estudiante', 'afectada', 1, 3),

('A26_REPROGRAMAR_EVALUACIONES', 'proteccion', 'Reprogramación de evaluaciones',
 'Reprogramación de evaluaciones y actividades académicas afectadas por los hechos.',
 'Ac. 002/2026 art. 26', 'estudiante', 'afectada', 1, 5),

('A26_CANCELACION_SIN_PERJUICIO', 'proteccion', 'Cancelación de asignaturas o semestre sin perjuicio académico',
 'Cancelación de asignaturas o del semestre sin perjuicio académico y sin importar el calendario académico.',
 'Ac. 002/2026 art. 26', 'estudiante', 'afectada', 1, 5),

('A26_CAMBIO_PRACTICA', 'proteccion', 'Cambio de práctica o pasantía',
 'Cambio del lugar o de las condiciones de la práctica o pasantía.',
 'Ac. 002/2026 art. 26', 'estudiante', 'afectada', 1, 10);

-- ─────────────────────────────────────────────────────────────────────────────
-- ART. 26 PARÁGRAFO · Cuando la persona denunciada es docente
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `catalogo_medidas`
  (`codigo`, `categoria`, `nombre`, `descripcion`, `fundamento`, `aplica_a`, `dirigida_a`, `requiere_consentimiento`, `dias_habiles_ejecucion`)
VALUES
('A26P_CAMBIO_EVALUADOR', 'proteccion', 'Cambio de evaluador',
 'Designación de un evaluador distinto de la persona denunciada.',
 'Ac. 002/2026 art. 26 par. lit. a', 'estudiante', 'afectada', 1, 5),

('A26P_SEGUNDO_EVALUADOR', 'proteccion', 'Segundo evaluador para lo ya calificado',
 'Designación de un segundo evaluador que revise las calificaciones ya emitidas por la persona denunciada.',
 'Ac. 002/2026 art. 26 par. lit. b', 'estudiante', 'afectada', 1, 10),

('A26P_OTRO_DOCENTE', 'proteccion', 'Cursar el espacio académico con otro docente',
 'Traslado de la persona afectada a un grupo del mismo espacio académico con otro docente.',
 'Ac. 002/2026 art. 26 par. lit. c', 'estudiante', 'afectada', 1, 5),

('A26P_ELECTIVA_AFIN', 'proteccion', 'Electiva afín en otro proyecto curricular',
 'Autorización para cursar una electiva afín en otro proyecto curricular.',
 'Ac. 002/2026 art. 26 par. lit. d', 'estudiante', 'afectada', 1, 10),

('A26P_MODALIDAD_ESPECIAL', 'proteccion', 'Modalidad especial de espacio académico',
 'Cursar el espacio académico en la modalidad especial prevista en el art. 36 del Estatuto Estudiantil.',
 'Ac. 002/2026 art. 26 par. lit. e · Ac. 027/1993 art. 36', 'estudiante', 'afectada', 1, 10),

('A26P_TUTORIA_OTRO_DOCENTE', 'proteccion', 'Tutoría con otro docente',
 'Asignación de la dirección de trabajo de grado o tutoría a un docente distinto.',
 'Ac. 002/2026 art. 26 par. lit. f', 'estudiante', 'afectada', 1, 10),

('A26P_PROHIBICION_CONTACTO', 'proteccion', 'Prohibición de contacto',
 'Prohibición de contacto directo, por intermedio de terceros o por medios electrónicos, entre la persona denunciada y la persona afectada.',
 'Ac. 002/2026 art. 26 par. lit. h', 'estudiante,docente,administrativo,contratista', 'denunciada', 0, 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- ART. 28 · Medidas para docente víctima
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `catalogo_medidas`
  (`codigo`, `categoria`, `nombre`, `descripcion`, `fundamento`, `aplica_a`, `dirigida_a`, `requiere_consentimiento`, `dias_habiles_ejecucion`)
VALUES
('A28_TRASLADO_ESPACIOS', 'proteccion', 'Traslado de espacios académicos',
 'Traslado de los espacios académicos a cargo de la persona afectada.',
 'Ac. 002/2026 art. 28', 'docente', 'afectada', 1, 10),

('A28_REPROGRAMACION_LABORAL', 'proteccion', 'Reprogramación laboral',
 'Reprogramación de la jornada o de las actividades laborales de la persona afectada.',
 'Ac. 002/2026 art. 28', 'docente', 'afectada', 1, 5),

('A28_CAMBIO_PAR_EVALUADOR', 'proteccion', 'Cambio como par evaluador',
 'Relevo de la persona afectada o de la persona denunciada en funciones de par evaluador.',
 'Ac. 002/2026 art. 28', 'docente', 'afectada', 1, 10);

-- ─────────────────────────────────────────────────────────────────────────────
-- ART. 29 · Administrativos y contratistas
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `catalogo_medidas`
  (`codigo`, `categoria`, `nombre`, `descripcion`, `fundamento`, `aplica_a`, `dirigida_a`, `requiere_consentimiento`, `dias_habiles_ejecucion`)
VALUES
('A29_PROTECCION_LABORAL', 'proteccion', 'Protección laboral',
 'Medidas de protección laboral gestionadas por el Comité de Convivencia Laboral y Talento Humano.',
 'Ac. 002/2026 art. 29', 'administrativo,contratista', 'afectada', 1, 10),

('A29_NO_TERMINACION', 'proteccion', 'Prohibición de terminación anticipada por la denuncia',
 'Prohibición de terminar anticipadamente o de no renovar el vínculo por causa o con ocasión de la denuncia.',
 'Ac. 002/2026 art. 29', 'administrativo,contratista', 'afectada', 0, 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- AC. 003 ART. 17 · Medidas de protección provisionales
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `catalogo_medidas`
  (`codigo`, `categoria`, `nombre`, `descripcion`, `fundamento`, `aplica_a`, `dirigida_a`, `requiere_consentimiento`, `dias_habiles_ejecucion`)
VALUES
('A003_17_PROVISIONAL', 'proteccion', 'Medida de protección provisional',
 'Medida provisional adoptada bajo criterios de necesidad, proporcionalidad y temporalidad, mientras se surte la actuación.',
 'Ac. 003/2026 art. 17', 'estudiante,docente,administrativo,contratista,externo', 'ambas', 0, 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- AC. 003 ART. 18 · Prohibición de retaliaciones
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `catalogo_medidas`
  (`codigo`, `categoria`, `nombre`, `descripcion`, `fundamento`, `aplica_a`, `dirigida_a`, `requiere_consentimiento`, `dias_habiles_ejecucion`)
VALUES
('A003_18_NO_RETALIACION', 'no_revictimizacion', 'Advertencia de prohibición de retaliaciones',
 'Advertencia formal de la prohibición de retaliaciones contra quien denuncia, la persona afectada o quienes declaren.',
 'Ac. 003/2026 art. 18', 'estudiante,docente,administrativo,contratista', 'denunciada', 0, 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- CATÁLOGO DE PLAZOS  (CLAUDE.md §10.5)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `catalogo_plazos` (`codigo`, `nombre`, `cantidad`, `unidad`, `fundamento`) VALUES
('DIRECCIONAMIENTO_OAAD',   'Direccionamiento del caso por la OAAD',          5,  'dias_habiles',    'Protocolo institucional VBG, etapa E4'),
('PLIEGO_CARGOS_EST',       'Pliego de cargos estudiantil',                   5,  'dias_habiles',    'Ac. 027/1993 art. 86.1'),
('DESCARGOS_EST',           'Descargos estudiantil',                          8,  'dias_habiles',    'Ac. 027/1993 art. 86.2'),
('PRUEBAS_EST',             'Práctica de pruebas',                            10, 'dias_habiles',    'Ac. 027/1993 art. 86.3'),
('INFORME_INVESTIGADOR',    'Informe del investigador',                       3,  'dias_habiles',    'Ac. 027/1993 art. 86.4'),
('DECISION_FONDO_EST',      'Decisión de fondo',                              5,  'dias_habiles',    'Ac. 027/1993 art. 86.5'),
('PLIEGO_CARGOS_DOC',       'Pliego de cargos docente',                       5,  'dias_habiles',    'Ac. 011/2002'),
('DESCARGOS_DOC',           'Descargos docente',                              5,  'dias_habiles',    'Ac. 011/2002'),
('ALEGATOS_PRECALIF',       'Alegatos precalificatorios',                     10, 'dias_calendario', 'Ac. 003/2026 art. 67'),
('ALEGATOS_CONCLUSION',     'Alegatos de conclusión',                         10, 'dias_calendario', 'Ac. 003/2026 art. 74'),
('INDAGACION_PREVIA',       'Indagación previa',                              6,  'meses',           'Ac. 003/2026 art. 63'),
('REVISION_MEDIDAS',        'Revisión de medidas y seguimiento integral',     3,  'meses',           'Ac. 002/2026 art. 30 · Protocolo E5');

-- =============================================================================
-- Verificación
-- =============================================================================
--   SELECT fundamento, COUNT(*) FROM catalogo_medidas GROUP BY fundamento;
--   SELECT COUNT(*) FROM catalogo_medidas WHERE fundamento LIKE '%art. 24%'; -- 7
--   SELECT COUNT(*) FROM catalogo_plazos; -- 12
--
--   -- Toda medida debe citar un artículo:
--   SELECT * FROM catalogo_medidas WHERE fundamento NOT LIKE '%art%';  -- 0 filas
