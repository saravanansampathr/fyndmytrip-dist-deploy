<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carries forward every row already keyed into the old generic
 * `inventory_items` table (live on production since Phase 1 — see task
 * "Own inventory management for all products") into its matching new
 * dedicated table, mapping the old `details` JSON keys onto the real typed
 * columns those tables now have.
 *
 * The row's original id is preserved on insert. This matters specifically
 * for flight rows: a held/confirmed booking may already reference one as
 * `external_id => "inv-{id}"` / `supplier_booking_ref =>
 * "OWN_INVENTORY-{id}-..."` (see FlightBookingController's own-inventory
 * helpers) — renumbering would silently break resolving those bookings.
 * Preserving ids for the other five modules costs nothing and keeps all
 * six consistent.
 *
 * The old `inventory_items` table itself is left in place afterwards (not
 * dropped) purely as a safety-net backup; nothing in the application reads
 * from it anymore after this deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_items')) {
            return;
        }

        DB::table('inventory_items')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $details = json_decode((string) ($row->details ?? '{}'), true) ?: [];
                $common = [
                    'id' => $row->id,
                    'tenant_id' => $row->tenant_id,
                    'title' => $row->title,
                    'capacity' => $row->capacity,
                    'net_price' => $row->net_price,
                    'currency' => $row->currency,
                    'valid_from' => $row->valid_from,
                    'valid_to' => $row->valid_to,
                    'share_on_platform_site' => $row->share_on_platform_site,
                    'share_with_network' => $row->share_with_network,
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];

                match ($row->module) {
                    'flight' => DB::table('flight_inventory_items')->insert($common + [
                        'origin' => $row->origin,
                        'destination' => $row->destination,
                        'travel_date' => $row->travel_date,
                        'departure_time' => $details['departure_time'] ?? null,
                        'airline' => $row->operator_or_provider,
                        'flight_number' => $details['flight_number'] ?? null,
                        'cabin_class' => $details['cabin_class'] ?? 'economy',
                        'aircraft_type' => $details['aircraft_type'] ?? null,
                        'duration_minutes' => $details['duration_minutes'] ?? null,
                        'baggage_allowance_kg' => $details['baggage_allowance_kg'] ?? null,
                        'is_refundable' => (bool) ($details['is_refundable'] ?? false),
                        'meal_included' => (bool) ($details['meal_included'] ?? false),
                    ]),
                    'hotel' => DB::table('hotel_inventory_items')->insert($common + [
                        'city' => $row->destination ?? $row->origin,
                        'hotel_name' => $row->operator_or_provider,
                        'check_in_date' => $row->travel_date,
                        'check_out_date' => $details['check_out_date'] ?? null,
                        'room_type' => $details['room_type'] ?? null,
                        'star_rating' => $details['star_rating'] ?? null,
                        'meal_plan' => $details['meal_plan'] ?? null,
                    ]),
                    'bus' => DB::table('bus_inventory_items')->insert($common + [
                        'origin' => $row->origin,
                        'destination' => $row->destination,
                        'travel_date' => $row->travel_date,
                        'departure_time' => $details['departure_time'] ?? null,
                        'operator_name' => $row->operator_or_provider,
                        'bus_type' => $details['bus_type'] ?? null,
                        'boarding_point' => $details['boarding_point'] ?? null,
                        'dropping_point' => $details['dropping_point'] ?? null,
                    ]),
                    'cab' => DB::table('cab_inventory_items')->insert($common + [
                        'origin' => $row->origin,
                        'destination' => $row->destination,
                        'travel_date' => $row->travel_date,
                        'provider_name' => $row->operator_or_provider,
                        'car_type' => $details['car_type'] ?? null,
                        'included_km' => $details['included_km'] ?? null,
                        'extra_km_fare' => $details['extra_km_fare'] ?? null,
                    ]),
                    'insurance' => DB::table('insurance_inventory_items')->insert($common + [
                        'plan_name' => $row->title,
                        'provider_name' => $row->operator_or_provider,
                        'coverage_amount' => $details['coverage_amount'] ?? null,
                        'policy_term_days' => $details['policy_term_days'] ?? null,
                    ]),
                    'cruise' => DB::table('cruise_inventory_items')->insert($common + [
                        'cruise_line' => $row->operator_or_provider,
                        'ship_name' => $details['ship_name'] ?? null,
                        'departure_port' => $row->origin,
                        'arrival_port' => $row->destination,
                        'departure_date' => $row->travel_date,
                        'nights' => $details['itinerary_nights'] ?? null,
                        'cabin_type' => $details['cabin_type'] ?? null,
                    ]),
                    default => null,
                };
            }
        });
    }

    public function down(): void
    {
        // Irreversible on purpose — this migration only copies data forward.
        // Rolling back the schema migration (…000018) drops the destination
        // tables anyway, which takes this data down with it.
    }
};
