<?php

namespace App\Services\Versioning;

use App\Models\IkontrolInstance;
use RuntimeException;

final class GoldenInstanceResolver
{
    public function id(): int
    {
        $configured = config('ikontrol.release_deployment.golden_instance_id');
        if (! is_int($configured) && ! (is_string($configured) && preg_match('/\A[1-9]\d*\z/', $configured))) {
            throw new RuntimeException('IKONTROL_GOLDEN_INSTANCE_ID no está configurado correctamente.');
        }

        $id = (int) $configured;
        if ($id < 1) throw new RuntimeException('IKONTROL_GOLDEN_INSTANCE_ID no está configurado correctamente.');

        return $id;
    }

    public function resolve(): IkontrolInstance
    {
        $instance = IkontrolInstance::find($this->id());
        if (! $instance) throw new RuntimeException('La instancia Golden configurada no existe.');

        return $instance;
    }

    public function isGolden(IkontrolInstance $instance): bool
    {
        return $this->resolve()->is($instance);
    }
}
