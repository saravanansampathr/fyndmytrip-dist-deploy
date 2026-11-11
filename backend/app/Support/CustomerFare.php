<?php

namespace App\Support;

use App\Domains\Booking\Models\Booking;
use App\Models\User;

/**
 * What a traveller-facing document (e-ticket, voucher, policy, invoice)
 * shows as the price.
 *
 * The platform's markup, margin, service charge or convenience fee is never
 * printed as its own line: "if any markup don't show in ticket instead add
 * that into overall ticket fare like base". It is folded into the fare line
 * (Base Fare / Room Charges / Premium ...), so the lines still add up to the
 * total the traveller paid.
 *
 * The fare-less e-ticket is a B2B tool: an agent hands the traveller a ticket
 * without the price they were charged. Direct (B2C) customers always get the
 * ticket with the price.
 */
class CustomerFare
{
    /**
     * Price lines for an e-ticket / voucher. They add up to the booking total.
     *
     * @return array<int, array{label: string, amount: float}>
     */
    public static function rows(Booking $booking, string $fareLabel = 'Base Fare'): array
    {
        $rows = [['label' => $fareLabel, 'amount' => self::fare($booking)]];

        return array_merge($rows, self::extraRows($booking));
    }

    /** Supplier fare plus the platform's markup, as one amount. */
    public static function fare(Booking $booking): float
    {
        $gross = (float) $booking->gross_amount;
        if ($gross <= 0) {
            $gross = (float) $booking->supplier_net_amount + (float) $booking->markup_amount;
        }

        return round($gross, 2);
    }

    /**
     * Add-ons, discount and TCS: real items the traveller chose or a tax, so
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
     * Only for B2B bookings (agent, sub-agent, white-label, API partner) and
     * only for the business side (agents, their staff, platform staff), never
     * for a direct customer or a guest.
     */
    public static function canHideFare(?User $user, Booking $booking): bool
    {
        if ($user === null || $user->is_guest || $user->role === User::ROLE_CUSTOMER) {
            return false;
        }

        return $booking->channel !== null && $booking->channel !== Booking::CHANNEL_DIRECT;
    }
}
