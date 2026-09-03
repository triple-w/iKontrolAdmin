# Despliegue de iKontrol Admin en MochaHost/cPanel

Destino:

- URL: `https://admin.ikontrol.solutions`
- Proyecto: `/home/tws001/ikontroladmin`
- DocumentRoot: `/home/tws001/ikontroladmin/public`
- Base: `tws001_ikontroladmin`
- Usuario MySQL: `tws001`

Los assets de `public/build` están compilados y versionados. El servidor no necesita Node.js ni npm.

## 0. Crear y publicar el repositorio

Desde el equipo local, después de completar las validaciones:

```powershell
cd C:\xampp\htdocs\iKontrolAdmin
git init
git add .
git status
git commit -m "Initial production-ready iKontrol Admin"
git branch -M main
git remote add origin URL_PRIVADA_DEL_REPOSITORIO
git push -u origin main
```

Antes del commit, confirmar en `git status` que aparecen `public/build/manifest.json` y sus assets, pero que no aparecen `.env`, `vendor` ni `node_modules`. El repositorio debe ser privado por contener código comercial de Vuexy.

## 1. Preparar cPanel

1. En **MySQL Databases**, crear `tws001_ikontroladmin`.
2. Asignar el usuario existente `tws001` a la base con **ALL PRIVILEGES**.
3. Crear el subdominio `admin.ikontrol.solutions`.
4. Configurar su DocumentRoot exactamente como `/home/tws001/ikontroladmin/public`.
5. Activar HTTPS para el subdominio antes de habilitar tráfico real.

## 2. Publicar el código

Clonar el repositorio o subir el paquete a `/home/tws001/ikontroladmin`. Confirmar que el despliegue contiene `public/build/manifest.json`, pero no `.env`, `node_modules` ni `vendor` provenientes del equipo local.

Desde la terminal de cPanel:

```bash
cd /home/tws001/ikontroladmin
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env
```

Editar `.env` en el servidor y establecer las credenciales reales únicamente ahí. Mantener como mínimo:

```dotenv
APP_NAME="iKontrol Admin"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://admin.ikontrol.solutions

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=tws001_ikontroladmin
DB_USERNAME=tws001
DB_PASSWORD=PASSWORD_MYSQL_REAL

SESSION_DRIVER=file

IKONTROL_DB_HOST=localhost
IKONTROL_DB_PORT=3306
IKONTROL_DB_USERNAME=tws001
IKONTROL_DB_PASSWORD=PASSWORD_MYSQL_REAL
IKONTROL_DB_PREFIX=tws001_ik_
IKONTROL_INSTANCES_ROOT=/home/tws001

CPANEL_HOST=HOST_REAL_DE_CPANEL
CPANEL_PORT=2083
CPANEL_USERNAME=tws001
CPANEL_API_TOKEN=TOKEN_REAL
```

No colocar estos secretos en Git, documentación, tickets o logs.

## Versiones y provisioning de instalaciones

El administrador no usa DOLD ni ninguna instalación existente como plantilla. Las versiones se registran en `ikontrol_versions` y la primera fuente soportada es `archive`: `source_reference` debe ser el nombre de un `.zip` ubicado dentro de `IKONTROL_VERSION_ARCHIVE_ROOT`. El ZIP debe contener `artisan` en su raíz, no debe contener symlinks ni rutas con traversal y puede verificarse con `checksum` SHA-256.

Todavía falta proporcionar el dato que conecta el código real: la ubicación privada del repositorio limpio o, preferiblemente para este hosting, un archivo ZIP versionado y su checksum SHA-256. No se ha inventado una referencia ni se ha registrado DOLD como versión.

Después de registrar una versión activa/default y revisar el preflight, el wizard permite un dry-run. El dry-run no crea carpeta, base, `.env`, archivos ni ejecuta Artisan. La ejecución real crea los recursos y queda en `READY_FOR_DOMAIN`; el administrador debe crear y apuntar manualmente el subdominio y usar **Confirmar dominio**. Un fallo se conserva en `instance_installation_logs`; **Reintentar** continúa desde el último paso fallido y no elimina la base ni la carpeta.

No ejecutar la primera instalación hasta haber confirmado el ZIP limpio, su checksum, permisos de `IKONTROL_INSTANCES_ROOT` y la estrategia de dependencias. Esta fase no ejecuta `composer install`: el ZIP debe incluir una estrategia de dependencias aprobada (normalmente `vendor` producido fuera del hosting) o se deberá ampliar explícitamente la lista blanca cuando se decida esa política.

## 3. Inicializar Laravel

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan ikontroladmin:create-admin
```

El último comando solicita nombre, email, contraseña y confirmación de forma interactiva. No usar seeders con credenciales fijas.

## 4. Permisos

El usuario de cPanel/PHP debe poder escribir en `storage` y `bootstrap/cache`. En una cuenta cPanel típica:

```bash
chmod -R 775 storage bootstrap/cache
find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

No usar `777`. Si PHP corre con otro usuario/grupo, ajustar propiedad o grupo desde cPanel/soporte antes de ampliar permisos.

## 5. Cachés de producción

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Después de cada cambio de `.env`, ejecutar de nuevo `php artisan config:cache`.

## 6. Comprobación final

```bash
php artisan migrate:status
php artisan about --only=environment,cache,drivers
test -f public/build/manifest.json
```

Verificar en navegador:

1. `https://admin.ikontrol.solutions/login` responde sin errores y carga estilos.
2. `/` redirige al login cuando no hay sesión.
3. El administrador puede iniciar y cerrar sesión.
4. Dashboard, Clientes, Instalaciones, Auditoría y Configuración cargan sin errores.
5. `APP_DEBUG` permanece en `false`.

No probar cPanel ni crear instalaciones reales hasta haber cargado y verificado de forma privada las credenciales de producción.

## Actualizaciones posteriores

```bash
cd /home/tws001/ikontroladmin
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
