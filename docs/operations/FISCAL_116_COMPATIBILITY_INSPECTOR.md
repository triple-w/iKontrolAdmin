# Inspector fiscal externo 1.1.6

Estado: sólo bundles de desarrollo verificados. Una evaluación de desarrollo no constituye una release oficial ni autoriza un deployment.

## Garantías

Admin acepta únicamente la versión objetivo configurada y, para Smartfree, el parámetro cerrado `invoice_id=1410`. No admite comandos, SQL, rutas, ejecutables ni identificadores arbitrarios desde HTTP.

Antes de ejecutar, verifica el SHA-256 normalizado del manifest, exige `inspect.php`, `InstanceCompatibilityInspector.php` y `contract-1.1.6.json`, rechaza rutas fuera del bundle y symlinks, copia sólo archivos manifestados a un staging privado y vuelve a verificar sus hashes. Ejecuta un vector fijo de argumentos con PHP y elimina el staging al terminar.

No copia archivos a la instancia, no ejecuta Spark, migraciones, PAC o SAT y no cambia versiones. Sólo persiste el contrato sanitizado; no guarda stdout libre, credenciales, XML, certificados, RFC, nombres, tokens ni CSD.

La cuenta MySQL debe ser distinta de la operativa. Admin ejecuta `SHOW GRANTS FOR CURRENT_USER` y sólo acepta `USAGE`, `SELECT` y opcionalmente `SHOW VIEW`, sin `GRANT OPTION`. Si no puede demostrarlo, devuelve `NOT_VERIFIED` sin iniciar el inspector.

## Ruta real del Admin

La ruta productiva confirmada es `/home/tws001/admin.ikontrol.solutions`. Antes del despliegue, comprobar que cPanel Domains/Git Version Control y la terminal resuelven esa misma ubicación:

```bash
pwd
realpath .
test -f artisan
git rev-parse --show-toplevel
git remote -v
git status --short --branch
```

`realpath .` y la raíz Git deben coincidir y contener `artisan`; en caso contrario, detenerse.

## Cuenta read-only y bundle

Crear fuera de Admin una cuenta dedicada y comprobar con ella:

```sql
SHOW GRANTS FOR CURRENT_USER;
```

Cualquier permiso de escritura, administración, ejecución, creación o `GRANT OPTION` bloquea la operación.

Guardar el bundle en una ubicación privada controlada por Admin, nunca bajo `public/`, una instancia o el árbol local de Platform:

```text
<ADMIN_ROOT>/storage/app/compatibility-inspector/bundles/<BUNDLE_ID>
```

Como referencia, usar directorios `0750` y archivos `0640`, ajustados a cPanel. Calcular el hash normalizado después de transferir:

```bash
php -r '$c=file_get_contents($argv[1]);$c=str_replace(["\r\n","\r"],"\n",$c);echo hash("sha256",$c),PHP_EOL;' /ruta/privada/bundle-manifest.json
```

El bundle local revisado produjo `5e0714cddd3c44ba6876323e18e6814710cc0bac5f62d2dc6b06c6cd650e4374`. Es evidencia del fixture de desarrollo, no un hash oficial; debe recomputarse tras la transferencia.

## Variables operativas

```dotenv
IKONTROL_COMPATIBILITY_TIMEOUT=120
IKONTROL_COMPATIBILITY_DB_USERNAME=<usuario_select_only>
IKONTROL_COMPATIBILITY_DB_PASSWORD=<secreto>
IKONTROL_COMPATIBILITY_116_BUNDLE_PATH=<ruta_privada_verificada>
IKONTROL_COMPATIBILITY_116_MANIFEST_SHA256=<sha256_normalizado>
```

No inventar los valores. Validar presencia sin imprimir secretos:

```bash
php artisan config:clear
php artisan tinker --execute="dump((bool) config('ikontrol.compatibility.database.username'), (bool) config('ikontrol.compatibility.database.password'), config('ikontrol.compatibility.targets.1.1.6.bundle_path'), config('ikontrol.compatibility.targets.1.1.6.manifest_sha256'));"
```

## Estados y compuerta

- `COMPATIBLE`: no se detectaron bloqueos con la evidencia disponible.
- `REVIEW_REQUIRED`: exige decisión humana.
- `BLOCKED`: existe una condición incompatible.
- `NOT_VERIFIED`: falta evidencia o una garantía falló; también bloquea.

Una recuperación pendiente añade `ADMIN_DEPLOYMENT_RECOVERY_REQUIRED`. La compuerta vuelve a evaluar antes de preparar y antes de ejecutar; no reutiliza evidencia histórica y compara snapshots del origen y de la release. Una evaluación `DEVELOPMENT_VERIFIED` siempre conserva `authorizes_deployment=false`.

## Verificación y reversión

Sin contactar instancias reales:

```bash
php artisan migrate:status
php artisan route:list --name=instances.compatibility
php artisan test --filter=InstanceCompatibilityInspectorTest
php artisan config:cache
```

Para revertir Admin, restaurar código y dependencias del commit anterior, limpiar configuración y reconstruir cachés. Retirar las variables y el bundle privado sólo tras comprobar las rutas exactas, sin imprimir secretos. Conservar evaluaciones como evidencia. La tabla es aditiva: no revertir su migración automáticamente.

Hasta que 1.1.6 tenga tag, commit y artefacto oficial inmutables, toda evaluación será `DEVELOPMENT_VERIFIED` y no autorizará deployments. La futura asociación oficial deberá demostrar release, commit y SHA-256 desde el staging verificado; nunca elevar confianza por HTTP, configuración o edición manual de base.
