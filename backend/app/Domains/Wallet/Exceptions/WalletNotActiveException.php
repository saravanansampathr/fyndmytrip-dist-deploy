<?php

namespace App\Domains\Wallet\Exceptions;

use RuntimeException;

class WalletNotActiveException extends RuntimeException
{
    public static function forWallet(int $walletId, string $status): self
    {
        return new self("Wallet #{$walletId} is {$status} and cannot post new ledger entries.");
    }
}
