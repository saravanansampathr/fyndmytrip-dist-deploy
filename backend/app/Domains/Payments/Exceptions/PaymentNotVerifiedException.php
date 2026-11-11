<?php

namespace App\Domains\Payments\Exceptions;

use RuntimeException;

/** The gateway hasn't confirmed the payment; nothing is booked or debited. */
class PaymentNotVerifiedException extends RuntimeException
{
    public function render()
    {
        return response()->json(['message' => $this->getMessage(), 'payment_not_verified' => true], 402);
    }
}
