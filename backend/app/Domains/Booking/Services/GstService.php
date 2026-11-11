<?php

namespace App\Domains\Booking\Services;

use App\Domains\Booking\Models\Booking;

/**
 * Indian GST details for a tax invoice: GSTINs, place of supply, the SAC
 * code for the service, and whether the tax is CGST + SGST (same state)
 * or IGST (different states). A GSTIN is read from the account's
 * "Business registration / GSTIN" field; its first two digits are the
 * state code.
 */
class GstService
{
    public const RATE_PERCENT = '18';

    /** SAC codes (chapter 9985, travel arrangement services). */
    public const SAC = [
        Booking::MODULE_FLIGHT => '998551',
        Booking::MODULE_BUS => '998551',
        Booking::MODULE_CAB => '998551',
        Booking::MODULE_CRUISE => '998551',
        Booking::MODULE_HOTEL => '998552',
        Booking::MODULE_HOLIDAY => '998555',
    ];

    public const STATES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand',
        '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya',
        '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh',
        '24' => 'Gujarat', '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
        '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry', '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh', '97' => 'Other Territory',
    ];

    public static function gstin(?string $value): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', (string) $value));

        return preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $v) ? $v : null;
    }

    /**
     * @return array{seller_gstin: ?string, buyer_gstin: ?string, place_of_supply: ?string, sac: string, inter_state: bool, lines: array<int, array{label: string, amount: float}>}
     */
    public function breakdown(string $module, float $taxAmount, ?string $sellerReg, ?string $buyerReg): array
    {
        $seller = self::gstin($sellerReg);
        $buyer = self::gstin($buyerReg);
        $sellerState = $seller ? substr($seller, 0, 2) : null;
        $supplyState = $buyer ? substr($buyer, 0, 2) : $sellerState;
        $interState = $sellerState && $supplyState && $sellerState !== $supplyState;

        $lines = [];
        if ($taxAmount > 0) {
            if ($interState) {
                $lines[] = ['label' => 'IGST (18%)', 'amount' => round($taxAmount, 2)];
            } else {
                $half = round($taxAmount / 2, 2);
                $lines[] = ['label' => 'CGST (9%)', 'amount' => $half];
                $lines[] = ['label' => 'SGST (9%)', 'amount' => round($taxAmount - $half, 2)];
            }
        }

        return [
            'seller_gstin' => $seller,
            'buyer_gstin' => $buyer,
            'place_of_supply' => $supplyState ? $supplyState.' - '.(self::STATES[$supplyState] ?? 'India') : null,
            'sac' => self::SAC[$module] ?? '998559',
            'inter_state' => (bool) $interState,
            'lines' => $lines,
        ];
    }
}
