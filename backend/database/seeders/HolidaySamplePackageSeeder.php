<?php

namespace Database\Seeders;

use App\Domains\Holiday\Models\HolidayPackage;
use Illuminate\Database\Seeder;

/**
 * Seeds a handful of representative holiday/tour packages (per the user's
 * choice of "a handful of sample packages" over a full catalog import) —
 * originally written for this platform, in the structure/format seen on
 * reference DMC package sites like Journey Meister (day-wise itinerary,
 * per-occupancy price tiers, inclusions/exclusions, terms), covering a mix
 * of domestic and international, budget and premium, so the catalog has
 * something to show across categories out of the box.
 *
 * tenant_id is left NULL (platform-wide catalog) with both distribution
 * flags on, so every tenant (B2C direct site, every B2B/white-label/agent)
 * can see and sell these — each reseller's own markup rule (MarkupService,
 * see HolidayBookingController) stacks on top of the sell_price set here.
 *
 * Cover/gallery images are free stock placeholders (Lorem Picsum, licensed
 * for free reuse) — swap for real destination photography from the admin
 * CRM whenever the marketing team is ready; these exist so the storefront
 * doesn't look empty on a fresh install.
 */
class HolidaySamplePackageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->packages() as $def) {
            $existing = HolidayPackage::query()->withoutGlobalScopes()->where('slug', $def['package']['slug'])->first();
            if ($existing) {
                continue;
            }

            $package = HolidayPackage::query()->create($def['package']);

            foreach ($def['itinerary'] as $day) {
                $package->itineraryDays()->create($day);
            }

            foreach ($def['tiers'] as $tier) {
                $tier['sell_price'] = HolidayPackage::priceWithMarkup($tier['net_price'], $tier['markup_percent'] ?? $package->markup_percent);
                $package->priceTiers()->create($tier);
            }

            foreach ($def['media'] as $index => $item) {
                $package->media()->create([...$item, 'sort_order' => $index]);
            }
        }

        $this->command?->info('Seeded '.count($this->packages()).' sample holiday packages (platform-wide catalog).');
    }

    protected function packages(): array
    {
        return [
            $this->kashmir(),
            $this->kerala(),
            $this->goa(),
            $this->bali(),
            $this->dubai(),
            $this->thailand(),
            $this->phuQuoc(),
            $this->singapore(),
        ];
    }

    protected function base(array $overrides, array $itineraryTitles, array $tiers): array
    {
        $name = $overrides['name'];
        $slug = HolidayPackage::uniqueSlugFor($name);

        $itinerary = [];
        foreach ($itineraryTitles as $i => $entry) {
            $itinerary[] = [
                'day_number' => $i + 1,
                'title' => $entry[0],
                'description' => $entry[1],
            ];
        }

        return [
            'package' => array_merge([
                'tenant_id' => null,
                'share_on_platform_site' => true,
                'share_with_network' => true,
                'supplier_code' => HolidayPackage::SUPPLIER_MANUAL,
                'slug' => $slug,
                'package_type' => HolidayPackage::TYPE_TOUR,
                'currency' => 'INR',
                'status' => HolidayPackage::STATUS_ACTIVE,
                'markup_percent' => '10.00',
            ], $overrides),
            'itinerary' => $itinerary,
            'tiers' => $tiers,
            'media' => [],
        ];
    }

    protected function kashmir(): array
    {
        return $this->base([
            'name' => 'Kashmir Valley Getaway — Srinagar, Gulmarg & Pahalgam',
            'booking_scope' => HolidayPackage::SCOPE_DOMESTIC,
            'category' => 'Hill Station',
            'destination_country' => 'IN',
            'destination_city' => 'Srinagar',
            'route_summary' => 'Srinagar - Gulmarg - Pahalgam - Srinagar',
            'duration_nights' => 5,
            'duration_days' => 6,
            'hotel_category' => '4-star',
            'cover_image_url' => 'https://picsum.photos/seed/kashmir-valley/900/500',
            'net_price' => '18500.00',
            'inclusions' => ['5 nights houseboat/hotel stay with breakfast & dinner', 'Private cab for sightseeing & transfers', 'Gondola ride (Phase 1) in Gulmarg', 'Shikara ride on Dal Lake', 'All applicable toll & parking charges'],
            'exclusions' => ['Airfare to/from Srinagar', 'Gondola Phase 2 & pony rides (payable directly)', 'GST and other government taxes', 'Anything not mentioned in inclusions'],
            'terms' => ['Rooms subject to availability at the time of confirmation.', 'Sightseeing order may change based on weather/road conditions.', 'Advance payment of 25% required to hold the booking.'],
        ], [
            ['Arrival in Srinagar', 'Pick-up from Srinagar airport, transfer to houseboat on Dal Lake, evening Shikara ride.'],
            ['Srinagar local sightseeing', 'Mughal Gardens — Nishat Bagh, Shalimar Bagh, and Chashme Shahi.'],
            ['Srinagar to Gulmarg', 'Drive to Gulmarg (~1.5 hrs), optional Gondola cable car ride, overnight in Gulmarg.'],
            ['Gulmarg to Pahalgam', 'Scenic drive to Pahalgam via saffron fields of Pampore, overnight in Pahalgam.'],
            ['Pahalgam local sightseeing', 'Betaab Valley, Aru Valley and Chandanwari (subject to road conditions).'],
            ['Departure', 'Drive back to Srinagar for departure flight.'],
        ], [
            ['occupancy_type' => 'adult_sharing', 'net_price' => '18500.00'],
            ['occupancy_type' => 'adult_single', 'age_label' => null, 'net_price' => '27500.00'],
            ['occupancy_type' => 'child_with_bed', 'age_label' => 'Age 5y - 11y', 'net_price' => '14000.00'],
            ['occupancy_type' => 'child_without_bed', 'age_label' => 'Age 2y - 5y', 'net_price' => '8500.00'],
        ]);
    }

    protected function kerala(): array
    {
        return $this->base([
            'name' => 'Kerala Backwaters & Hills — Kochi, Munnar & Alleppey',
            'booking_scope' => HolidayPackage::SCOPE_DOMESTIC,
            'category' => 'Backwaters',
            'destination_country' => 'IN',
            'destination_city' => 'Kochi',
            'route_summary' => 'Kochi - Munnar - Thekkady - Alleppey',
            'duration_nights' => 5,
            'duration_days' => 6,
            'hotel_category' => '4-star',
            'cover_image_url' => 'https://picsum.photos/seed/kerala-backwaters/900/500',
            'net_price' => '21000.00',
            'inclusions' => ['One night on a premium houseboat (all meals)', '4 nights hotel with breakfast', 'Private car with driver throughout', 'Periyar boat ride entry ticket', 'All toll, parking & driver allowance'],
            'exclusions' => ['Flights to/from Kochi', 'Spice plantation & Kathakali show tickets (optional add-ons)', 'GST', 'Personal expenses & tips'],
            'terms' => ['Houseboat check-in is typically at noon and check-out the next morning.', 'Munnar hotel altitude means cooler nights — pack accordingly.', '30% advance to confirm dates.'],
        ], [
            ['Arrival in Kochi', 'Transfer to hotel, evening at leisure or optional Fort Kochi heritage walk.'],
            ['Kochi to Munnar', 'Drive through tea estates and waterfalls to Munnar (~4 hrs), check-in and relax.'],
            ['Munnar sightseeing', 'Tea Museum, Mattupetty Dam, Echo Point and Eravikulam National Park (season permitting).'],
            ['Munnar to Thekkady', 'Drive to Thekkady, evening boating on Periyar Lake spotting wildlife.'],
            ['Thekkady to Alleppey houseboat', 'Board a private houseboat, cruise the backwaters, all meals on board, overnight on the boat.'],
            ['Alleppey to Kochi, departure', 'Disembark after breakfast, transfer to Kochi airport for departure.'],
        ], [
            ['occupancy_type' => 'adult_sharing', 'net_price' => '21000.00'],
            ['occupancy_type' => 'adult_single', 'net_price' => '31000.00'],
            ['occupancy_type' => 'child_with_bed', 'age_label' => 'Age 5y - 11y', 'net_price' => '16000.00'],
            ['occupancy_type' => 'child_without_bed', 'age_label' => 'Age 2y - 5y', 'net_price' => '9500.00'],
        ]);
    }

    protected function goa(): array
    {
        return $this->base([
            'name' => 'Goa Beach Escape — North & South Goa',
            'booking_scope' => HolidayPackage::SCOPE_DOMESTIC,
            'category' => 'Beach',
            'destination_country' => 'IN',
            'destination_city' => 'Goa',
            'route_summary' => 'North Goa - South Goa',
            'duration_nights' => 3,
            'duration_days' => 4,
            'hotel_category' => '3-star',
            'cover_image_url' => 'https://picsum.photos/seed/goa-beach/900/500',
            'net_price' => '9500.00',
            'inclusions' => ['3 nights beach-resort stay with breakfast', 'Airport pickup & drop', 'Half-day North Goa sightseeing', 'Half-day South Goa sightseeing'],
            'exclusions' => ['Flights to/from Goa', 'Water sports at Baga/Calangute (payable directly)', 'Lunch & dinner unless specified', 'GST'],
            'terms' => ['Resort location subject to availability — beachfront or 5-min walk to beach.', 'Peak season (Dec 20 - Jan 5) carries a supplement.'],
        ], [
            ['Arrival & North Goa', 'Airport pickup, check-in, evening at Baga/Calangute beach.'],
            ['North Goa sightseeing', 'Fort Aguada, Chapora Fort, Anjuna flea market (seasonal) and Vagator beach.'],
            ['South Goa sightseeing', 'Basilica of Bom Jesus, Se Cathedral, Colva and Palolem beaches.'],
            ['Departure', 'Leisure morning, check-out and transfer to airport.'],
        ], [
            ['occupancy_type' => 'adult_sharing', 'net_price' => '9500.00'],
            ['occupancy_type' => 'adult_single', 'net_price' => '13500.00'],
            ['occupancy_type' => 'child_with_bed', 'age_label' => 'Age 5y - 11y', 'net_price' => '7000.00'],
        ]);
    }

    protected function bali(): array
    {
        return $this->base([
            'name' => 'Bali Island Bliss — Kuta, Ubud & Nusa Dua',
            'booking_scope' => HolidayPackage::SCOPE_INTERNATIONAL,
            'category' => 'Honeymoon',
            'destination_country' => 'ID',
            'destination_city' => 'Bali',
            'route_summary' => 'Kuta - Ubud - Nusa Dua',
            'duration_nights' => 5,
            'duration_days' => 6,
            'hotel_category' => '4-star',
            'is_fixed_departure' => false,
            'cover_image_url' => 'https://picsum.photos/seed/bali-island/900/500',
            'net_price' => '52000.00',
            'inclusions' => ['5 nights hotel with daily breakfast', 'Private airport transfers', 'Ubud village & rice terrace tour', 'Kintamani volcano tour with lunch', 'Visa arrangement assistance'],
            'exclusions' => ['International airfare', 'Water sports at Tanjung Benoa', 'Bali tourism tax (paid on arrival)', 'Travel insurance'],
            'terms' => ['Passport must be valid for at least 6 months from the travel date.', 'Tour order may be resequenced by the local operator.', '40% advance at booking, balance 20 days before travel.'],
        ], [
            ['Arrival in Bali', 'Airport pickup, transfer to Kuta hotel, evening free at Kuta beach.'],
            ['Kuta to Ubud', 'Tegalalang rice terraces, a coffee plantation, and Ubud Monkey Forest en route to Ubud.'],
            ['Ubud & Kintamani', 'Kintamani volcano viewpoint with lunch, Tirta Empul water temple.'],
            ['Ubud to Nusa Dua', 'Transfer to Nusa Dua, afternoon at leisure by the beach.'],
            ['Nusa Dua & Uluwatu', 'Optional water sports at Tanjung Benoa, sunset at Uluwatu Temple with Kecak dance.'],
            ['Departure', 'Free morning for last-minute shopping, transfer to airport.'],
        ], [
            ['occupancy_type' => 'adult_sharing', 'net_price' => '52000.00'],
            ['occupancy_type' => 'adult_single', 'net_price' => '78000.00'],
            ['occupancy_type' => 'child_with_bed', 'age_label' => 'Age 5y - 11y', 'net_price' => '38000.00'],
        ]);
    }

    protected function dubai(): array
    {
        return $this->base([
            'name' => 'Dubai City Lights — Modern Dubai & Desert Safari',
            'booking_scope' => HolidayPackage::SCOPE_INTERNATIONAL,
            'category' => 'City Break',
            'destination_country' => 'AE',
            'destination_city' => 'Dubai',
            'route_summary' => 'Dubai City',
            'duration_nights' => 4,
            'duration_days' => 5,
            'hotel_category' => '4-star',
            'cover_image_url' => 'https://picsum.photos/seed/dubai-skyline/900/500',
            'net_price' => '46000.00',
            'inclusions' => ['4 nights hotel with breakfast', 'Airport transfers on private basis', 'Dubai city tour (Burj Khalifa observation deck - level 124/125)', 'Desert safari with BBQ dinner', 'Dubai visa processing'],
            'exclusions' => ['International airfare', 'Dhow cruise dinner (optional add-on)', 'Theme park tickets', 'Tourism dirham fee charged by hotel at check-in'],
            'terms' => ['Visa processing takes 4-5 working days — documents needed at least 10 days before travel.', 'Passport valid 6 months from the travel date, mandatory for visa.', 'Desert safari subject to weather conditions.'],
        ], [
            ['Arrival in Dubai', 'Private transfer to hotel, evening at leisure.'],
            ['Dubai city tour', 'Burj Khalifa (At the Top), Dubai Mall & Fountain show, Palm Jumeirah drive-by.'],
            ['Desert safari', 'Dune bashing, camel ride, henna, and a BBQ dinner with live entertainment at the desert camp.'],
            ['Old Dubai & free time', 'Optional Dubai Creek/Gold & Spice Souk visit, rest of the day free for shopping.'],
            ['Departure', 'Check-out and private transfer to the airport.'],
        ], [
            ['occupancy_type' => 'adult_sharing', 'net_price' => '46000.00'],
            ['occupancy_type' => 'adult_single', 'net_price' => '68000.00'],
            ['occupancy_type' => 'child_with_bed', 'age_label' => 'Age 5y - 11y', 'net_price' => '34000.00'],
        ]);
    }

    protected function thailand(): array
    {
        return $this->base([
            'name' => 'Phuket & Krabi Thailand Trail',
            'booking_scope' => HolidayPackage::SCOPE_INTERNATIONAL,
            'category' => 'Beach',
            'destination_country' => 'TH',
            'destination_city' => 'Phuket',
            'route_summary' => 'Phuket - Krabi',
            'duration_nights' => 6,
            'duration_days' => 7,
            'hotel_category' => '4-star',
            'cover_image_url' => 'https://picsum.photos/seed/phuket-krabi/900/500',
            'net_price' => '58000.00',
            'inclusions' => ['3 nights Phuket + 3 nights Krabi hotel with breakfast', 'Phi Phi Island day tour by speedboat with lunch', 'James Bond Island tour by longtail boat', 'All transfers between Phuket and Krabi'],
            'exclusions' => ['International airfare', 'Thailand visa-on-arrival fee (as applicable)', 'Island tour entrance/national park fees not specified', 'Alcoholic beverages'],
            'terms' => ['Speedboat tours may be rescheduled or cancelled due to weather/sea conditions.', 'Passport valid 6 months from the travel date.'],
        ], [
            ['Arrival in Phuket', 'Airport pickup, transfer to hotel, evening free at Patong Beach.'],
            ['Phi Phi Islands day tour', 'Speedboat to Maya Bay, Monkey Beach and Phi Phi Don with lunch on the island.'],
            ['Phuket free day', 'Optional Big Buddha & Old Phuket Town tour, or relax at the resort.'],
            ['Phuket to Krabi', 'Transfer by road/ferry to Krabi, check-in, evening at Ao Nang beach.'],
            ['James Bond Island tour', 'Longtail boat through Phang Nga Bay, Khao Phing Kan (James Bond Island) and a sea-cave canoe stop.'],
            ['Krabi free day', 'Optional Railay Beach or Emerald Pool excursion.'],
            ['Departure', 'Transfer to Krabi airport for departure.'],
        ], [
            ['occupancy_type' => 'adult_sharing', 'net_price' => '58000.00'],
            ['occupancy_type' => 'adult_single', 'net_price' => '86000.00'],
            ['occupancy_type' => 'child_with_bed', 'age_label' => 'Age 5y - 11y', 'net_price' => '42000.00'],
        ]);
    }

    protected function phuQuoc(): array
    {
        return $this->base([
            'name' => 'Phu Quoc Beach Retreat, Vietnam',
            'booking_scope' => HolidayPackage::SCOPE_INTERNATIONAL,
            'category' => 'Beach',
            'destination_country' => 'VN',
            'destination_city' => 'Phu Quoc',
            'route_summary' => 'Phu Quoc Island',
            'duration_nights' => 4,
            'duration_days' => 5,
            'hotel_category' => '4-star',
            'cover_image_url' => 'https://picsum.photos/seed/phu-quoc-island/900/500',
            'net_price' => '49000.00',
            'inclusions' => ['4 nights beachfront resort with breakfast', 'Airport transfers', 'Phu Quoc cable car (world\'s longest three-rope car) return ticket', 'Half-day southern islands snorkeling tour'],
            'exclusions' => ['International airfare', 'Vietnam e-visa fee', 'Vinpearl Safari & amusement park tickets (optional)', 'Diving/snorkeling equipment rental beyond the included tour'],
            'terms' => ['Vietnam e-visa should be applied for at least 5 working days before travel.', 'Passport valid 6 months from the travel date.', 'Snorkeling tour subject to sea/weather conditions.'],
        ], [
            ['Arrival in Phu Quoc', 'Airport pickup, transfer to beachfront resort, evening free by the beach.'],
            ['Southern islands snorkeling tour', 'Boat trip to the southern archipelago for snorkeling, seafood lunch on board.'],
            ['Cable car & Sunset Town', 'Ride the Hon Thom cable car, explore Sunset Town, evening beach walk.'],
            ['Free day at leisure', 'Optional Vinpearl Safari, night market visit, or relax at the resort.'],
            ['Departure', 'Check-out and transfer to the airport.'],
        ], [
            ['occupancy_type' => 'adult_sharing', 'net_price' => '49000.00'],
            ['occupancy_type' => 'adult_single', 'net_price' => '72000.00'],
            ['occupancy_type' => 'child_with_bed', 'age_label' => 'Age 5y - 11y', 'net_price' => '36000.00'],
        ]);
    }

    protected function singapore(): array
    {
        return $this->base([
            'name' => 'Singapore & Sentosa Family Fun',
            'booking_scope' => HolidayPackage::SCOPE_INTERNATIONAL,
            'category' => 'Family',
            'destination_country' => 'SG',
            'destination_city' => 'Singapore',
            'route_summary' => 'Singapore City - Sentosa Island',
            'duration_nights' => 4,
            'duration_days' => 5,
            'hotel_category' => '4-star',
            'cover_image_url' => 'https://picsum.photos/seed/singapore-sentosa/900/500',
            'net_price' => '54000.00',
            'inclusions' => ['4 nights hotel with breakfast', 'Airport transfers', 'Singapore city tour (Merlion Park, Gardens by the Bay light show)', 'Sentosa day pass with cable car', 'Universal Studios Singapore 1-day ticket'],
            'exclusions' => ['International airfare', 'Night Safari / River Wonders (optional add-on)', 'Singapore e-visa/entry requirements if applicable to nationality', 'Meals other than breakfast'],
            'terms' => ['Universal Studios ticket is date-specific and non-refundable once issued.', 'Passport valid 6 months from the travel date.'],
        ], [
            ['Arrival in Singapore', 'Airport pickup, transfer to hotel, evening at Clarke Quay/Marina Bay.'],
            ['Singapore city tour', 'Merlion Park, Chinatown, Little India, evening Gardens by the Bay light show.'],
            ['Sentosa Island', 'Cable car to Sentosa, beaches, S.E.A. Aquarium (optional), evening free.'],
            ['Universal Studios Singapore', 'Full day at Universal Studios with the family.'],
            ['Departure', 'Free morning for shopping at Orchard Road, transfer to the airport.'],
        ], [
            ['occupancy_type' => 'adult_sharing', 'net_price' => '54000.00'],
            ['occupancy_type' => 'adult_single', 'net_price' => '79000.00'],
            ['occupancy_type' => 'child_with_bed', 'age_label' => 'Age 5y - 11y', 'net_price' => '41000.00'],
            ['occupancy_type' => 'child_without_bed', 'age_label' => 'Age 2y - 5y', 'net_price' => '25000.00'],
        ]);
    }
}
