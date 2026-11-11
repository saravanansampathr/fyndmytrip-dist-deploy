<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\GstService;
use Tests\TestCase;

class GstInvoiceTest extends TestCase
{
    public function test_same_state_splits_cgst_and_sgst(): void
    {
        $g = app(GstService::class)->breakdown(Booking::MODULE_HOTEL, 180.0, '33AABCF1234K1Z5', '33AAACX9999L1Z2');
        $this->assertSame(['CGST (9%)', 'SGST (9%)'], array_column($g['lines'], 'label'));
        $this->assertSame(180.0, array_sum(array_column($g['lines'], 'amount')));
        $this->assertSame('998552', $g['sac']);
        $this->assertSame('33 - Tamil Nadu', $g['place_of_supply']);
    }

    public function test_other_state_buyer_pays_igst(): void
    {
        $g = app(GstService::class)->breakdown(Booking::MODULE_FLIGHT, 99.99, '33AABCF1234K1Z5', '27AAACX9999L1Z2');
        $this->assertSame([['label' => 'IGST (18%)', 'amount' => 99.99]], $g['lines']);
        $this->assertSame('27 - Maharashtra', $g['place_of_supply']);
        $this->assertSame('998551', $g['sac']);
    }

    public function test_invalid_gstin_is_ignored(): void
    {
        $this->assertNull(GstService::gstin('REG-12345'));
        $g = app(GstService::class)->breakdown(Booking::MODULE_VISA, 10.0, 'REG-1', null);
        $this->assertNull($g['seller_gstin']);
        $this->assertSame('998559', $g['sac']);
        $this->assertCount(2, $g['lines']);
    }
}
