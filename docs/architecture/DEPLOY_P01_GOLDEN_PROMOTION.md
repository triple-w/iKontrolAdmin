# DEPLOY-P01 — Lifecycle de promoción y compuerta Golden

## Alcance implementado

Este paquete agrega gobierno operativo al deployer existente de iKontrolAdmin. No construye artefactos, no modifica `ikontrol-platform` y no cambia la mecánica de staging, backup, reemplazo de archivos, upgrade dirigido o rollback.

La validación técnica y la promoción operativa son ejes independientes:

- `ikontrol_releases.status`: `discovered`, `validated`, `invalid` y el valor legacy `deprecated`.
- `ikontrol_releases.promotion_status`: `BUILT`, `STAGING`, `APPROVED`, `DEPRECATED`.

Una release nueva o existente validada inicia en `BUILT`. Las releases que ya tenían el estado técnico legacy `deprecated` se migran a promoción `DEPRECATED`.

## Golden oficial

La única fuente de autoridad es:

```dotenv
IKONTROL_GOLDEN_INSTANCE_ID=123
```

El valor debe ser un entero positivo y debe corresponder a un registro existente de `ikontrol_instances`. `GoldenInstanceResolver` falla de forma cerrada si falta, es inválido o no existe.

El campo histórico `is_release_canary` no concede permisos Golden. Se conserva como metadata informativa para no romper la administración actual.

## Estados y transiciones

```text
BUILT --(Golden run válido)--> STAGING
STAGING --(aprobación manual)--> APPROVED
BUILT | STAGING | APPROVED --(confirmación)--> DEPRECATED
```

No se permiten regresiones ni saltos, por ejemplo `BUILT -> APPROVED`, `APPROVED -> BUILT` o `DEPRECATED -> APPROVED`.

### BUILT → STAGING

Requiere un `instance_update_run` que:

- pertenezca a la instancia configurada como Golden;
- esté `completed`/`SUCCESS`;
- pertenezca a la misma release;
- tenga la misma versión objetivo;
- conserve exactamente el commit SHA y artifact SHA-256 de la release.

### STAGING → APPROVED

Requiere además:

- actor autenticado;
- confirmación exacta del Release ID;
- evidencia/checklist no vacío;
- el mismo Golden run que originó la transición vigente a STAGING.

### DEPRECATED

Bloquea nuevos `prepare` y `execute`, tanto en Golden como en instancias normales. Requiere actor, confirmación exacta y motivo.

## Invariantes del backend

1. `BUILT` y `STAGING` sólo pueden desplegarse en Golden.
2. Una instancia normal sólo puede recibir `APPROVED` vigente.
3. `DEPRECATED` nunca admite un nuevo deployment.
4. La compuerta se ejecuta en `ReleaseDeploymentService::prepare()` y nuevamente en `execute()`.
5. `ReleaseCompatibilityService` aplica la misma compuerta; la UI no puede convertir una release bloqueada en “disponible”.
6. Cada update run conserva snapshots inmutables `release_commit_sha` y `release_artifact_sha256`.
7. Cada promoción conserva sus propios snapshots del commit y artefacto.
8. Una aprobación sólo es vigente si el evento, su Golden run y la release actual siguen coincidiendo.
9. Si GitHub sync cambia el commit o artifact SHA de una release aprobada, no se corrige ni se reaprueba silenciosamente: todo deployment queda bloqueado. El operador debe investigar y deprecar la versión comprometida; una corrección debe publicarse con una versión nueva.
10. `release_promotions` es append-only desde el modelo: no expone rutas de edición y rechaza `update` y `delete`. Sus FKs usan `RESTRICT` para no destruir evidencia relacionada.

11. La ruta HTTP histÃ³rica de upgrade dirigido resuelve la versiÃ³n objetivo desde el catÃ¡logo y aplica la misma compuerta antes de ejecutar Spark.
12. La operaciÃ³n HTTP genÃ©rica `migrate` fue retirada de rutas y UI; el flujo de releases no ofrece una vÃ­a alternativa a la promociÃ³n.

## Persistencia

La migración `2026_10_16_000001_add_release_promotion_lifecycle.php` agrega:

- `ikontrol_releases.promotion_status`;
- `instance_update_runs.release_commit_sha`;
- `instance_update_runs.release_artifact_sha256`;
- tabla `release_promotions` con transición, actor, Golden run, snapshots, evidencia, notas y fecha.

Los runs creados antes de esta migración no contienen snapshots confiables y deliberadamente no pueden promover una release. Se necesita ejecutar un nuevo dry-run/deployment Golden después de instalar DEPLOY-P01.

## Auditoría

El journal `release_promotions` conserva la transición de dominio. Además se registran acciones en `admin_audit_logs`:

- `RELEASE_PROMOTED_TO_STAGING`;
- `RELEASE_APPROVED`;
- `RELEASE_DEPRECATED`;
- eventos `*_REJECTED` para intentos que alcanzan el servicio y violan una regla de dominio.

## Interfaz

El catálogo muestra por separado validación técnica y promoción. El detalle del manifest muestra:

- commit y artifact SHA;
- Golden oficial;
- historial de transiciones, actor, fecha, run y evidencia;
- acciones contextuales para STAGING, APPROVED y DEPRECATED.

En una instancia normal no se ofrece “Preparar actualización” para una release no aprobada. Esta ocultación es sólo una ayuda visual: los servicios bloquean igualmente una petición HTTP manual.

## Procedimiento manual posterior

Este procedimiento debe realizarse sólo después de revisar y desplegar el código de Admin en una ventana autorizada:

1. Respaldar la base de datos de iKontrolAdmin.
2. Desplegar iKontrolAdmin y ejecutar `php artisan migrate --force` dentro de su procedimiento normal de despliegue.
3. Identificar el ID real de `ikontrol.ikontrol.solutions` en `ikontrol_instances`.
4. Configurar `IKONTROL_GOLDEN_INSTANCE_ID=<id>` y recargar la configuración de Laravel con `php artisan config:clear` y, si el servidor usa cache, `php artisan config:cache`.
5. Abrir la ficha de esa instancia y comprobar que muestra `GOLDEN OFICIAL`.
6. Sincronizar una release validada; debe aparecer como `BUILT`.
7. Preparar y ejecutar el deployment únicamente en Golden siguiendo el plan existente.
8. Confirmar que el update run terminó en `completed` y conserva ambos snapshots.
9. En el manifest de la release, promoverla a `STAGING` seleccionando ese run.
10. Ejecutar la validación manual acordada sobre Golden.
11. Marcarla `APPROVED`, escribiendo el Release ID y adjuntando el checklist.
12. Verificar que sólo entonces aparece como disponible en una instancia normal compatible.

No se debe probar este procedimiento con instancias reales hasta que el paquete haya sido revisado, migrado en un entorno no productivo y exista autorización operacional expresa.

## Despliegue de iKontrolAdmin

### Unidad de despliegue

La unidad soportada es el commit completo `deploy: add golden release promotion gate` sobre la base `6b504c1`. No se deben copiar archivos sueltos: la compuerta depende de servicios, modelos, rutas, vistas y esquema trabajando como una sola versión.

El commit actualiza `.env.example`, `config/ikontrol.php`, los controladores de versiones, instancias y upgrades, los modelos de release/run/promoción, los servicios de deployment/promoción/compatibilidad/Golden, las vistas relacionadas y `routes/web.php`. También incorpora la migración `database/migrations/2026_10_16_000001_add_release_promotion_lifecycle.php`, las pruebas y esta documentación. El inventario exacto se obtiene con:

```bash
git diff --name-status 6b504c1..HEAD
```

### Orden operativo

1. Activar la ventana de mantenimiento y detener nuevas preparaciones o ejecuciones de releases.
2. Registrar el commit desplegado y respaldar el código y la base de datos de Admin.
3. Desplegar el commit completo de P01.
4. Ejecutar todas las migraciones pendientes, en orden, con `php artisan migrate --force`. P01 depende de migraciones anteriores; no se debe ejecutar su archivo manualmente.
5. Identificar Golden en la base de Admin y comprobar visualmente que corresponde al dominio real:

   ```sql
   SELECT id, name, domain, url, active
   FROM ikontrol_instances
   WHERE domain = 'ikontrol.ikontrol.solutions'
      OR url LIKE '%ikontrol.ikontrol.solutions%';
   ```

   Debe elegirse un solo registro activo. Si hay cero o varios candidatos, detener el despliegue y resolver el inventario.

6. Agregar al `.env` del servidor `IKONTROL_GOLDEN_INSTANCE_ID=<id_real>`. Es el `id` numérico de `ikontrol_instances`, no UUID, slug, dominio ni `is_release_canary`.
7. Ejecutar `php artisan config:clear` y, si producción usa configuración cacheada, `php artisan config:cache`.
8. Confirmar `php artisan migrate:status`, abrir la ficha de la instancia y verificar `GOLDEN OFICIAL` antes de cualquier deployment.

### Verificación de la primera release

Para `v1.1.5`, la ficha sincronizada debe mostrar exactamente:

```text
commit_sha:       3c4a65d00d833743378ce526da7e5891da7509f9
artifact_sha256:  e2d6763291c341c75ad8d09f901d66e7f545269c52a28e8a0a54033e565245f6
promotion_status: BUILT
```

1. Confirmar que una instancia normal compatible no ofrece preparación y que una petición directa también es rechazada.
2. Preparar y ejecutar `v1.1.5` en Golden.
3. Verificar que el run terminó en `completed` y que sus snapshots de commit y artefacto coinciden exactamente.
4. Promover ese mismo run a `STAGING`, completar las pruebas manuales y aprobar usando el Release ID exacto y el checklist.
5. Confirmar en `release_promotions` los eventos `BUILT -> STAGING` y `STAGING -> APPROVED`, ligados al mismo run, actor y hashes.
6. Confirmar que una instancia normal compatible ya permite preparar la release. No es necesario ejecutarla para demostrar la apertura de la compuerta.

### Reversión de Admin

Si falla antes de migrar, restaurar el código anterior (`6b504c1`), recargar cachés y retirar la variable Golden agregada durante el intento.

Si la migración terminó pero el código nuevo falla, restaurar temporalmente el código anterior y limpiar/reconstruir cachés. El esquema P01 es aditivo y el código anterior ignora sus columnas y tabla; conviene conservarlo para no destruir historial. No ejecutar `migrate:rollback` a ciegas.

Para revertir también el esquema, detener toda actividad y restaurar la copia de base previa. El `down()` elimina `release_promotions` y sus evidencias. MySQL puede dejar DDL aplicado si una migración falla a mitad; se debe inspeccionar el esquema y la tabla `migrations` antes de reintentar.

### Precauciones

- No operar releases si el ID Golden falta, es ambiguo o apunta a otra instancia; el backend falla de forma cerrada.
- No usar runs anteriores a P01 para promover: no tienen snapshots confiables. Crear un run Golden nuevo.
- No editar manualmente estados, hashes ni `release_promotions`.
- No cambiar metadatos de una release aprobada: una diferencia invalida la aprobación; publicar una versión nueva y deprecar la comprometida.
- Conservar el respaldo hasta completar Golden y verificar la apertura para una instancia normal.
- No confundir rollback de Admin con rollback de una instancia. Si su upgrade alcanzó migraciones, atender `MANUAL_REVIEW_REQUIRED` antes de reintentar.
