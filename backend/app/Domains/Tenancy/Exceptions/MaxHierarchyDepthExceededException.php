<?php

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

class MaxHierarchyDepthExceededException extends RuntimeException
{
    public static function forTenant(int $tenantId, int $limit): self
    {
        return new self("Creating this sub-tenant would exceed the maximum allowed hierarchy depth ({$limit}) configured on tenant #{$tenantId}.");
    }

    public static function forPlatform(int $limit): self
    {
        return new self("Creating this sub-tenant would exceed the platform-wide maximum hierarchy depth ({$limit}).");
    }
}
