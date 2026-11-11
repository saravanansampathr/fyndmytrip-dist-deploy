<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bookings made outside the website (over the phone, on another portal or
 * directly with an airline or hotel) entered by hand or imported from a
 * CSV, so reports, invoices, GST returns and commission include them.
 */
class OfflineBookingController extends Controller
{
    public const CSV_COLUMNS = ['account_id', 'service', 'supplier_name', 'supplier_ref', 'travel_date', 'return_date', 'travellers', 'contact_email', 'contact_phone', 'supplier_amount', 'selling_amount', 'currency', 'payment_mode', 'status', 'international', 'notes'];

    public function store(Request $request)
    {
        $booking = $this->create($request->all(), $request->user());

        return response()->json(['data' => ['id' => $booking->id, 'booking_code' => $booking->booking_code, 'status' => $booking->status], 'message' => 'Booking saved. Its invoice is ready under Bookings.'], 201);
    }

    /** CSV with a header row; see CSV_COLUMNS. Each row is saved on its own. */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);
        $fh = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), fgetcsv($fh) ?: []);
        abort_unless(in_array('service', $header, true) && in_array('selling_amount', $header, true), 422, 'The first row must be the column names: '.implode(', ', self::CSV_COLUMNS).'.');
        $results = [];
        $line = 1;
        while (($row = fgetcsv($fh)) !== false && $line <= 501) {
            $line++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $r = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
            $input = [
                'tenant_id' => $r['account_id'] ?? null, 'module' => strtolower(trim((string) ($r['service'] ?? ''))),
                'supplier_name' => $r['supplier_name'] ?? null, 'supplier_ref' => $r['supplier_ref'] ?? null,
                'travel_date_start' => $r['travel_date'] ?? null, 'travel_date_end' => ($r['return_date'] ?? '') ?: null,
                'travellers' => array_values(array_filter(array_map('trim', explode(';', (string) ($r['travellers'] ?? ''))))),
                'contact_email' => ($r['contact_email'] ?? '') ?: null, 'contact_phone' => ($r['contact_phone'] ?? '') ?: null,
                'supplier_amount' => $r['supplier_amount'] ?? 0, 'selling_amount' => $r['selling_amount'] ?? null,
                'currency' => strtoupper((string) (($r['currency'] ?? '') ?: 'INR')), 'payment_mode' => strtolower((string) (($r['payment_mode'] ?? '') ?: 'bank')),
                'status' => strtolower((string) (($r['status'] ?? '') ?: 'ticketed')), 'is_international' => in_array(strtolower((string) ($r['international'] ?? '')), ['1', 'yes', 'true', 'y'], true),
                'notes' => $r['notes'] ?? null, 'notify_customer' => false,
            ];
            try {
                $b = $this->create($input, $request->user());
                $results[] = ['line' => $line, 'ok' => true, 'booking_code' => $b->booking_code];
            } catch (ValidationException $e) {
                $results[] = ['line' => $line, 'ok' => false, 'error' => collect($e->errors())->flatten()->first()];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $results[] = ['line' => $line, 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        fclose($fh);
        $ok = collect($results)->where('ok', true)->count();

        return response()->json(['data' => $results, 'message' => "{$ok} of ".count($results).' rows saved.']);
    }

    public function template()
    {
        $csv = implode(',', self::CSV_COLUMNS)."\n,flight,IndiGo,ABC123,".now()->addDays(10)->toDateString().',,Ravi Kumar;Anita Kumar,ravi@example.com,9876543210,9000,10000,INR,bank,ticketed,no,Booked by phone'."\n";

        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="offline-bookings-template.csv"']);
    }

    public function create(array $input, User $user): Booking
    {
        $d = Validator::make($input, [
            'tenant_id' => ['nullable', 'integer'],
            'module' => ['required', Rule::in(Tenant::ALL_MODULES)],
            'supplier_name' => ['required', 'string', 'max:100'],
            'supplier_ref' => ['nullable', 'string', 'max:100'],
            'travel_date_start' => ['required', 'date'],
            'travel_date_end' => ['nullable', 'date', 'after_or_equal:travel_date_start'],
            'travellers' => ['required', 'array', 'min:1', 'max:50'],
            'travellers.*' => ['string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'supplier_amount' => ['nullable', 'numeric', 'min:0'],
            'selling_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_mode' => ['nullable', Rule::in(['cash', 'bank', 'upi', 'card', 'cheque', 'credit'])],
            'status' => ['nullable', Rule::in([Booking::STATUS_CONFIRMED, Booking::STATUS_TICKETED])],
            'is_international' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'notify_customer' => ['nullable', 'boolean'],
        ], ['travellers.required' => 'Add at least one traveller name.'])->validate();

        $tenant = $this->tenantFor($user, $d['tenant_id'] ?? null);
        $selling = round((float) $d['selling_amount'], 2);
        $net = round((float) ($d['supplier_amount'] ?? 0), 2);
        $status = $d['status'] ?? Booking::STATUS_TICKETED;
        $channel = match ($tenant->type) {
            Tenant::TYPE_AGENT => Booking::CHANNEL_AGENT, Tenant::TYPE_SUB_AGENT => Booking::CHANNEL_SUB_AGENT,
            Tenant::TYPE_WHITE_LABEL => Booking::CHANNEL_WHITE_LABEL, default => Booking::CHANNEL_DIRECT,
        };

        return DB::transaction(function () use ($d, $user, $tenant, $selling, $net, $status, $channel) {
            $b = Booking::withoutTenantScope()->create([
                'tenant_id' => $tenant->id, 'agent_tenant_id' => in_array($tenant->type, [Tenant::TYPE_AGENT, Tenant::TYPE_SUB_AGENT], true) ? $tenant->id : null,
                'channel' => $channel, 'created_by_user_id' => $user->id, 'module' => $d['module'], 'is_international' => (bool) ($d['is_international'] ?? false),
                'supplier_code' => 'OFFLINE', 'supplier_booking_ref' => $d['supplier_ref'] ?? null, 'status' => Booking::STATUS_CART,
                'gross_amount' => $selling, 'supplier_net_amount' => $net, 'markup_amount' => max(0, round($selling - $net, 2)), 'commission_amount' => 0,
                'currency' => strtoupper($d['currency'] ?? 'INR'), 'total_amount' => $selling, 'passenger_count' => count($d['travellers']),
                'travel_date_start' => $d['travel_date_start'], 'travel_date_end' => $d['travel_date_end'] ?? null,
                'contact_email' => $d['contact_email'] ?? null, 'contact_phone' => $d['contact_phone'] ?? null,
                'metadata' => [
                    'offline' => true, 'supplier_name' => $d['supplier_name'], 'payment_mode' => $d['payment_mode'] ?? 'bank', 'notes' => $d['notes'] ?? null,
                    'entered_by' => $user->id, 'silent' => ! ($d['notify_customer'] ?? false), 'fraud_checked_at' => 'offline',
                ],
            ]);
            foreach ($d['travellers'] as $name) {
                $parts = preg_split('/\s+/', trim($name), 2);
                $b->passengers()->create(['name' => trim($name), 'first_name' => $parts[0], 'last_name' => $parts[1] ?? '', 'passenger_type' => 'adult']);
            }
            $svc = app(BookingStatusService::class);
            $svc->transition($b, Booking::STATUS_HELD, $user, 'Offline booking');
            $b = $svc->transition($b->fresh(), Booking::STATUS_CONFIRMED, $user, 'Offline booking');
            if ($status === Booking::STATUS_TICKETED) {
                $b = $svc->transition($b, Booking::STATUS_TICKETED, $user, 'Offline booking: issued by '.$d['supplier_name']);
            }

            return $b;
        });
    }

    /** Staff may enter for any account; a partner for accounts under them; an agent for their own. */
    protected function tenantFor(User $user, $tenantId): Tenant
    {
        if ($user->isPlatformStaff()) {
            return $tenantId ? Tenant::query()->findOrFail($tenantId) : $user->tenant;
        }
        abort_unless(in_array($user->role, [User::ROLE_PARTNER_ADMIN, User::ROLE_AGENT, User::ROLE_SUB_AGENT], true), 403, 'Only staff, partners and agents can add offline bookings.');
        if (! $tenantId || (int) $tenantId === $user->tenant_id) {
            return $user->tenant;
        }
        abort_unless($user->role === User::ROLE_PARTNER_ADMIN && in_array((int) $tenantId, app(TenantService::class)->getDescendantIdsRobust($user->tenant), true), 403, 'You can only add bookings for your own accounts.');

        return Tenant::query()->findOrFail($tenantId);
    }
}
