# Auditoría de arquitectura: releases y despliegue multiinstancia de iKontrol

**Fecha de auditoría:** 2026-10-16
**Repositorio auditado:** `triple-w/iKontrolAdmin`
**Rama y revisión:** `main` / `6b504c1`
**Alcance:** gestión de releases, artefactos, inspección, planificación, despliegue de código, upgrade dirigido, respaldo, rollback, auditoría y UI.
**Naturaleza del trabajo:** auditoría estática y pruebas automatizadas locales. No se ejecutaron migraciones ni comandos contra instancias reales.

> **ActualizaciÃ³n DEPLOY-P01 (2026-10-16):** la brecha de promociÃ³n descrita en esta auditorÃ­a fue implementada en el paquete DEPLOY-P01. La validaciÃ³n tÃ©cnica permanece separada de `promotion_status`; Golden se resuelve exclusivamente por `IKONTROL_GOLDEN_INSTANCE_ID`; los servicios de compatibilidad, preparaciÃ³n y ejecuciÃ³n exigen la compuerta; y las transiciones quedan en `release_promotions`. TambiÃ©n se cerraron las rutas HTTP heredadas: el upgrade dirigido exige una release permitida por la promociÃ³n y la operaciÃ³n genÃ©rica `migrate` dejÃ³ de ser enrutable. El resto del documento conserva el estado de la revisiÃ³n base `6b504c1` como evidencia histÃ³rica.

## Resumen ejecutivo

iKontrolAdmin ya contiene un deployer de código funcional y conservador. Descubre GitHub Releases, registra un catálogo local, exige referencias inmutables y SHA-256, descarga y extrae un ZIP en staging, valida un manifest de archivos, construye un dry-run, respalda los archivos que sustituirá, hace reemplazos atómicos por archivo, ejecuta el versionador dirigido de Platform y revierte el código instalado cuando falla.

La brecha principal no está en la copia de archivos: está en el gobierno de promoción. El estado `validated` significa hoy “manifest de release válido”, pero también funciona de facto como autorización para desplegar. No existen los estados operativos `BUILT`, `STAGING`, `APPROVED` y `DEPRECATED`, no se registra una validación manual de Golden y `is_release_canary` es informativo: el servicio no obliga a desplegar primero en `ikontrol.ikontrol.solutions` ni impide desplegar una release no aprobada en Smartfree, Navika u otra instancia.

Tampoco existe en este repositorio el productor reproducible del artefacto de Platform. Admin conoce y valida el contrato esperado, pero no construye el ZIP ni su `deployment-manifest.json`. El comando de templates existente pertenece al aprovisionamiento de instalaciones nuevas y no sustituye una canalización de releases.

Estimación del estado del deployer para el flujo objetivo completo: **65 %**. La mecánica técnica central existe; faltan la compuerta Golden/APPROVED, la generación reproducible del artefacto y endurecimiento operativo para concurrencia, recuperación y ejecución asíncrona.

## Método y límites

Se revisaron rutas, controladores, modelos, migraciones, servicios, comandos, vistas, configuración, pruebas y documentación. También se buscaron jobs, integraciones SSH/SFTP/SCP/rsync, scripts de artefactos y usos de GitHub/cPanel.

No se consultó producción, no se ejecutó `php spark`, no se conectó a Golden, Smartfree o Navika, no se ejecutó `migrate`, no se descargó un artefacto real y no se comprobó el estado de las migraciones en la base productiva. Por ello, esta auditoría afirma qué implementa el código en la revisión indicada, no que la configuración o las tablas estén desplegadas correctamente en un servidor específico.

# A. Arquitectura actual encontrada

## A.1 Componentes y responsabilidades

| Área | Implementación encontrada | Estado |
|---|---|---|
| Catálogo de releases | `ikontrol_releases`, modelo, sincronizador y UI | Implementado |
| Descubrimiento | GitHub Releases; drafts ignorados; tag resuelto a commit SHA exacto | Implementado |
| Manifest lógico | `updates/{version}/manifest.json`, esquema 1 | Implementado |
| Artefacto | Asset ZIP con nombre permitido, URL/path y SHA-256 | Consumidor implementado; productor ausente |
| Manifest de archivos | `updates/{version}/deployment-manifest.json` dentro del ZIP | Validador/consumidor implementado; generador ausente |
| Inspección | `ikontrol:version`, `database-check`, `baseline-check` | Implementado |
| Clasificación | `CURRENT`, `UPDATE_AVAILABLE`, `LEGACY_ADOPTABLE`, `BLOCKED`, `UNREACHABLE` | Implementado |
| Plan lógico de upgrade | `ikontrol:upgrade:plan --target=... --json` | Implementado |
| Adopción legacy | dry-run y ejecución confirmada de `adopt-baseline` | Implementado |
| Dry-run de archivos | nuevos, reemplazos, protegidos, conflictos, espacio y rutas | Implementado |
| Staging | descarga, verificación, ZIP seguro y extracción confinada | Implementado |
| Backup de código | archivos a reemplazar, hashes y manifest en BD | Implementado |
| Despliegue | temporal + hash + rename por archivo | Implementado |
| Post-deploy | version, DB check, plan, upgrade, reinspección | Implementado |
| Rollback de código | restaura reemplazos y elimina sólo creaciones intactas | Implementado con brecha semántica/operativa |
| Rollback de BD | No se intenta automáticamente | Ausente por diseño seguro |
| Auditoría | `admin_audit_logs`, `ikontrol_upgrade_audits`, `instance_update_runs` | Implementado, no totalmente granular |
| Promoción Golden | bandera `is_release_canary` y texto/UI | Sólo informativo |
| Aprobación de release | No hay estado, registro, actor, evidencia ni compuerta | No implementado |
| Procesamiento asíncrono | No se encontraron jobs/colas para despliegues | No implementado |
| Acceso remoto | No se encontró SSH/SFTP/SCP/rsync; opera sobre filesystem accesible | No implementado |

## A.2 Separación actual de los manifests

Hay dos contratos distintos y ambos son necesarios:

1. `updates/{version}/manifest.json`: describe compatibilidad funcional del release (`version`, `channel`, `from_versions`, migrations, commands, health checks y backup requerido).
2. `updates/{version}/deployment-manifest.json`: viaja dentro del ZIP y describe exactamente los archivos desplegables, tamaño, SHA-256 y, opcionalmente, hashes aceptables de origen por versión.

Esta separación es acertada: el primero decide si una instancia puede avanzar; el segundo controla qué bytes se pueden instalar.

## A.3 Persistencia

### `ikontrol_releases`

Contiene versión única, canal, tag, commit, repositorio, hashes/manifests, fechas y validación; posteriormente se añadieron `release_identifier`, `source_ref`, URL/path/SHA-256 del artefacto, estado de verificación y manifest de archivos.

Su `status` actual admite `discovered`, `validated`, `invalid` y `deprecated`. Es un estado de descubrimiento/validación, no una máquina de promoción. `deprecated` existe, pero no hay equivalentes a `BUILT`, `STAGING` o `APPROVED`.

### `ikontrol_instances`

Además de los datos operativos conserva `current_version`, `current_commit_sha`, canal, última actualización, `installation_origin`, `detected_version`, `target_version`, `upgrade_status`, `last_upgrade_audit_at`, estado de DB y baseline, overrides protegidos e `is_release_canary`.

La bandera canary/Golden se puede mostrar y editar, pero no se usa como autorización dentro del servicio de despliegue.

### `instance_update_runs`

Registra origen/destino, release, estado, inicio/fin, backup, log y error; también plan, manifest de backup, staging, rollback y si se requiere revisión de base de datos. Es la entidad correcta para una ejecución de código + upgrade dirigido.

### `ikontrol_upgrade_audits`

Registra planes y evidencias de inspección/adopción/upgrade Spark, con items detallados. Coexiste correctamente con `instance_update_runs`: la primera familia audita el versionador; la segunda, el despliegue completo.

### `admin_audit_logs`

Los controladores registran inicio, final y fallo de las acciones relevantes. No existe aún un historial append-only específico de cada transición de release o de cada paso del deployment.

# B. Archivos y clases involucradas

## B.1 Rutas y controladores

- `routes/web.php`
  - inspección, plan, preparación y ejecución de deployment;
  - plan/ejecución de adopción y upgrade dirigido;
  - catálogo y sincronización de releases;
  - todas las rutas están bajo `auth`; las mutaciones tienen throttling.
- `app/Http/Controllers/Admin/InstanceUpgradeController.php`
  - prepara/muestra/ejecuta deployments;
  - inspecciona, genera plan, adopta y ejecuta upgrade;
  - exige confirmación con slug en ejecuciones modificadoras;
  - comprueba pertenencia de audits/runs a la instancia.
- `app/Http/Controllers/Admin/IkontrolVersionController.php`
  - lista y sincroniza releases, muestra manifest;
  - también conserva flujos históricos de versions/templates.
- `app/Http/Controllers/Admin/InstanceController.php`
  - ficha/listado, inspección general, canal y bandera de validación.
- `app/Http/Controllers/Admin/InstanceOperationController.php`
  - infraestructura operativa histórica; mantiene una operación genérica `migrate` ajena al flujo nuevo.
- `app/Http/Controllers/Admin/InstanceDiagnosticsController.php`
  - diagnóstico y logs; no distribuye releases.

## B.2 Modelos

- `app/Models/IkontrolRelease.php`
- `app/Models/IkontrolInstance.php`
- `app/Models/InstanceUpdateRun.php`
- `app/Models/IkontrolUpgradeAudit.php`
- `app/Models/IkontrolUpgradeAuditItem.php`
- `app/Models/AdminAuditLog.php`

## B.3 Migraciones relacionadas

- `database/migrations/2026_08_31_000005_create_admin_audit_logs_table.php`
- `database/migrations/2026_09_10_000001_add_upgrade_fields_to_ikontrol_instances_table.php`
- `database/migrations/2026_09_10_000002_create_ikontrol_upgrade_audits_table.php`
- `database/migrations/2026_10_01_000001_create_ikontrol_releases_table.php`
- `database/migrations/2026_10_01_000002_add_release_state_to_ikontrol_instances_table.php`
- `database/migrations/2026_10_01_000003_create_instance_update_runs_table.php`
- `database/migrations/2026_10_07_000001_add_canonical_version_and_health_state_to_instances.php`
- `database/migrations/2026_10_08_000001_add_deployment_metadata_to_releases_and_update_runs.php`

No se necesita una migración para documentar esta auditoría. Sí será necesaria para implementar promoción/aprobación con evidencia persistente.

## B.4 Servicios de releases y deployment

- `app/Services/Versioning/GitHubReleaseService.php`
  - consulta GitHub Releases, resuelve commit del tag y descarga el manifest lógico.
- `app/Services/Versioning/ReleaseSyncService.php`
  - sincronización idempotente y selección del asset ZIP esperado.
- `app/Services/Versioning/ReleaseManifestValidator.php`
  - valida esquema, SemVer, canal, listas y ausencia de secretos.
- `app/Services/Versioning/ReleaseCompatibilityService.php`
  - canal, versión superior y `from_versions`.
- `app/Services/Versioning/ReleaseArtifactService.php`
  - descarga/cache, SHA-256, defensa ZIP, validación del deployment manifest y extracción.
- `app/Services/Versioning/DeploymentPlanService.php`
  - compara staging contra instancia y clasifica creaciones, reemplazos, conflictos y protegidos.
- `app/Services/Versioning/StagedFileDeploymentService.php`
  - backup, instalación atómica por archivo y rollback conservador.
- `app/Services/Versioning/ReleaseDeploymentService.php`
  - orquesta preparación, deployment, post-checks, upgrade dirigido y rollback.

## B.5 Inspección, Spark y upgrades

- `app/Services/AllowedSparkRunner.php`
  - usa Symfony Process con argumentos separados, allowlist y validación SemVer.
- `app/Services/Upgrade/InstanceVersionManagementService.php`
  - clasificación canónica, plan, adopción, ejecución y reinspección.
- `app/Services/ManagedCommandJsonProtocol.php`
  - decodifica respuestas JSON administradas.
- `app/Services/Upgrade/InstanceVersionDetectorService.php`
- `app/Services/Upgrade/InstanceUpgradeAuditService.php`
- `app/Services/Upgrade/InstanceUpgradeCompatibilityService.php`
- `app/Services/Upgrade/InstanceUpgradePlanService.php`
- `app/Services/Upgrade/InstanceDatabaseUpgradeAuditor.php`
- `app/Services/Upgrade/InstanceFilesUpgradeAuditor.php`
- `app/Services/Upgrade/InstanceSettingsUpgradeAuditor.php`
- `app/Services/Upgrade/InstanceFiscalCapabilityAuditor.php`

Los últimos servicios forman un auditor histórico/estático adicional. No son el reemplazo del plan Spark que forma parte del deployment de releases.

## B.6 Infraestructura de provisioning reutilizable, pero distinta

- `app/Services/IkontrolDeploymentService.php`
- `app/Services/VersionSourceManager.php`
- `app/Services/VersionSources/VersionSource.php`
- `app/Services/VersionSources/ArchiveVersionSource.php`
- `app/Console/Commands/BuildIkontrolTemplateCommand.php`

Estas piezas despliegan templates/versiones para aprovisionamiento inicial. No generan el artefacto inmutable de una GitHub Release, no implementan la promoción Golden y no deben confundirse con el deployer incremental auditado.

## B.7 Comandos

- `app/Console/Commands/SyncIkontrolReleasesCommand.php`: `php artisan ikontrol:sync-releases`, con salida humana o `--json`.
- `app/Console/Commands/BuildIkontrolTemplateCommand.php`: construye templates de instalación; no es un builder de release de Platform.

No se encontraron comandos de recuperación de un deployment interrumpido, limpieza de staging/backups ni aprobación/promoción.

## B.8 Vistas

- `resources/views/admin/versions/index.blade.php`
- `resources/views/admin/versions/manifest.blade.php`
- `resources/views/admin/instances/index.blade.php`
- `resources/views/admin/instances/show.blade.php`
- `resources/views/admin/instances/deployment-run.blade.php`
- `resources/views/admin/instances/upgrade-audit.blade.php`

Existen listado de releases, manifest, estado de instancia, acciones contextuales, plan de archivos y confirmación por slug. No existe pantalla de build, validación Golden, checklist manual, aprobación, deprecación controlada o historial de promociones.

## B.9 Configuración

- `config/ikontrol.php`
- `.env.example`

Variables relevantes:

- `IKONTROL_INSTANCES_ROOT`
- `IKONTROL_GITHUB_REPOSITORY`
- `IKONTROL_GITHUB_TOKEN`
- `IKONTROL_GITHUB_API_URL`
- `IKONTROL_RELEASE_MANIFEST_MAX_BYTES`
- `IKONTROL_CANONICAL_VERSION`
- `IKONTROL_RELEASE_ARTIFACT_ROOT`
- `IKONTROL_RELEASE_STAGING_ROOT`
- `IKONTROL_RELEASE_BACKUP_ROOT`
- `IKONTROL_RELEASE_MAX_ARCHIVE_BYTES`
- `IKONTROL_RELEASE_MAX_EXTRACTED_BYTES`
- `IKONTROL_RELEASE_MAX_FILES`
- `IKONTROL_PHP_BINARY`
- `IKONTROL_DEPLOYMENT_TIMEOUT`

La lista de rutas protegidas incluye `.env`, `.git`, `writable`, `files`, `uploads`, runtime/logs/cache/sessions y backups; cada instancia puede sumar `deployment_overrides`.

## B.10 Pruebas y documentación

- `tests/Feature/ReleaseCodeDeploymentTest.php`
- `tests/Feature/VersionManagerPhaseOneTest.php`
- `tests/Feature/InstanceVersionManagementTest.php`
- `tests/Feature/IkontrolVersionCatalogTest.php`
- `tests/Unit/VersionAndDeploymentSafetyTest.php`
- `docs/versioning/IKONTROL_RELEASE_CODE_DEPLOYMENT.md`
- `docs/versioning/IKONTROL_VERSION_MANAGER_PHASE1.md`
- `docs/versioning/IKONTROL_INSTANCE_VERSION_ADMIN.md`

Las pruebas cubren ampliamente seguridad de ZIP/path, checksum, archivos protegidos, dry-run, conflictos, rollback, comandos posteriores, baseline independiente, confirmación, canonical release, idempotencia y auditoría. No pueden cubrir promoción/aprobación porque esa capacidad no existe.

## B.11 Integraciones encontradas y ausentes

- cPanel/UAPI existe para aprovisionamiento y bases de datos, pero no participa en la distribución de releases.
- No se encontró una integración SSH, SFTP, SCP o rsync para releases.
- El deployer supone que Admin tiene acceso directo al filesystem de cada instancia bajo `IKONTROL_INSTANCES_ROOT`.
- No se encontraron jobs/colas de despliegue; la preparación y ejecución ocurren sincrónicamente en la petición HTTP.
- No se encontraron scripts auxiliares que construyan el ZIP y el deployment manifest de Platform.

# C. Flujo actual real de actualización

```text
GitHub Release
  -> sync de metadata + manifest lógico
  -> ikontrol_releases(status=validated|invalid)
  -> operador abre una instancia
  -> inspección: version + database-check + baseline-check
  -> compatibilidad release/instancia
  -> Preparar actualización
       -> crea instance_update_run
       -> descarga/carga ZIP fuera de la instancia
       -> verifica SHA-256
       -> valida ZIP y deployment-manifest.json
       -> extrae únicamente archivos declarados
       -> compara hashes contra la instancia
       -> genera DEPLOYMENT_READY, CONFLICT o BLOCKED
  -> operador escribe el slug y confirma
  -> se reconstruye y compara el plan
  -> backup de archivos a reemplazar
  -> instalación temporal + hash + rename por archivo
  -> ikontrol:version --json
  -> ikontrol:database-check
  -> ikontrol:upgrade:plan --target=X --json
  -> si compatible: ikontrol:upgrade --target=X --yes --json
  -> reinspección: version + database-check + baseline-check
  -> éxito sólo si current_version=X y clasificación CURRENT
  -> ante fallo: rollback conservador de código
```

Detalles importantes:

- `baseline-check` se persiste como eje informativo y no invalida por sí mismo una instancia `CURRENT` o `UPDATE_AVAILABLE`.
- El plan de deployment no borra archivos desconocidos.
- La preparación usa el `database_status` persistido de la instancia; no vuelve a ejecutar necesariamente `database-check` en ese momento. Antes del upgrade, después de instalar el código, sí se ejecuta el check.
- La compatibilidad exige release `validated`, canal permitido, upgrade estricto y versión origen incluida en `from_versions`.
- La ejecución vuelve a construir el plan y bloquea si cambian las listas de archivos a crear o reemplazar.
- Si ya se intentó el upgrade de BD y algo falla, se marca revisión manual; no se intenta revertir esquema/datos.
- No existe una transición automática o manual de Golden a `APPROVED`.

# D. Qué puede hacer hoy IKONTROLADMIN

1. Descubrir releases publicadas en GitHub sin tratar cada push a `main` como distribuible.
2. Resolver el tag a un commit SHA exacto.
3. Validar el manifest lógico y rechazar estructura, SemVer, canales o secretos inválidos.
4. Sincronizar releases de forma idempotente.
5. Inspeccionar y clasificar instancias mediante comandos Spark permitidos.
6. Separar salud técnica de baseline/onboarding funcional.
7. Generar planes de upgrade y adopción legacy, registrando auditoría.
8. Seleccionar un asset ZIP con nombre previsto y exigir SHA-256 antes de desplegar.
9. Bloquear traversal, paths absolutos, duplicados, symlinks de ZIP y bombas razonables.
10. Extraer en staging fuera del árbol activo y sólo archivos declarados.
11. Proteger archivos operativos y overrides de instancia.
12. Detectar nuevos, reemplazos, conflictos locales, symlinks y falta de espacio.
13. Respaldar archivos reemplazados con hashes.
14. Instalar de forma atómica por archivo y comprobar cada checksum.
15. Revertir cambios de código ya aplicados cuando la ejecución falla y los hashes siguen siendo seguros.
16. Ejecutar el versionador dirigido posterior al código sin invocar `php spark migrate` desde este flujo.
17. Exigir confirmación escrita con el slug de la instancia.
18. Registrar un run técnico y eventos de auditoría administrativos.

# E. Qué NO puede hacer todavía

1. Construir en Platform un artefacto reproducible y publicar automáticamente su hash/manifest.
2. Demostrar reproducibilidad o procedencia criptográfica del artefacto más allá del SHA-256 recibido.
3. Representar por separado validación técnica y promoción (`BUILT`, `STAGING`, `APPROVED`, `DEPRECATED`).
4. Obligar a que Golden sea el primer destino de una release.
5. Impedir que una release `validated` pero no aprobada se despliegue en Smartfree/Navika.
6. Vincular aprobación con un `instance_update_run` completado sobre Golden, mismo commit y mismo artifact hash.
7. Registrar actor, fecha, checklist y evidencia de validación manual.
8. Ejecutar deployments en cola con locks, reintentos controlados y recuperación tras caída del proceso.
9. Evitar de forma robusta dos preparaciones/ejecuciones concurrentes para la misma instancia/release/artefacto.
10. Reanudar una descarga parcial o una ejecución interrumpida.
11. Gestionar retención/limpieza de artefactos, staging y backups.
12. Ofrecer restauración manual verificada desde UI/CLI.
13. Respaldar la base de datos antes del upgrade o coordinar un mecanismo externo verificable.
14. Revertir cambios de base de datos; correctamente exige revisión manual cuando hubo intento de upgrade.
15. Desplegar a hosts que no compartan filesystem con Admin.
16. Mostrar un historial granular e inmutable de cada etapa y transición.
17. Aplicar roles/permisos finos: las rutas auditadas exigen autenticación, pero no se observó una policy específica para preparar, ejecutar o aprobar.
18. Hacer rollout por cohortes o update-all; esto debe seguir fuera de alcance hasta cerrar las compuertas.

# F. Riesgos actuales

## F.1 Críticos

### `validated` equivale de facto a “desplegable”

El validador prueba que el manifest es correcto, no que la release fue validada en Golden. `ReleaseDeploymentService` y `ReleaseCompatibilityService` aceptan `validated`; no existe `APPROVED`. Ésta es la desviación principal respecto del flujo objetivo.

### Golden no es una compuerta

`is_release_canary` no se aplica en el dominio de despliegue. Marcar una instancia como Golden cambia presentación/metadata, pero no la autorización. Una instancia normal puede convertirse en primer destino.

### Artefacto consumidor sin productor auditado

Admin impone un contrato sólido, pero no existe aquí ni se pudo evidenciar una canalización de Platform que genere exactamente el ZIP y `deployment-manifest.json`. Sin ese productor, la integridad del consumidor no completa la cadena de suministro.

## F.2 Altos

- No hay lock por instancia/release; dos operadores o reintentos HTTP pueden competir.
- El trabajo largo es sincrónico y puede quedar ambiguo por timeout, cierre del navegador o reinicio de PHP.
- El backup es sólo de código reemplazado. No existe backup coordinado de BD antes de ejecutar `ikontrol:upgrade`.
- El estado de rollback exitoso se denomina `CODE_ROLLBACK_AVAILABLE`, aunque el servicio ya realizó el rollback; existe además `rolled_back` en el modelo, pero no es el resultado usado. Esto puede inducir decisiones operativas incorrectas.
- El manifest del backup vive en la base; no hay copia autocontenida junto al directorio de backup para recuperación si Admin/BD no está disponible.
- La preparación usa un `database_status` persistido, que puede estar obsoleto, aunque el post-deploy vuelve a comprobar DB antes del upgrade.
- El servicio de descarga no implementa lock de cache, resume o reintentos; preparaciones simultáneas pueden competir por el mismo `.part`/destino.

## F.3 Medios

- La instalación es atómica por archivo, no por árbol completo; durante una ventana breve puede servirse una mezcla de versiones si hay tráfico.
- No existe maintenance mode/traffic drain coordinado.
- `artifact_verification_status` y el estado del run se sobrescriben; no constituyen por sí solos un journal inmutable de eventos.
- Si la preparación falla después de crear el run, el audit del controlador puede no conservar el `update_run_id` porque la excepción no lo retorna al controlador.
- La comparación previa a ejecutar se centra en listas de creación/reemplazo; falta una huella explícita e inmutable del plan completo con expiración.
- No hay política de permisos/modos/propietario declarada en el manifest; el despliegue controla contenido, no toda la metadata POSIX.
- El flujo genérico histórico de operaciones aún expone una operación `migrate`. El deployer nuevo no la llama, pero conviene separar autorización y UI para evitar confusión operacional.
- Todos los administradores autenticados parecen compartir capacidad de sincronizar/preparar/ejecutar; falta segregación de funciones para aprobar.

## F.4 Deliberadamente seguros

No borrar archivos desconocidos, no usar `git pull`, no ejecutar `php spark migrate` dentro del release deployer y no revertir automáticamente BD son decisiones correctas para esta etapa, no carencias que deban “arreglarse” sin un diseño explícito.

# G. Gap para soportar el flujo objetivo exacto

| Paso objetivo | Estado actual | Gap |
|---|---|---|
| Local/Platform crea release | GitHub Releases se consumen | Falta pipeline/builder reproducible auditado |
| Publicar artefacto | Admin localiza un ZIP nominal | Falta productor, publicación de digest/provenance y prueba end-to-end |
| Registrar release | Existe sync idempotente | Separar validación técnica de promoción |
| Instalar primero en Golden | Puede prepararse en cualquier compatible | Compromiso formal de instancia staging y regla de exclusividad |
| Golden funciona como STAGING | Existe `is_release_canary` | Estado de promoción y vínculo con run completado |
| Validación manual | No existe entidad/acción | Checklist, evidencia, actor, timestamp y confirmación |
| Marcar APPROVED | No existe | Transición explícita autorizada y auditable |
| Habilitar otras instancias | Cualquier `validated` puede desplegarse | Guard obligatorio `APPROVED` para no-Golden |
| Navika legacy | Adopción dirigida existe | Integrarla con la compuerta de release aprobada y preflight de código |
| Smartfree versionada | Upgrade dirigido existe | Aplicar aprobación, locks y operación durable |
| Rollback/recovery | Rollback inmediato de código existe | Corregir semántica, crash recovery y backup de BD/estrategia externa |

La máquina de estados recomendada debe separar dos ejes:

- **Validación técnica:** `discovered`, `validated`, `invalid`.
- **Promoción operativa:** `BUILT`, `STAGING`, `APPROVED`, `DEPRECATED`.

No conviene reutilizar una sola columna para ambos conceptos. Una release puede tener manifest válido y, simultáneamente, no estar aprobada para clientes.

# H. Gap analysis priorizado

## P0 CRÍTICO

1. **Compuerta Golden y lifecycle de promoción.** Impedir por servicio, no sólo por UI, que una release no aprobada llegue a una instancia no-Golden.
2. **Productor reproducible del artefacto.** Implementar en `ikontrol-platform` el builder/CI que construya el ZIP exacto, genere el deployment manifest y publique SHA-256 asociado al commit/tag.
3. **Exclusión mutua e idempotencia operacional.** Lock por instancia y run, protección frente a doble submit y definición de recuperación de estados en progreso.
4. **Autorización de acciones críticas.** Policies/roles separados para preparar, desplegar en Golden y aprobar/promover.

## P1 NECESARIO

1. Job/worker durable para preparación y ejecución, con timeouts y heartbeats.
2. Journal append-only de pasos, artefacto, commit, hashes y transiciones.
3. Corregir semántica `rolled_back`/`CODE_ROLLBACK_AVAILABLE` y agregar recovery CLI verificado.
4. Manifest autocontenido junto al backup y política de retención.
5. Estrategia explícita de backup de BD antes del upgrade, sin asumir rollback automático.
6. Reinspección fresca de DB al preparar/confirmar y huella/expiración del plan.
7. Locks de descarga/cache, retries limitados y limpieza segura de `.part`/staging.
8. UI de validación Golden, aprobación, evidencia, errores por etapa y revisión manual.
9. Pruebas end-to-end con el artefacto realmente producido por Platform.

## P2 MEJORA

1. Firma/provenance del artefacto además de SHA-256.
2. Cohortes y rollout progresivo después de Golden; nunca update-all directo.
3. Métricas, alertas y panel de tiempos/fallos por etapa.
4. Soporte explícito para hosts remotos si dejan de compartir filesystem.
5. Maintenance mode o estrategia de switch de árbol cuando la arquitectura de Platform lo permita.
6. Gestión de ownership/permisos de archivo declarados.

# I. Propuesta por paquetes pequeños y seguros

## DEPLOY-P01 — Lifecycle de promoción y compuerta Golden

**Objetivo**

Separar validación de promoción e imponer el flujo `BUILT -> STAGING -> APPROVED -> DEPRECATED`. Una release no aprobada sólo podrá desplegarse en la instancia staging oficial; una instancia no-Golden sólo aceptará `APPROVED`.

**Archivos a crear/modificar**

- Nueva migración de promotion status y tabla append-only de promociones/validaciones.
- `app/Models/IkontrolRelease.php`
- Nuevo modelo `ReleasePromotion` o equivalente.
- `app/Models/IkontrolInstance.php`
- `app/Services/Versioning/ReleaseDeploymentService.php`
- `app/Services/Versioning/ReleaseCompatibilityService.php`
- Nuevo `ReleasePromotionService`.
- `app/Http/Controllers/Admin/IkontrolVersionController.php`
- `routes/web.php`
- vistas de versiones y deployment.
- `config/ikontrol.php` / `.env.example` para identificar staging oficial de forma estable, preferentemente por ID configurado y verificado, no sólo dominio textual.

**Base de datos**

- `promotion_status` en releases.
- Tabla de transiciones con release, estado anterior/nuevo, actor, `instance_update_run_id`, artifact SHA, commit SHA, evidencia/checklist y timestamps.
- Restricción/validación para una única instancia staging oficial según diseño elegido.

**Tests**

- Release BUILT sólo desplegable en Golden.
- No-Golden rechaza BUILT/STAGING/DEPRECATED.
- Run Golden completado con mismo commit/hash permite pasar a STAGING.
- APPROVED exige confirmación, actor y evidencia manual.
- Sólo APPROVED habilita Smartfree/Navika.
- Cambio del artefacto/commit invalida aprobación.
- Permisos y auditoría de todas las transiciones.

**Criterio de aceptación**

No existe ruta de servicio ni HTTP que permita desplegar una release no `APPROVED` en una instancia distinta del staging oficial; toda aprobación referencia una ejecución Golden completada del mismo artefacto.

**Dependencias**

Ninguna nueva dependencia externa. Reutiliza releases, runs, auth y audit existentes.

## DEPLOY-P02 — Builder reproducible de Platform y contrato de publicación

**Objetivo**

Producir en `ikontrol-platform` el ZIP inmutable esperado por Admin, con archivos permitidos, deployment manifest determinista y SHA-256 publicado.

**Archivos a crear/modificar**

- En el repositorio Platform: script/command de build, definición de exclusiones, generador/validador de manifest y workflow de GitHub Release.
- En Admin: fixtures/validador contractual compartido o pruebas de compatibilidad del artefacto.
- Documentación del contrato y procedimiento de release.

**Base de datos**

Ninguna obligatoria; opcionalmente provenance/signature metadata en una fase posterior.

**Tests**

- Dos builds del mismo commit producen el mismo contenido/hash bajo ambiente fijado.
- Exclusiones de secretos/operativos.
- `vendor` decidido y probado explícitamente.
- Artifact real pasa `ReleaseArtifactService`.
- Modificación de un byte invalida checksum.

**Criterio de aceptación**

Un tag firmado/conocido genera un asset nominal, manifest lógico y deployment manifest que Admin sincroniza y prepara sin intervención manual.

**Dependencias**

Contrato actual de Admin. Requiere trabajo coordinado en `ikontrol-platform`.

## DEPLOY-P03 — Locks, jobs e idempotencia durable

**Objetivo**

Evitar ejecuciones concurrentes y sacar el trabajo largo de la petición HTTP, conservando confirmación y trazabilidad.

**Archivos a crear/modificar**

- Jobs de preparación/ejecución.
- Servicio de lock por instancia/release y token de idempotencia.
- Controlador/UI para estados en cola/en progreso.
- Configuración de queue y timeout.

**Base de datos**

- Campos de heartbeat/idempotency key/lock owner, o tabla de eventos según diseño.

**Tests**

- doble clic/doble job;
- lock expirado;
- retry seguro;
- caída antes/después de cada frontera crítica;
- una sola ejecución de upgrade.

**Criterio de aceptación**

Nunca hay dos ejecuciones activas sobre la misma instancia y un retry no repite una mutación completada.

**Dependencias**

DEPLOY-P01 para aplicar autorización de promoción en el worker.

## DEPLOY-P04 — Recuperación, backups y semántica de rollback

**Objetivo**

Hacer recuperables los runs interrumpidos y eliminar ambigüedad entre “rollback disponible”, “rollback ejecutado” y “revisión manual”.

**Archivos a crear/modificar**

- `StagedFileDeploymentService`, `InstanceUpdateRun`, vistas.
- Comando CLI de inspección/recuperación seguro y dry-run.
- Manifest firmado/hasheado junto al backup.
- Adaptador configurable para backup de BD previo al upgrade.

**Base de datos**

- Estados/transiciones normalizados y metadata del backup DB si aplica.

**Tests**

- crash en cada etapa;
- recuperación idempotente;
- divergencia posterior exige revisión manual;
- backup DB faltante bloquea upgrade cuando la política lo exige.

**Criterio de aceptación**

Cada estado incompleto tiene una acción documentada y verificable; ninguna recuperación pisa cambios posteriores ni simula rollback de BD.

**Dependencias**

DEPLOY-P03 para heartbeats/ownership; puede adelantarse la corrección nominal de estados.

## DEPLOY-P05 — Hardening de artefactos y almacenamiento

**Objetivo**

Robustecer cache, descargas, limpieza, permisos y retención.

**Archivos a crear/modificar**

- `ReleaseArtifactService`, config, comando de garbage collection, métricas.

**Base de datos**

Opcional: tabla histórica de verificaciones/retención.

**Tests**

- descargas concurrentes/parciales;
- retries;
- cleanup sin tocar runs activos;
- límites y modos de archivo;
- cache corrupta.

**Criterio de aceptación**

Una descarga parcial/corrupta nunca se publica como cache válida y la limpieza sólo elimina objetos sin referencias activas.

**Dependencias**

DEPLOY-P02 para probar artefactos reales.

## DEPLOY-P06 — RBAC, UX operativa y evidencia

**Objetivo**

Separar constructor, operador de staging y aprobador; mostrar con claridad promoción, paso fallido, rollback y revisión requerida.

**Archivos a crear/modificar**

- Policies/middleware, controladores, navegación y vistas.
- Admin audit/event journal.

**Base de datos**

Roles/permisos si la infraestructura actual no alcanza; la evidencia base nace en P01.

**Tests**

- matriz de permisos;
- aprobación por persona distinta si se adopta four-eyes;
- redacción de secretos;
- estados y acciones contextuales.

**Criterio de aceptación**

La UI no sólo oculta botones: el backend niega cada acción no autorizada y conserva evidencia de quién hizo qué.

**Dependencias**

DEPLOY-P01.

## DEPLOY-P07 — Rollout progresivo por cohortes

**Objetivo**

Tras APPROVED, habilitar secuencias controladas (por ejemplo Smartfree, Navika, resto) sin implementar update-all ciego.

**Archivos a crear/modificar**

- Entidades de rollout/cohorte, scheduler/operator UI y jobs.

**Base de datos**

- Rollouts, targets, orden, pausa y resultado.

**Tests**

- orden, pausa en primer fallo, exclusión de bloqueadas, reanudación explícita.

**Criterio de aceptación**

Cada instancia mantiene confirmación/plan/run propio y el rollout se detiene de manera predeterminada ante fallo.

**Dependencias**

DEPLOY-P01, P03, P04 y P06.

# J. Primer paquete recomendado

El primer paquete debe ser **DEPLOY-P01 — Lifecycle de promoción y compuerta Golden**.

La razón es de seguridad operacional: la implementación actual ya es capaz de modificar archivos y ejecutar un upgrade dirigido, pero su única barrera de release es `validated`. Antes de conectar un builder real o hacer la primera prueba oficial, debe ser imposible por construcción seleccionar Smartfree, Navika u otra instancia como primer destino. P01 convierte el proceso deseado en una regla de dominio comprobable y auditable.

Después debe ejecutarse DEPLOY-P02 para cerrar la cadena de suministro desde Platform. La primera prueba real con Golden sólo debería ocurrir cuando P01 y P02 estén completos, sus pruebas pasen y exista un procedimiento de recuperación revisado.

## Conclusión

iKontrolAdmin no está empezando desde cero: posee catálogo, validadores, staging, plan, backup de código, reemplazo seguro, ejecución dirigida y rollback conservador. La arquitectura existente debe reutilizarse. La siguiente etapa no debe reescribir ese núcleo; debe colocar encima la máquina de promoción y hacer cumplir Golden/APPROVED en el servicio, y luego alimentar el sistema con un artefacto reproducible producido por Platform.

No debe realizarse una prueba en Golden con el flujo objetivo hasta cerrar al menos esos dos P0 y revisar locks/recuperación para la ventana operativa elegida.
