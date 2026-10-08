# Despliegue seguro de código de iKontrol Platform

## Arquitectura reutilizada

La implementación extiende `ikontrol_releases`, `instance_update_runs`, `InstanceVersionManagementService`, `AllowedSparkRunner`, las rutas autenticadas y `admin_audit_logs`. `IkontrolDeploymentService` y `ArchiveVersionSource` siguen atendiendo instalaciones nuevas; su extracción directa no sirve para upgrades porque exige una carpeta vacía y no ofrece reemplazo/rollback de instancias existentes.

Cadena: GitHub Release validada → asset ZIP inmutable → SHA-256 publicado → staging administrado → manifest de archivos → dry-run → backup selectivo → reemplazo por archivo → `ikontrol:version` → database-check → upgrade plan → upgrade dirigido → reinspección. No existe `git pull`, `php spark migrate`, borrado de archivos desconocidos ni update-all.

## Contrato del artefacto

El GitHub Release debe tener tag SemVer (`v1.1.5` o `1.1.5`), nombre `ikontrol-1.1.5-canonical-stamp-wallet-resolution` y un asset llamado exactamente `ikontrol-platform-1.1.5.zip` (también se acepta `ikontrol-1.1.5.zip`). Admin registra el commit exacto resuelto desde el tag, la URL del asset y el digest `sha256:` de GitHub. Sin SHA-256 no se permite preparar el despliegue.

El ZIP debe contener `updates/1.1.5/deployment-manifest.json`; puede tener un único directorio raíz. Contrato:

```json
{
  "schema_version": 1,
  "product": "ikontrol",
  "version": "1.1.5",
  "release_id": "ikontrol-1.1.5-canonical-stamp-wallet-resolution",
  "commit_sha": "SHA_COMPLETO_DE_40_CARACTERES",
  "files": [
    {"path": "app/Commands/IkontrolVersion.php", "sha256": "SHA256_DEL_ARCHIVO", "size": 1234, "from_sha256": {"1.1.4": "SHA256_EN_1.1.4"}}
  ]
}
```

Cada archivo administrado aparece con path relativo, tamaño y SHA-256. Para archivos reemplazables, `from_sha256` declara el hash esperado en cada `from_version`; sirve como baseline verificable cuando Admin aún no materializó el artefacto anterior. El manifest incluye como mínimo `index.php` y `spark`. Platform no tiene `composer.json` ni `vendor/` raíz: se distribuyen `system/` y las dependencias versionadas bajo `app/ThirdParty/`; Admin no ejecuta Composer en clientes.

## Política de archivos

Nunca se admiten ni modifican `.env`, `.git/`, `writable/`, `files/`, `uploads/`, `storage/runtime`, `storage/logs`, `storage/framework`, `logs`, `cache`, `sessions` o `backups`. `deployment_overrides` agrega exclusiones por instancia. Esto protege CSD, llaves privadas, PAC, timbres, documentos, branding y estado operativo.

No se eliminan archivos desconocidos. Un archivo nuevo se crea; uno existente sólo se reemplaza si su SHA actual coincide con el SHA de la release base registrada. Si no existe manifest base o el hash difiere, se reporta `LOCAL_MODIFICATION` y el plan queda `DEPLOYMENT_CONFLICT`.

## Staging, backup y seguridad

Se rechazan paths absolutos, `..`, NUL, drive letters, symlinks, duplicados, demasiadas entradas y archives sobredimensionados. Staging y backups están fuera de `IKONTROL_INSTANCES_ROOT`. La instancia debe resolver dentro de esa raíz y no ser symlink.

El dry-run sólo escribe staging y auditoría de Admin, nunca la instancia. Antes del deploy se repite el plan. Cada reemplazo se copia a un temporal junto al destino, se verifica y se activa mediante rename. Los archivos reemplazados se respaldan con checksum antes de modificar.

Ante fallo se restauran sólo archivos cuyo checksum continúa siendo el desplegado y se eliminan sólo archivos creados por esa ejecución que conservan su checksum. Una divergencia produce `MANUAL_REVIEW_REQUIRED`. Si ya se invocó `ikontrol:upgrade`, también se marca `database_review_required`; nunca se revierte esquema o datos automáticamente.

## Estados

- `DEPLOYMENT_READY`: dry-run sin conflictos y espacio suficiente.
- `DEPLOYMENT_CONFLICT`: modificación local administrada.
- `DEPLOYMENT_BLOCKED`: archivo protegido, falta de espacio o condición estructural.
- `failed`: fallo anterior a cambios potenciales de base y rollback verificable.
- `MANUAL_REVIEW_REQUIRED`: rollback no garantizable o upgrade dirigido ya iniciado.
- `completed`: código, upgrade y reinspección confirmaron el destino.

Baseline es informativo: `FAIL` no invalida el éxito si `current_version` es `1.1.5` y database-check está `READY`.

## Primera validación con Golden

1. Publicar el asset ZIP `1.1.5` con manifest y confirmar el digest SHA-256 en la API del release.
2. Configurar las variables y desplegar/migrar solamente iKontrolAdmin.
3. Ejecutar `php artisan ikontrol:sync-releases --json`; no contacta instancias.
4. En Versiones comprobar release ID, commit, `validated` y SHA presente.
5. Marcar Golden como canary (`is_release_canary`) y confirmar versión `1.1.4` y DB `READY`.
6. Pulsar **Preparar actualización** y revisar archivos, conflictos, espacio, commit y release. Debe quedar `DEPLOYMENT_READY`.
7. Con ventana de mantenimiento y autorización, escribir el slug exacto y pulsar **Actualizar a 1.1.5**.
8. Confirmar `completed`, versión `1.1.5`, commit esperado y DB `READY`. Baseline incompleto sólo genera advertencia.
9. Validar funcionalmente Golden antes de habilitar otra instancia. No existe update-all.

La ejecución real de los pasos 7–9 queda fuera de esta entrega.

## Variables

```dotenv
IKONTROL_CANONICAL_VERSION=1.1.5
IKONTROL_RELEASE_ARTIFACT_ROOT=/home/tws001/ikontrol-release-artifacts
IKONTROL_RELEASE_STAGING_ROOT=/home/tws001/ikontrol-release-staging
IKONTROL_RELEASE_BACKUP_ROOT=/home/tws001/ikontrol-release-backups
IKONTROL_RELEASE_MAX_ARCHIVE_BYTES=536870912
IKONTROL_RELEASE_MAX_EXTRACTED_BYTES=1073741824
IKONTROL_RELEASE_MAX_FILES=50000
```

Los tres directorios deben ser privados, escribibles por Admin y estar fuera de instancias. El token GitHub conserva acceso mínimo de lectura y nunca se persiste.

## Riesgos pendientes

- Platform debe producir y adjuntar ZIP/manifest. El checkout local auditado aún no contiene `updates/1.1.5` y corresponde a un commit anterior; no se inventó el SHA real.
- La activación es atómica por archivo, no para todo el árbol; hay una ventana breve con archivos de ambas versiones.
- Staging y backups requieren una política futura de retención, nunca borrado implícito.
- Un fallo después de iniciar `ikontrol:upgrade` exige revisión manual de base.
