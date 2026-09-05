<?php

namespace App\Services\LegacyFc2;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FactucareReaderService
{
    private Connection $db;
    private array $tableCache = [];
    private array $columnCache = [];

    public function __construct(private FactucareConnectionService $connectionStatus) {}

    public function findUserByRfc(string $rfc, string $section = 'summary', ?string $search = null, int $page = 1): array
    {
        $normalized = Str::upper(trim($rfc));
        if ($normalized === '') {
            return ['found' => false, 'rfc' => $normalized, 'message' => 'Escriba un RFC.'];
        }

        try {
            $this->db = DB::connection(config('fc2.connection'));
            if (! $this->hasTable('users_perfil') || ! $this->hasColumn('users_perfil', 'rfc')) {
                return ['found' => false, 'rfc' => $normalized, 'message' => 'El schema FactuCare no contiene users_perfil.rfc.'];
            }
            $relation = $this->userRelationColumn('users_perfil');
            if (! $relation) {
                return ['found' => false, 'rfc' => $normalized, 'message' => 'No fue posible determinar la relación de users_perfil con users.'];
            }
            $profile = $this->db->table('users_perfil')->select([$relation, 'rfc'])->whereRaw('UPPER(TRIM(`rfc`)) = ?', [$normalized])->first();
            if (! $profile) {
                return ['found' => false, 'rfc' => $normalized, 'message' => 'No se encontró un usuario FactuCare con ese RFC.'];
            }

            return $this->readUser((int) $profile->{$relation}, $section, $search, $page, $normalized);
        } catch (Throwable $exception) {
            return ['found' => false, 'rfc' => $normalized, 'status' => 'ERROR', 'message' => $this->connectionStatus->safeError($exception)];
        } finally {
            $this->disconnect();
        }
    }

    public function getUserById(int $userId, string $section = 'summary', ?string $search = null, int $page = 1): array
    {
        try {
            $this->db = DB::connection(config('fc2.connection'));

            return $this->readUser($userId, $section, $search, $page);
        } catch (Throwable $exception) {
            return ['found' => false, 'status' => 'ERROR', 'message' => $this->connectionStatus->safeError($exception)];
        } finally {
            $this->disconnect();
        }
    }

    private function readUser(int $userId, string $section, ?string $search, int $page, ?string $knownRfc = null): array
    {
        if (! $this->hasTable('users') || ! $this->hasColumn('users', 'id')) {
            return ['found' => false, 'message' => 'El schema FactuCare no contiene la tabla users esperada.'];
        }
        $user = $this->safeRow('users', 'id', $userId, [
            'id' => ['id'], 'name' => ['name', 'nombre', 'username'], 'email' => ['email', 'correo'],
            'active' => ['active', 'activo', 'status', 'estado'], 'created_at' => ['created_at', 'fecha_registro', 'date_created'],
            'last_login_at' => ['last_login_at', 'ultimo_acceso', 'last_login'],
        ]);
        if (! $user) {
            return ['found' => false, 'message' => 'El usuario solicitado no existe en FactuCare.'];
        }
        $profile = $this->relatedRow('users_perfil', $userId, [
            'rfc' => ['rfc'], 'legal_name' => ['razon_social', 'legal_name', 'nombre_fiscal'],
            'fiscal_regime' => ['regimen_fiscal', 'regimen_fiscal_id', 'regimen'], 'postal_code' => ['codigo_postal', 'cp'],
            'street' => ['calle'], 'exterior_number' => ['numero_exterior', 'num_ext'], 'interior_number' => ['numero_interior', 'num_int'],
            'neighborhood' => ['colonia'], 'locality' => ['localidad'], 'municipality' => ['municipio'],
            'state' => ['estado'], 'country' => ['pais'], 'phone' => ['telefono', 'phone'], 'fiscal_email' => ['email', 'correo'],
        ]);
        $rfc = Str::upper(trim((string) ($knownRfc ?? $profile['rfc'] ?? '')));
        $summary = $this->summary($userId);
        $result = ['found' => true, 'users_id' => $userId, 'rfc' => $rfc, 'user' => $user, 'profile' => $profile, 'summary' => $summary, 'section' => $section];

        if ($section === 'clients') $result['clients'] = $this->clients($userId, $search, $page);
        if ($section === 'products') $result['products'] = $this->products($userId, $search, $page);
        if ($section === 'csd') $result['csd'] = $this->csd($userId);
        if ($section === 'folios') $result['folios'] = $this->folios($userId);
        if ($section === 'invoices') $result['invoices'] = $this->invoiceStats($userId);
        if ($section === 'other') $result['other'] = $this->otherCounts($userId);

        return $result;
    }

    private function summary(int $userId): array
    {
        $counts = [];
        foreach (['clientes' => 'Clientes', 'productos' => 'Productos', 'folios' => 'Folios', 'facturas' => 'Facturas', 'complementos' => 'Complementos', 'cancelaciones' => 'Cancelaciones', 'timbres_movs' => 'Timbres'] as $table => $label) {
            $count = $this->countRelated($table, $userId);
            if ($count !== null) $counts[$table] = ['label' => $label, 'value' => $count];
        }
        $counts['perfil'] = ['label' => 'Perfil fiscal', 'value' => $this->relatedExists('users_perfil', $userId) ? 'OK' : 'No disponible'];
        $counts['csd'] = ['label' => 'CSD', 'value' => $this->relatedExists('users_info_factura', $userId) ? 'OK' : 'No disponible'];

        return $counts;
    }

    private function clients(int $userId, ?string $search, int $page): ?LengthAwarePaginator
    {
        if (! $this->hasTable('clientes') || ! ($relation = $this->userRelationColumn('clientes'))) return null;
        $map = ['id' => ['id'], 'rfc' => ['rfc'], 'legal_name' => ['razon_social', 'nombre', 'legal_name'], 'contact' => ['contacto', 'nombre_contacto'], 'email' => ['email', 'correo'], 'phone' => ['telefono', 'phone'], 'postal_code' => ['codigo_postal', 'cp'], 'fiscal_regime' => ['regimen_fiscal', 'regimen_fiscal_id'], 'municipality' => ['municipio'], 'state' => ['estado']];
        $query = $this->mappedQuery('clientes', $map)->where("clientes.$relation", $userId);
        if (filled($search)) {
            $columns = array_filter([$this->firstColumn('clientes', ['rfc']), $this->firstColumn('clientes', ['razon_social', 'nombre', 'legal_name'])]);
            $query->where(fn (Builder $nested) => collect($columns)->each(fn ($column, $index) => $index === 0 ? $nested->where($column, 'like', '%'.trim($search).'%') : $nested->orWhere($column, 'like', '%'.trim($search).'%')));
        }

        return $query->orderBy($this->firstColumn('clientes', ['id']) ?? $relation)->paginate(config('fc2.per_page'), ['*'], 'page', $page);
    }

    private function products(int $userId, ?string $search, int $page): ?LengthAwarePaginator
    {
        if (! $this->hasTable('productos') || ! ($relation = $this->userRelationColumn('productos'))) return null;
        $map = ['id' => ['id'], 'code' => ['clave', 'codigo', 'sku'], 'description' => ['descripcion', 'description', 'nombre'], 'price' => ['precio', 'price'], 'unit' => ['unidad'], 'notes' => ['observaciones', 'notas']];
        $query = $this->mappedQuery('productos', $map)->where("productos.$relation", $userId);
        $this->joinSatCatalog($query, 'clave_prod_serv', 'clave_prod_serv_id', 'sat_product_code', 'sat_product_description');
        $this->joinSatCatalog($query, 'clave_unidad', 'clave_unidad_id', 'sat_unit_code', 'sat_unit_description');
        if (filled($search)) {
            $columns = array_filter([$this->firstColumn('productos', ['clave', 'codigo', 'sku']), $this->firstColumn('productos', ['descripcion', 'description', 'nombre'])]);
            $query->where(fn (Builder $nested) => collect($columns)->each(fn ($column, $index) => $index === 0 ? $nested->where("productos.$column", 'like', '%'.trim($search).'%') : $nested->orWhere("productos.$column", 'like', '%'.trim($search).'%')));
        }

        return $query->orderBy('productos.'.($this->firstColumn('productos', ['id']) ?? $relation))->paginate(config('fc2.per_page'), ['*'], 'page', $page);
    }

    private function joinSatCatalog(Builder $query, string $table, string $foreignKey, string $codeAlias, string $descriptionAlias): void
    {
        if (! $this->hasTable($table) || ! $this->hasColumn('productos', $foreignKey) || ! ($id = $this->firstColumn($table, ['id']))) return;
        $query->leftJoin($table, "productos.$foreignKey", '=', "$table.$id");
        if ($code = $this->firstColumn($table, ['clave', 'codigo'])) $query->addSelect("$table.$code as $codeAlias");
        if ($description = $this->firstColumn($table, ['descripcion', 'description'])) $query->addSelect("$table.$description as $descriptionAlias");
    }

    private function csd(int $userId): array
    {
        $info = $this->relatedRow('users_info_factura', $userId, [
            '_legacy_id' => ['id'],
            'certificate_number' => ['numero_certificado', 'no_certificado', 'certificado_numero'],
            'valid_from' => ['fecha_inicio', 'vigencia_inicio'], 'valid_until' => ['fecha_vencimiento', 'vigencia_fin'],
            'status' => ['estado', 'status', 'vigente'],
        ]);
        $infoId = $info['_legacy_id'] ?? null;
        if ($info) unset($info['_legacy_id']);
        $documents = [];
        if ($this->hasTable('users_info_factura_documentos')) {
            $relation = $this->userRelationColumn('users_info_factura_documentos');
            $relationValue = $userId;
            if (! $relation && $infoId && $this->hasColumn('users_info_factura_documentos', 'users_info_factura_id')) {
                $relation = 'users_info_factura_id';
                $relationValue = $infoId;
            }
            if ($relation) {
                $map = ['type' => ['tipo', 'type'], 'filename' => ['nombre_archivo', 'filename', 'nombre'], 'extension' => ['extension']];
                $documents = $this->mappedQuery('users_info_factura_documentos', $map)->where($relation, $relationValue)->limit(20)->get()->map(fn ($row) => (array) $row)->all();
            }
        }
        $types = Str::upper(collect($documents)->pluck('type')->merge(collect($documents)->pluck('filename'))->filter()->implode(' '));

        $hasCer = str_contains($types, 'CER') || $this->relatedNonNull('users_info_factura', $userId, ['archivo_cer', 'cer', 'certificado']);
        $hasKey = str_contains($types, 'KEY') || $this->relatedNonNull('users_info_factura', $userId, ['archivo_key', 'key', 'llave']);

        return ['info' => $info, 'has_cer' => $hasCer, 'has_key' => $hasKey, 'documents' => $documents];
    }

    private function folios(int $userId): array
    {
        if (! $this->hasTable('folios') || ! ($relation = $this->userRelationColumn('folios'))) return [];
        $query = $this->mappedQuery('folios', ['id' => ['id'], 'type' => ['tipo', 'type'], 'series' => ['serie'], 'current_folio' => ['folio', 'folio_actual', 'numero'], 'status' => ['estado', 'activo']]);

        return $query->where($relation, $userId)->limit(100)->get()->map(fn ($row) => (array) $row)->all();
    }

    private function invoiceStats(int $userId): array
    {
        $result = ['total' => null, 'first_date' => null, 'last_date' => null, 'by_status' => [], 'complements' => $this->countRelated('complementos', $userId), 'cancellations' => $this->countRelated('cancelaciones', $userId)];
        if (! $this->hasTable('facturas') || ! ($relation = $this->userRelationColumn('facturas'))) return $result;
        $query = $this->db->table('facturas')->where($relation, $userId);
        $result['total'] = (clone $query)->count();
        if ($date = $this->firstColumn('facturas', ['fecha', 'created_at', 'fecha_emision'])) {
            $result['first_date'] = (clone $query)->min($date);
            $result['last_date'] = (clone $query)->max($date);
        }
        if ($status = $this->firstColumn('facturas', ['estado', 'status'])) {
            $result['by_status'] = (clone $query)->select($status)->selectRaw('COUNT(*) AS total')->groupBy($status)->pluck('total', $status)->all();
        }

        return $result;
    }

    private function otherCounts(int $userId): array
    {
        $result = [];
        foreach (['impuestos', 'cuentas', 'email_personalizados', 'empleados', 'nominas', 'users_pagos_timbres'] as $table) {
            $count = $this->countRelated($table, $userId);
            if ($count !== null) $result[$table] = $count;
        }

        return $result;
    }

    private function mappedQuery(string $table, array $map): Builder
    {
        $query = $this->db->table($table);
        foreach ($map as $alias => $candidates) {
            if ($column = $this->firstColumn($table, $candidates)) $query->addSelect("$table.$column as $alias");
        }

        return $query;
    }

    private function safeRow(string $table, string $key, int $value, array $map): ?array
    {
        if (! $this->hasTable($table) || ! $this->hasColumn($table, $key)) return null;
        $row = $this->mappedQuery($table, $map)->where($key, $value)->first();

        return $row ? (array) $row : null;
    }

    private function relatedRow(string $table, int $userId, array $map): ?array
    {
        if (! $this->hasTable($table) || ! ($relation = $this->userRelationColumn($table))) return null;
        $row = $this->mappedQuery($table, $map)->where($relation, $userId)->first();

        return $row ? (array) $row : null;
    }

    private function countRelated(string $table, int $userId): ?int
    {
        if (! $this->hasTable($table) || ! ($relation = $this->userRelationColumn($table))) return null;

        return $this->db->table($table)->where($relation, $userId)->count();
    }

    private function relatedExists(string $table, int $userId): bool
    {
        return $this->countRelated($table, $userId) > 0;
    }

    private function relatedNonNull(string $table, int $userId, array $candidates): bool
    {
        if (! $this->hasTable($table) || ! ($relation = $this->userRelationColumn($table)) || ! ($column = $this->firstColumn($table, $candidates))) return false;

        return $this->db->table($table)->where($relation, $userId)->whereNotNull($column)->exists();
    }

    private function userRelationColumn(string $table): ?string
    {
        return $this->firstColumn($table, ['users_id', 'id_user', 'user_id']);
    }

    private function firstColumn(string $table, array $candidates): ?string
    {
        $columns = array_flip($this->columns($table));

        return collect($candidates)->first(fn (string $candidate) => isset($columns[$candidate]));
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    private function columns(string $table): array
    {
        if (! $this->hasTable($table)) return [];

        return $this->columnCache[$table] ??= $this->db->getSchemaBuilder()->getColumnListing($table);
    }

    private function hasTable(string $table): bool
    {
        return $this->tableCache[$table] ??= $this->db->getSchemaBuilder()->hasTable($table);
    }

    private function disconnect(): void
    {
        $name = config('fc2.connection');
        if (config("database.connections.$name.driver") !== 'sqlite') DB::disconnect($name);
    }
}
