# Recuperación controlada de Golden 1.1.5

## Alcance y estado conocido

Procedimiento exclusivo para Golden ID `2`, deployment ID `1`, Platform en `/home/tws001/ikontrol.ikontrol.solutions` y Admin en `/home/tws001/admin.ikontrol.solutions`.

Estado observado: código `1.1.4`, versión registrada en BD `1.1.5`, deployment `MANUAL_REVIEW_REQUIRED`. Los 14 reemplazables coinciden con el origen; los tres archivos nuevos deben comprobarse individualmente.

No eliminar filas de `app_schema_versions`, revertir migraciones, copiar código ni marcar el run como completado durante el diagnóstico.

## 1. Capturar evidencia

Desde Admin:

```bash
cd /home/tws001/admin.ikontrol.solutions
php artisan about
php artisan migrate:status
php artisan tinker --execute='$r=App\Models\InstanceUpdateRun::findOrFail(1); echo json_encode($r->only(["id","instance_id","status","from_version","to_version","release_id","release_commit_sha","release_artifact_sha256","deployment_plan","backup_reference","backup_manifest","staging_reference","rollback_status","database_review_required","log","error_message"]), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;'
```

Detenerse si el run no pertenece a instancia 2, no apunta de 1.1.4 a 1.1.5 o no contiene:

- commit `3c4a65d00d833743378ce526da7e5891da7509f9`;
- artifact SHA-256 `e2d6763291c341c75ad8d09f901d66e7f545269c52a28e8a0a54033e565245f6`.

Desde Golden, sólo lectura:

```bash
cd /home/tws001/ikontrol.ikontrol.solutions
php spark ikontrol:version --json
php spark ikontrol:database-check
php spark ikontrol:upgrade:plan --target=1.1.5 --json
php spark migrate:status
sha256sum app/Config/Version.php
```

Detenerse si no se reproduce código 1.1.4 / BD 1.1.5 o si `database-check` deja de ser `READY`.

## 2. Determinar el alcance real de la BD

La fila 1.1.5 de `app_schema_versions` no demuestra que no existan otros cambios.

1. Obtener del run el `staging_reference` y validar que esté dentro de la raíz administrada.
2. Revisar allí migraciones y paquete de upgrade 1.1.5.
3. Comparar migraciones, esquema y datos declarados mutables contra el respaldo completo inmediatamente anterior al run 1.
4. Registrar cada diferencia.

Detenerse si falta el respaldo, no puede explicarse cada cambio, existen cambios fiscales o una transformación no tiene reversión demostrada.

## 3. Estrategia de recuperación

### Preferida: restaurar el estado origen

Usar únicamente si la comparación demuestra que la BD puede restablecerse exactamente a 1.1.4 mediante el respaldo previo, sin pérdida de datos.

- Restaurar con el mecanismo de respaldo aprobado y ventana de mantenimiento.
- No eliminar manualmente una fila de `app_schema_versions`.
- No improvisar SQL: el comando depende del respaldo y del delta que debe demostrarse primero.
- Después, ambos valores de `ikontrol:version --json` deben ser 1.1.4.

En la pantalla del deployment 1 seleccionar **Origen 1.1.4**, escribir el slug real y pulsar **Verificar y desbloquear**. La acción no modifica Platform: exige código y BD 1.1.4, hashes originales para reemplazados y ausencia real de cada archivo nuevo. Debe terminar en `RECOVERY_VERIFIED_SOURCE`.

### Alternativa: roll-forward controlado

Si existen cambios no reversibles o no hay prueba suficiente para volver a 1.1.4, no tocar la BD. Instalar únicamente los archivos administrados del artefacto 1.1.5 usando el manifest y staging conservados.

Antes de copiar:

- verificar nuevamente el SHA-256 del ZIP;
- verificar cada archivo del staging contra el deployment manifest;
- confirmar los 14 reemplazables en hash origen;
- comprobar por separado los tres archivos nuevos;
- ejecutar primero el dry-run de la herramienta de copia aprobada.

Después, código y BD deben declarar 1.1.5, `database-check` debe ser `READY` y el plan no debe tener paquetes pendientes. Seleccionar **Destino 1.1.5** en **Verificar y desbloquear**. Debe terminar en `RECOVERY_VERIFIED_TARGET`.

Esta alternativa recupera coherencia, pero no convierte el run fallido en un run Golden exitoso ni autoriza promoción. Para promover hará falta una atestación Golden aprobada o repetir desde un origen reproducible.

## 4. Reprobar 1.1.5

La estrategia de origen permite repetir el flujo normal:

1. Confirmar `RECOVERY_VERIFIED_SOURCE`.
2. Reinspeccionar Golden: código 1.1.4, BD 1.1.4, DB READY.
3. Confirmar commit y SHA-256 oficiales de 1.1.5.
4. Preparar un run nuevo.
5. Exigir `DEPLOYMENT_READY`, sin protegidos ni conflictos.
6. Ejecutar el run.
7. Exigir código 1.1.5, BD 1.1.5, hashes destino y estado `completed`.
8. Sólo entonces promover a STAGING.

El run 1 permanece intacto como evidencia histórica.

## Criterios de parada

Detenerse si hay commit/SHA distintos, versión inesperada, hash desconocido, archivo nuevo presente al validar origen, backup o staging fuera de raíz, rollback incompleto, cambio de BD no explicado, ausencia de respaldo, salida Spark inválida, timeout o exit code distinto de cero.
