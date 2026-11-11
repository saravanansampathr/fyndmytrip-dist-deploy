<?php

namespace App\Domains\Wallet\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public static function forWallet(int $walletId, string $requested, string $available): self
    {
        return new self("Wallet #{$walletId} has insufficient balance: requested {$requested}, available {$available}.");
    }
}
