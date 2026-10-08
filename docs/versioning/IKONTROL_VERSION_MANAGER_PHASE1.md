# iKontrol Version Manager — Fase 1

## Alcance

Esta fase implementa discovery, registro, manifests, compatibilidad y UI. No despliega archivos, no ejecuta migraciones o comandos remotos, no crea backups y no hace rollback.

## Arquitectura existente reutilizada

IKONTROLADMIN es Laravel 12 con vistas Blade/Vuexy y autenticación `auth`. La auditoría previa identificó:

- clientes: `Client`, `ClientController` y tabla `clients`;
- instancias, dominios y bases: `IkontrolInstance`, `InstanceController` y tabla `ikontrol_instances`;
- provisioning: `InstanceProvisioningService`, `ProvisioningController` y `InstanceInstallationLog`;
- cPanel UAPI: `CpanelService`;
- archivos y ejecución controlada: `IkontrolDeploymentService`, `InstanceFilesystemService`, `AllowedArtisanRunner`, `AllowedSparkRunner` y `ManagedCommandJsonProtocol`;
- inspección, diagnósticos y logs: servicios `IkontrolInstance*`, `Instance*Diagnostic*`, `InstanceLogService` y sus snapshots;
- catálogo anterior para instalaciones nuevas: `ikontrol_versions` y `ikontrol_templates`;
- auditoría administrativa: `AuditService` y `admin_audit_logs`;
- UI: rutas autenticadas, controladores Admin, Blade y menú Vuexy.

El registro de releases es una capa nueva y separada del catálogo de paquetes. No sustituye ni invoca el provisioning/despliegue existente en esta fase.

## Tablas

### `ikontrol_releases`

Catálogo idempotente de GitHub Releases. `version` y `git_tag` son únicos. Guarda `channel`, commit, repositorio, SHA-256 y JSON del manifest, errores de validación, fechas y estado (`discovered`, `validated`, `invalid`, `deprecated`). Nunca guarda el token de GitHub.

### Campos nuevos en `ikontrol_instances`

- `current_version`
- `current_commit_sha`
- `update_channel` (`stable` por defecto o `canary`)
- `last_update_at`
- `last_update_status`

La migración inicializa `current_version` con el primer valor disponible entre `installed_version`, `detected_version` y `app_version`. Los campos anteriores se conservan por compatibilidad.

### `instance_update_runs`

Registro preparado para ejecuciones futuras: instancia, versiones origen/destino, release, estado, fechas, referencia de backup, log y error. Los estados previstos son `pending`, `preflight`, `backup`, `deploying`, `migrating`, `commands`, `health_check`, `completed`, `failed` y `rolled_back`. Fase 1 no crea ejecuciones automáticamente.

## GitHub Releases

`GitHubReleaseService` consulta exclusivamente el repositorio configurado, ignora drafts y usa GitHub Releases como unidad distribuible. Para cada release obtiene tag, fecha, indicador prerelease, commit SHA y `updates/{version}/manifest.json` desde el mismo tag. Un push a `main` no se importa.

La sincronización se ofrece en la UI y con:

```bash
php artisan ikontrol:sync-releases
php artisan ikontrol:sync-releases --json
```

La operación usa `version` como clave idempotente: crea registros nuevos, actualiza metadata modificada y no duplica al ejecutarse otra vez. Un manifest descargado recibe SHA-256. Los fallos por release se registran como `invalid`; un fallo global de conectividad devuelve error seguro sin incluir credenciales.

## Contrato del manifest

Ubicación en `ikontrol-platform`:

```text
updates/{version}/manifest.json
```

Esquema 1 admite únicamente los campos del contrato. Se valida JSON, tamaño, producto `ikontrol`, SemVer, canal, tipos, duplicados, IDs de migración y nombres de comandos sin argumentos de shell. Se rechazan campos adicionales y nombres de campos que indiquen passwords, tokens, secrets, credenciales o llaves privadas/API.

Archivo pendiente en el repositorio separado `C:\xampp\htdocs\ikontrol-platform\updates\1.0.1-rc.1\manifest.json`:

```json
{
  "schema_version": 1,
  "product": "ikontrol",
  "version": "1.0.1-rc.1",
  "channel": "canary",
  "from_versions": [
    "1.0.0"
  ],
  "migrations": [
    {
      "id": "2026-10-01-120000_AddSatCatalogInfrastructure",
      "required": true
    }
  ],
  "commands": [],
  "health_checks": [
    "ikontrol:baseline-check"
  ],
  "requires_backup": true
}
```

Después debe publicarse un GitHub Release con tag `v1.0.1-rc.1` (o `1.0.1-rc.1`) marcado como prerelease. El archivo y la migración referenciada deben formar parte de ese tag. IKONTROLADMIN no modifica el repositorio platform.

## Canales y compatibilidad

La regla `isCompatible(instance, release)` exige:

1. release validada;
2. versión actual conocida y presente exactamente en `from_versions`;
3. destino mayor que origen según SemVer (sin misma versión ni downgrade);
4. instancia stable sólo ve releases stable;
5. instancia canary ve releases canary y stable.

No se calculan rutas de múltiples saltos.

## UI y permisos

El menú autenticado incorpora `iKontrol → Versiones iKontrol`. La pantalla lista releases, permite sincronizar, ver manifest y compatibilidad, y conserva debajo el catálogo anterior de paquetes de instalación. El botón de actualización está deshabilitado y marcado “Fase 2”.

La ficha de instancia muestra dominio, versión, commit, canal, última actualización, estado y release compatible disponible. El selector de canal usa las mismas restricciones `auth` existentes, validación de allowlist y auditoría administrativa.

## Seguridad

- repositorio, tag y versión se validan antes de formar URLs;
- los manifests no controlan rutas ni forman comandos shell;
- `commands` y `health_checks` sólo admiten identificadores, no argumentos ni metacaracteres;
- el token se obtiene de configuración, sólo se añade al header HTTP y no se serializa;
- controladores y CLI devuelven errores genéricos para fallos globales;
- los errores persistidos se sanitizan;
- cPanel, DB, SSH y otros secretos no forman parte de las tablas nuevas ni del manifest.

## Variables de entorno

```dotenv
IKONTROL_GITHUB_REPOSITORY=triple-w/ikontrol-platform
IKONTROL_GITHUB_TOKEN=
IKONTROL_GITHUB_API_URL=https://api.github.com
IKONTROL_RELEASE_MANIFEST_MAX_BYTES=262144
```

El token es opcional para un repositorio público, pero recomendable por límites de API. Para un repositorio privado debe ser un token de sólo lectura con acceso mínimo a Contents/Metadata. No escribirlo en logs ni en manifests.

## Nuevas instalaciones

La dirección futura es provisionar desde una release estable de `ikontrol-platform`: baseline limpio, migraciones requeridas, seeders, catálogos SAT, onboarding, módulos y health check. La base de `ikontrol.ikontrol.solutions` es una instancia canary/productiva y nunca debe copiarse como plantilla de clientes.

## Limitaciones y fases siguientes

Fase 2 implementará preflight, backup real, descarga/verificación de artefactos, deploy, migraciones/comandos allowlisted, health check y rollback, con confirmaciones y auditoría. Fase 3 agregará un grafo de upgrade paths y selección segura de rutas multiversión.

Hasta entonces no se actualiza `ikontrol.ikontrol.solutions`, DOLD ni ninguna otra instancia; tampoco se ejecutan comandos, migraciones o cambios de archivos remotos.
