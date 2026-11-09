# Pruebas — Botón Violeta

Pruebas automatizadas en PHP nativo, sin dependencias (CLAUDE.md §5: cero dependencias,
por lo que no se usa PHPUnit).

## Regla de oro

**Nunca ejecutar contra producción.** Las pruebas escriben filas en `denuncias`,
`archivos`, `seguimientos` y `auditoria`. El script aborta solo si `APP_ENV` es
`production`, pero esa salvaguarda no reemplaza el cuidado de apuntar a la
réplica correcta.

## Preparar una réplica local

```bash
# 1. Crear la base de datos de pruebas
mysql -h127.0.0.1 -uroot -e "CREATE DATABASE bv_pruebas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Cargar el esquema
mysql -h127.0.0.1 -uroot bv_pruebas < bd/migraciones/0001_esquema_inicial.sql
mysql -h127.0.0.1 -uroot bv_pruebas < bd/migraciones/0002_tabla_instituciones.sql
mysql -h127.0.0.1 -uroot bv_pruebas < bd/migraciones/0003_denuncias_campos_ubicacion.sql
mysql -h127.0.0.1 -uroot bv_pruebas < bd/migraciones/0004_denuncias_campos_agresor.sql
mysql -h127.0.0.1 -uroot bv_pruebas < bd/migraciones/0005_archivos_usuario_id_nullable.sql

# 3. Copiar config.example.php como bv_config_pruebas.php FUERA del árbol web
#    y apuntarlo a bv_pruebas con APP_ENV = 'development'
```

> Si se restaura desde un volcado de producción, la réplica contendrá relatos
> reales. Eliminarla al terminar (`DROP DATABASE bv_pruebas;`) y borrar el
> volcado de forma segura. Un entorno de desarrollo no es un lugar autorizado
> de tratamiento de datos.

## Ejecutar

```bash
BV_CONFIG=/ruta/a/bv_config_pruebas.php php pruebas/fase1_reparacion.php
```

Devuelve código de salida `0` si todo pasa y `1` si hay algún fallo, de modo que
puede encadenarse en un cron o en un flujo de integración continua.

## Contenido actual

| Archivo | Comprobaciones | Cubre |
|---|:--:|---|
| `fase1_reparacion.php` | 35 | H-02, H-03, H-04, H-05, H-06, H-07, H-11, H-12, H-14 · prohibiciones 1, 9 y 11 · §5.1.3 |
| `fase2_seguimiento.php` | 47 | Cifrado en reposo · índice ciego · respuesta uniforme · código de un solo uso · sesión efímera · proyección pública · límites en tres ejes · **criterio de aceptación: 10.000 intentos de enumeración** |

Ambas suites son idempotentes: limpian su propio estado al arrancar y pueden
ejecutarse repetidamente. `fase2_seguimiento.php` tarda unos 30 segundos por la
prueba de enumeración.

## Réplica para la Fase 2

Además del esquema base, requiere:

```bash
mysql -h127.0.0.1 -uroot bv_pruebas < bd/migraciones/0008_seguimiento_seguro.sql
BV_CONFIG=/ruta/bv_config_pruebas.php php bd/migraciones/0009_cifrar_contactos.php --ejecutar
```

> **Aviso de portabilidad:** las migraciones heredadas `0003` y `0004` usan
> sintaxis exclusiva de MariaDB (`DROP FOREIGN KEY IF EXISTS`,
> `ADD COLUMN IF NOT EXISTS`) y **no se ejecutan en MySQL 8**. Producción corre
> MariaDB, así que allí funcionaron; una réplica sobre MySQL 8 necesita añadir
> a mano las columnas `tipo_agresor`, `agresor_nombre`, `agresor_identificacion`,
> `testigos`, `facultad`, `sede`, `edificio` y `salon_area`. Las migraciones
> nuevas (0005 en adelante) sí son compatibles con ambos motores.

## Pendiente

`pruebas/reglas/` — una prueba por cada regla de negocio inviolable (RN-01 … RN-13),
exigida por CLAUDE.md §7. Se irá poblando a medida que las reglas se implementen:
las RN-01, RN-02, RN-07 y RN-08 corresponden a la Fase 3.
