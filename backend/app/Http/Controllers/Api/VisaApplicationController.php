<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Suppliers\Services\MarkupService;
use App\Domains\Visa\Models\VisaProduct;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;

/**
 * Starts a visa application: one Booking (module visa, status held) for
 * one visa product and one or more applicants, priced at the product's
 * selling price per applicant plus the booking tenant's own visa margin
 * rule. It is then paid through CatalogBookingPaymentController
 * (/visa-bookings/{id}/confirm and /pay), after which the visa team
 * collects the documents listed on the product.
 *
 * Before this the catalog could be browsed but "Apply now" did nothing.
 */
class VisaApplicationController extends Controller
{
    public function __construct(protected MarkupService $markupService) {}

    public function store(Request $request, VisaProduct $visaProduct)
    {
        $user = $request->user();

        abort_unless(
            $visaProduct->isBookable() && ($visaProduct->tenant_id === null || (int) $visaProduct->tenant_id === (int) $user->tenant_id),
            404
        );

        $data = $request->validate([
            'travel_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'applicants' => ['required', 'array', 'min:1', 'max:10'],
            'applicants.*.name' => ['required', 'string', 'max:191'],
            'applicants.*.passport_number' => ['required', 'string', 'max:20'],
            'applicants.*.date_of_birth' => ['nullable', 'date', 'before:today'],
            'applicants.*.nationality' => ['nullable', 'string', 'max:60'],
        ]);

        $count = count($data['applicants']);
        $catalog = BigDecimal::of((string) $visaProduct->sell_price)->multipliedBy($count);
        $net = BigDecimal::of((string) ($visaProduct->net_price ?? $visaProduct->sell_price))->multipliedBy($count);

        $pricing = $this->markupService->applyMarkup($user->tenant, Booking::MODULE_VISA, $this->markupRoleFor($user), (string) $catalog->toScale(2, RoundingMode::HALF_UP), [
            'passenger_count' => $count,
            'destination' => $visaProduct->destination_country,
            'is_international' => true,
            'supplier_code' => $visaProduct->supplier_code,
        ]);
        $gross = BigDecimal::of($pricing['gross_amount']);

        $booking = Booking::create([
            'tenant_id' => $user->tenant_id,
            'created_by_user_id' => $user->id,
            'channel' => Booking::channelForTenant($user->tenant),
            'module' => Booking::MODULE_VISA,
            'supplier_code' => $visaProduct->supplier_code ?: VisaProduct::SUPPLIER_MANUAL,
            'is_international' => true,
            'gross_amount' => (string) $gross->toScale(2, RoundingMode::HALF_UP),
            'supplier_net_amount' => (string) $net->toScale(2, RoundingMode::HALF_UP),
            'markup_amount' => (string) $gross->minus($net)->toScale(2, RoundingMode::HALF_UP),
            'travel_date_start' => $data['travel_date'],
            'passenger_count' => $count,
            'contact_email' => $data['contact_email'] ?? $user->email,
            'contact_phone' => $data['contact_phone'] ?? $user->phone,
            'currency' => $visaProduct->currency ?: 'INR',
            'status' => Booking::STATUS_HELD,
            'metadata' => [
                'visa_product_id' => $visaProduct->id,
                'destination_country' => $visaProduct->destination_country,
                'destination_country_name' => $visaProduct->destination_country_name,
                'visa_type' => $visaProduct->visa_type,
                'entry_type' => $visaProduct->entry_type,
                'processing_time_label' => $visaProduct->processing_time_label,
                'documents_required' => $visaProduct->documents_required,
                'applicants' => $data['applicants'],
                'catalog_amount' => (string) $catalog->toScale(2, RoundingMode::HALF_UP),
                'reseller_markup_amount' => $pricing['markup_amount'],
            ],
        ]);

        foreach ($data['applicants'] as $applicant) {
            $booking->passengers()->create([
                'name' => $applicant['name'],
                'passenger_type' => 'adult',
                'passport_number' => $applicant['passport_number'],
                'dob' => $applicant['date_of_birth'] ?? null,
            ]);
        }

        return response()->json(['data' => new BookingResource($booking->fresh(['passengers']))], 201);
    }

    protected function markupRoleFor(User $user): string
    {
        return match ($user->role) {
            User::ROLE_AGENT, User::ROLE_PARTNER_ADMIN => 'agent',
            User::ROLE_SUB_AGENT => 'sub_agent',
            default => 'b2c',
        };
    }
}
