# Checklist de publicación de Admin: inspector fiscal 1.1.6

Fecha de revisión: 2026-10-26.

Estado: **NO READY PARA DESPLIEGUE AL SERVIDOR** hasta resolver los bloqueos manuales del apartado 10. Esta tarea no realiza commit, push, migraciones productivas ni contacto con instancias.

## 1. Archivos revisados

- `.env.example`
- `app/Http/Controllers/Admin/InstanceController.php`
- `app/Http/Controllers/Admin/InstanceCompatibilityController.php`
- `app/Models/IkontrolInstance.php`
- `app/Models/InstanceCompatibilityEvaluation.php`
- `app/Services/Compatibility/CompatibilityDeploymentGate.php`
- `app/Services/Compatibility/CompatibilityInspectorBundle.php`
- `app/Services/Compatibility/CompatibilityInspectorProcessRunner.php`
- `app/Services/Compatibility/InstanceCompatibilityService.php`
- `app/Services/Compatibility/ReadOnlyDatabaseCredentialVerifier.php`
- `app/Services/Versioning/ReleaseDeploymentService.php`
- `config/ikontrol.php`
- `database/migrations/2026_10_26_000001_create_instance_compatibility_evaluations_table.php`
- `resources/views/admin/instances/show.blade.php`
- `resources/views/admin/instances/compatibility-evaluation.blade.php`
- `routes/web.php`
- `tests/Feature/InstanceCompatibilityInspectorTest.php`
- `docs/operations/FISCAL_116_COMPATIBILITY_INSPECTOR.md`

No se incluyeron archivos de Platform, `.env`, respaldos, credenciales, artefactos fiscales ni rutas locales absolutas.

## 2. Defectos encontrados y corregidos

1. Se eliminó la elevación de confianza mediante configuración; los bundles locales son siempre `DEVELOPMENT_VERIFIED`.
2. La asociación oficial exige ID de release, commit y SHA-256 exactos; la ausencia del commit ya no coincide implícitamente.
3. La compuerta vuelve a evaluar antes de `prepare` y `execute`; no reutiliza evaluaciones históricas y compara snapshots.
4. El bundle vuelve a verificarse dentro del staging para cerrar sustituciones entre verificación y ejecución.
5. Se rechazan symlinks, rutas inseguras y manifests incompletos.
6. La sanitización cubre claves y valores sensibles.
7. La migración falla cerrada si encuentra una tabla parcial.

No se creó otro motor de actualización ni se agregaron reparaciones automáticas.

## 3. Resultado de pruebas

```text
Pruebas focalizadas: 48 PASS, 226 assertions
Suite completa: 258 PASS, 1255 assertions
Lint PHP: PASS en los 14 archivos PHP modificados o nuevos
Compilación Blade: PASS
Rutas del inspector: 2 registradas
git diff --check: PASS
```

La migración se probó en una base SQLite temporal aislada: aplicación completa PASS, estado Ran confirmado y rollback de la migración objetivo confirmado. Laravel advirtió que otras migraciones del mismo batch no estaban en el path al usar rollback filtrado; por ello no debe usarse ese comando de forma automática en producción.

## 4. Migración

La migración sólo crea `instance_compatibility_evaluations`, con claves foráneas a instancias, releases y usuarios; un índice compuesto por instancia/objetivo/fecha e índices individuales de claves foráneas, estado y evidencia; y snapshots de origen, commit y SHA del artefacto. No altera tablas existentes ni datos fiscales.

Si la tabla ya existe, aborta con revisión manual: no completa ni elimina un esquema parcial. Estado: **APROBADA EN BASE AISLADA**, sujeto a comprobar que la tabla no exista y revisar todas las migraciones productivas pendientes.

```bash
php artisan migrate:status
php artisan migrate:status --path=database/migrations/2026_10_26_000001_create_instance_compatibility_evaluations_table.php
```

No ejecutar `migrate --force` hasta revisar la lista completa.

## 5. Variables operativas

```text
IKONTROL_COMPATIBILITY_TIMEOUT
IKONTROL_COMPATIBILITY_DB_USERNAME
IKONTROL_COMPATIBILITY_DB_PASSWORD
IKONTROL_COMPATIBILITY_116_BUNDLE_PATH
IKONTROL_COMPATIBILITY_116_MANIFEST_SHA256
```

Los valores reales deben proceder de cPanel, la cuenta MySQL read-only y el bundle transferido. No hay valores ficticios aceptables. Consultar `docs/operations/FISCAL_116_COMPATIBILITY_INSPECTOR.md`.

## 6. Procedimiento de despliegue de Admin

### Preflight obligatorio

1. Confirmar que cPanel, `realpath .` y la raíz Git coinciden con la ruta productiva declarada: `/home/tws001/admin.ikontrol.solutions`.
2. Publicar primero el commit revisado por fast-forward y anotar su SHA; este checklist no lo publica.
3. Confirmar PHP compatible con Laravel 12 y extensiones.
4. Confirmar árbol limpio, remoto y commit desplegado.
5. Revisar todas las migraciones pendientes.
6. Crear y comprobar la cuenta MySQL exclusivamente de lectura.
7. Transferir el bundle a una ruta privada y recomputar su SHA-256 normalizado.
8. Crear un respaldo consistente de código, base de Admin y configuración desde el mismo punto. Registrar ubicaciones sin registrar secretos.

```bash
pwd
realpath .
test -f artisan
git status --short --branch
git rev-parse HEAD
git remote -v
php -v
php artisan about
php artisan migrate:status
```

Detenerse ante cambios locales, divergencia, migraciones desconocidas, respaldo incompleto o ruta distinta.

### Actualización autorizada posterior

Sustituir `<COMMIT_ADMIN_REVISADO>` sólo por el SHA publicado y confirmado:

```bash
git fetch --prune origin
git merge --ff-only <COMMIT_ADMIN_REVISADO>
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan config:clear
php artisan cache:clear
php artisan migrate:status
```

Volver a revisar todas las pendientes. Sólo con autorización operativa explícita:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Esto actualiza Admin; no debe invocar evaluación ni deployment de Golden, Smartfree o Navika.

## 7. Verificaciones posteriores

```bash
git rev-parse HEAD
git status --short --branch
php artisan migrate:status
php artisan route:list --name=instances.compatibility
php artisan test --filter=InstanceCompatibilityInspectorTest
php artisan about
```

Comprobar que sólo usuarios autorizados ven **Verificar compatibilidad**, sin iniciar evaluaciones reales. La tabla debe existir y estar vacía antes del primer uso autorizado. Una configuración ausente o inválida debe fallar cerrada con `NOT_VERIFIED`.

## 8. Reversión

1. Suspender solicitudes nuevas de Admin.
2. Capturar commit, error y migraciones sin secretos.
3. Restaurar código, `vendor` y configuración del mismo respaldo.
4. Ejecutar `php artisan config:clear` y reconstruir cachés con el código anterior.
5. No revertir automáticamente la tabla aditiva; conservarla evita perder evidencia.
6. Si se autoriza eliminarla, respaldar la base, confirmar código anterior y revisar evaluaciones. Ejecutar sólo la migración objetivo y detenerse si Laravel intenta afectar otras del batch.
7. Verificar Admin, jobs, logs y Git antes de salir de mantenimiento.

No modificar bases, código o configuración de Platform como parte de la reversión.

## 9. Instancias fuera de alcance

Este cambio no ejecuta el inspector ni modifica archivos, bases, versiones o configuración de Golden, Smartfree y Navika/DOLD. Las evaluaciones reales requieren una ventana posterior y autorización separada.

## 10. Estado final y bloqueos

El código podrá publicarse cuando pruebas, lint y `git diff --check` permanezcan en PASS.

El despliegue en servidor permanece bloqueado hasta:

- crear y probar la cuenta MySQL read-only;
- disponer del commit de Admin publicado y confirmado;
- crear respaldo completo y verificable;
- revisar todas las migraciones productivas pendientes;
- transferir y verificar el bundle privado;
- completar valores reales de configuración sin exponer secretos.

Platform 1.1.6 sigue local. Hasta su release oficial, el inspector sólo produce evidencia de desarrollo y nunca autoriza deployments.
