<?php

namespace App\Domains\Suppliers\Exceptions;

use RuntimeException;

class SupplierException extends RuntimeException
{
    public function __construct(public readonly string $supplierCode, string $message)
    {
        parent::__construct($message);
    }
}
