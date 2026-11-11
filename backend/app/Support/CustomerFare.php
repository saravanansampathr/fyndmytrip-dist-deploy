<?php

namespace App\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\GstService;
use App\Domains\Settings\Services\TaxService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;

/**
 * The price lines printed on a booking's e-ticket, voucher, policy and
 * invoice. The user's rules (2026-11-11):
 *
 * - Direct (B2C) customers: our markup and the GST on it are never shown.
 *   Both are folded into the Base Fare. The airline's own tax breakup (K3
 *   GST, fuel surcharge, airport taxes), which comes from the supplier API,
 *   is shown.
 * - B2B partners (agent, sub-agent, white-label, API partner): the supplier
 *   Base Fare, the airline tax breakup, our Convenience fee and the 18% GST
 *   on it (CGST + SGST or IGST), so the partner can claim input tax credit.
 * - The fare-less e-ticket is a B2B tool. Direct customers always get the
 *   ticket with the price.
 */
class CustomerFare
{
    /** Airline tax codes, grouped into the lines a traveller sees. */
    private const TAX_GROUPS = [
        'K3' => 'Airline GST (K3)',
        'YQ' => 'Fuel surcharge (YQ/YR)',
        'YR' => 'Fuel surcharge (YQ/YR)',
    ];

    private const OTHER_TAXES = 'Airport & other taxes';

    /**
     * Price lines. They always add up to the booking total.
     *
     * @return array<int, array{label: string, amount: float}>
     */
    public static function rows(Booking $booking, string $fareLabel = 'Base Fare', ?string $sellerReg = null, ?string $buyerReg = null): array
    {
        $taxes = self::airlineTaxes($booking);
        $supplierBase = round((float) $booking->supplier_net_amount - array_sum($taxes), 2);
        $markup = self::markup($booking);

        $rows = [];
        if (! self::isB2B($booking)) {
            // Markup and our GST on it are hidden inside the base fare.
            $rows[] = ['label' => $fareLabel, 'amount' => round($supplierBase + $markup, 2)];
            foreach ($taxes as $label => $amount) {
                $rows[] = ['label' => $label, 'amount' => $amount];
            }
        } else {
            $rows[] = ['label' => $fareLabel, 'amount' => $supplierBase];
            foreach ($taxes as $label => $amount) {
                $rows[] = ['label' => $label, 'amount' => $amount];
            }
            if ($markup > 0) {
                [$fee, $gst] = self::splitFee($booking, $markup);
                $rows[] = ['label' => 'Convenience fee', 'amount' => $fee];
                $gstLines = app(GstService::class)->breakdown(
                    $booking->module,
                    $gst,
                    $sellerReg ?? self::sellerReg(),
                    $buyerReg ?? Tenant::query()->whereKey($booking->tenant_id)->value('business_registration_no'),
                )['lines'];
                foreach ($gstLines as $line) {
                    $rows[] = ['label' => $line['label'].' on convenience fee', 'amount' => $line['amount']];
                }
            }
        }

        return array_merge($rows, self::extraRows($booking));
    }

    public static function isB2B(Booking $booking): bool
    {
        return $booking->channel !== null && $booking->channel !== Booking::CHANNEL_DIRECT;
    }

    /** Our markup on the booking (GST-inclusive). */
    public static function markup(Booking $booking): float
    {
        $gross = (float) $booking->gross_amount;
        $markup = $gross > 0 ? $gross - (float) $booking->supplier_net_amount : (float) $booking->markup_amount;

        return round(max(0.0, $markup), 2);
    }

    /**
     * Our markup split into the taxable fee and the GST on it.
     *
     * @return array{0: float, 1: float}
     */
    public static function splitFee(Booking $booking, ?float $markup = null): array
    {
        $markup ??= self::markup($booking);
        $rate = app(TaxService::class)->gstRate($booking->module);
        $fee = $markup > 0 ? round($markup / (1 + $rate / 100), 2) : 0.0;

        return [$fee, round($markup - $fee, 2)];
    }

    /**
     * The airline's tax breakup stored at booking time, grouped by line.
     *
     * @return array<string, float>
     */
    public static function airlineTaxes(Booking $booking): array
    {
        $out = [];
        foreach ((array) ($booking->metadata['airline_taxes'] ?? []) as $code => $amount) {
            $label = self::TAX_GROUPS[strtoupper((string) $code)] ?? self::OTHER_TAXES;
            $out[$label] = round(($out[$label] ?? 0) + (float) $amount, 2);
        }
        $out = array_filter($out, fn ($v) => $v > 0);
        if (array_sum($out) >= (float) $booking->supplier_net_amount) {
            return [];
        }
        // Fixed order: GST, fuel, other.
        uksort($out, fn ($a, $b) => array_search($a, [self::TAX_GROUPS['K3'], self::TAX_GROUPS['YQ'], self::OTHER_TAXES], true)
            <=> array_search($b, [self::TAX_GROUPS['K3'], self::TAX_GROUPS['YQ'], self::OTHER_TAXES], true));

        return $out;
    }

    /**
     * Add-ons, discount and TCS: things the traveller chose or a tax, so
     * they stay on their own lines.
     *
     * @return array<int, array{label: string, amount: float}>
     */
    public static function extraRows(Booking $booking): array
    {
        $rows = [];
        $extras = (float) $booking->extras_amount;
        if ($extras > 0) {
            $rows[] = ['label' => $booking->module === Booking::MODULE_FLIGHT ? 'Add-on Services (meals / seats / baggage)' : 'Add-on Services', 'amount' => round($extras, 2)];
        }
        $discount = (float) $booking->discount_amount;
        if ($discount > 0) {
            $rows[] = ['label' => 'Discount', 'amount' => -round($discount, 2)];
        }
        $tcs = (float) ($booking->metadata['tcs_amount'] ?? 0);
        if ($tcs > 0) {
            $rows[] = ['label' => 'TCS u/s 206C(1G)', 'amount' => round($tcs, 2)];
        }

        return $rows;
    }

    /**
     * Whether this viewer may download the e-ticket without the fare.
     * Only for B2B bookings and only for the business side (agents, their
     * staff, platform staff), never for a direct customer or a guest.
     */
    public static function canHideFare(?User $user, Booking $booking): bool
    {
        if ($user === null || $user->is_guest || $user->role === User::ROLE_CUSTOMER) {
            return false;
        }

        return self::isB2B($booking);
    }

    private static function sellerReg(): ?string
    {
        return Tenant::query()->where('type', Tenant::TYPE_MASTER)->value('business_registration_no');
    }
}
