# Administración dirigida de versiones de instancias

## Auditoría del sistema anterior

IKONTROLADMIN ya disponía de modelos de instancias, `InstanceController`, `InstanceUpgradeController`, diagnósticos, `AllowedSparkRunner`, `IkontrolDeploymentService`, auditoría administrativa y las tablas `ikontrol_upgrade_audits` / `ikontrol_upgrade_audit_items`.

El flujo anterior detectaba versiones desde archivos locales y auditaba archivos, settings y esquema mediante conexión directa de sólo lectura. Generaba un plan local, pero no invocaba `ikontrol:upgrade:plan`, no ejecutaba `ikontrol:upgrade`, no soportaba `adopt-baseline` y no reinspeccionaba después de una modificación. Se conserva como auditoría histórica, pero el flujo dirigido nuevo no lo usa para aplicar cambios.

Las rutas de auditoría sí existían. En Windows, `grep` normalmente no está disponible; la comprobación equivalente es:

```powershell
php artisan route:list | findstr /i "instance upgrade diagnostic version"
```

Si el servidor usa route cache, después de desplegar debe reconstruirse con `php artisan route:clear` y `php artisan route:cache`.

## Estado canónico

La versión canónica se configura con `IKONTROL_CANONICAL_VERSION` y actualmente es `1.1.4`. La instancia conserva:

- `detected_version`
- `canonical_version`
- `target_version`
- `installation_origin`
- `database_status`
- `baseline_status`
- `upgrade_status`
- `last_upgrade_audit_at`

Estados:

- `CURRENT`: versión detectada igual a la canónica, database-check y baseline listos.
- `UPDATE_AVAILABLE`: versión detectada menor que la canónica y checks listos.
- `LEGACY_ADOPTABLE`: `current_version` nula, database-check listo y dry-run de adopción compatible.
- `BLOCKED`: fallo de DB/baseline, plan incompatible, versión superior a la canónica o ejecución no confirmada por reinspección.
- `UNREACHABLE`: no fue posible ejecutar/decodificar `ikontrol:version`.

## Comandos Spark

El runner utiliza `Symfony Process` con un array de argumentos, nunca una cadena de shell. Las operaciones nuevas tienen métodos dedicados y validan SemVer antes de construir `--target`:

```text
ikontrol:version --json
ikontrol:database-check
ikontrol:baseline-check
ikontrol:upgrade:plan --target={semver} --json
ikontrol:upgrade --target={semver} --yes --json
ikontrol:adopt-baseline --json
ikontrol:adopt-baseline --execute --yes --json
```

El flujo de upgrade nunca llama `php spark migrate`. Los comandos operacionales anteriores permanecen allowlisted para provisioning y diagnósticos existentes; cualquier nombre/argumentos no contemplados se rechaza.

## Flujo

### Inspección

Admin ejecuta `version`, `database-check` y `baseline-check`. Para una versión nula también ejecuta el dry-run de `adopt-baseline`. Sólo actualiza metadata local y registra `INSTANCE_VERSION_INSPECTED`.

### Actualización

1. Una instancia `UPDATE_AVAILABLE` solicita `upgrade:plan` hacia la versión canónica.
2. La respuesta se almacena en `ikontrol_upgrade_audits`.
3. Sólo un plan `PLAN_READY` / `COMPATIBLE` muestra el formulario de actualización.
4. El administrador escribe el slug exacto de la instancia.
5. Admin registra el inicio y vuelve a ejecutar el plan.
6. Si continúa compatible, ejecuta `ikontrol:upgrade --yes`.
7. Repite version, database-check y baseline-check.
8. Sólo queda `CURRENT` si la reinspección confirma la versión canónica; en otro caso queda `BLOCKED`.

### Adopción legacy

1. La inspección clasifica `LEGACY_ADOPTABLE` únicamente tras un dry-run compatible.
2. “Evaluar adopción” guarda otra evidencia dry-run en `ikontrol_upgrade_audits`.
3. Sólo `ADOPTION_READY` muestra la confirmación por slug.
4. Antes de ejecutar se repite el dry-run.
5. Se ejecuta `adopt-baseline --execute --yes` y se reinspecciona.
6. La adopción no actualiza automáticamente; normalmente la instancia pasa a `UPDATE_AVAILABLE` y requiere un plan de upgrade separado.

## Seguridad

- No se ejecutan comandos shell construidos con input web.
- El target web no se acepta: siempre proviene de la versión canónica configurada.
- Se valida SemVer de nuevo dentro del runner.
- El path debe resolver dentro de `IKONTROL_INSTANCES_ROOT` y no puede ser symlink.
- La pertenencia de cada auditoría a su instancia se valida.
- Upgrade y adopción requieren confirmación exacta por slug.
- Inicio, final y fallo de operaciones modificadoras se guardan en `admin_audit_logs`.
- No se invoca migrate, git reset, borrado, PAC, timbrado ni cancelación CFDI.

## Despliegue

Después de incorporar estos cambios al artefacto/branch que se desplegará:

```bash
cd /ruta/absoluta/de/iKontrolAdmin
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan down
php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
php artisan route:list | grep -Ei "instance|upgrade|diagnostic|version"
```

Configurar antes de reconstruir caché:

```dotenv
IKONTROL_CANONICAL_VERSION=1.1.4
IKONTROL_INSTANCES_ROOT=/home/tws001
IKONTROL_PHP_BINARY=/usr/local/bin/php
```

La ruta de PHP debe ajustarse al binario real de cPanel. No ejecutar ninguna acción de upgrade hasta verificar en la UI que Golden aparece `CURRENT`, Smartfree `UPDATE_AVAILABLE` si corresponde y Navika se limita inicialmente a dry-run de adopción.
