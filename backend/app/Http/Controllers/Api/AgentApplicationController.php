<?php

namespace App\Http\Controllers\Api;

use App\Domains\Cms\Models\MediaFile;
use App\Domains\Kyc\Models\AgentApplication;
use App\Domains\Kyc\Models\KycDocument;
use App\Domains\Notifications\Services\NotificationService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Travel agents sign themselves up (company details, PAN, GSTIN and KYC
 * documents); platform staff (or the white-label partner whose site they
 * signed up on) approve or reject. Approval creates the agent account and
 * its login with the password the agent chose.
 */
class AgentApplicationController extends Controller
{
    public function store(Request $request)
    {
        $site = $request->attributes->get('resolved_tenant') ?? app(TenantContext::class)->get();
        $parent = $site && in_array($site->type, [Tenant::TYPE_MASTER, Tenant::TYPE_WHITE_LABEL], true) ? $site : Tenant::query()->where('type', Tenant::TYPE_MASTER)->firstOrFail();

        $d = $request->validate([
            'company_name' => ['required', 'string', 'max:191'],
            'contact_name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'pan' => ['required', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],
            'gstin' => ['nullable', 'regex:/^\d{2}[A-Za-z]{5}\d{4}[A-Za-z][1-9A-Za-z][Zz][0-9A-Za-z]$/'],
            'address' => ['required', 'string', 'max:1000'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'consent' => ['accepted'],
            'documents' => ['required', 'array'],
            'documents.pan' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'documents.gst' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'documents.id_proof' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'documents.address_proof' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'documents.iata' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ], ['consent.accepted' => 'Please agree to the terms and privacy policy.']);

        abort_if(User::query()->where('email', $d['email'])->exists() || AgentApplication::query()->where('email', $d['email'])->where('status', 'pending')->exists(),
            422, 'An account or application with this email already exists. Sign in, or use another email.');

        $app = DB::transaction(function () use ($d, $parent, $request) {
            $app = AgentApplication::query()->create([
                'parent_tenant_id' => $parent->id, 'company_name' => $d['company_name'], 'contact_name' => $d['contact_name'],
                'email' => strtolower($d['email']), 'phone' => $d['phone'], 'pan' => strtoupper($d['pan']), 'gstin' => isset($d['gstin']) ? strtoupper($d['gstin']) : null,
                'address' => $d['address'], 'city' => $d['city'], 'state' => $d['state'], 'password_hash' => Hash::make($d['password']), 'status' => 'pending',
            ]);
            foreach ($request->file('documents', []) as $type => $file) {
                $path = $file->store('kyc/'.$app->id, 'local');
                $m = MediaFile::query()->create(['tenant_id' => null, 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'is_public' => false]);
                KycDocument::query()->create(['agent_application_id' => $app->id, 'type' => $type, 'media_file_id' => $m->id]);
            }

            return $app;
        });

        try {
            app(NotificationService::class)->alertStaff('staff_alert', [
                'booking_code' => 'Agent sign-up', 'reason' => "New agent application from {$app->company_name} ({$app->email}). Review it in Admin settings → Agent sign-ups.",
            ]);
        } catch (\Throwable) {
        }

        return response()->json(['data' => ['id' => $app->id, 'status' => $app->status], 'message' => 'Thank you. We will check your documents and email you when your account is ready, usually within one working day.'], 201);
    }

    public function index(Request $request)
    {
        $q = $this->scoped($request->user())->withCount('documents')->latest('id')
            ->when($request->query('status', 'pending'), fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s));

        return response()->json(['data' => $q->limit(200)->get()]);
    }

    public function show(Request $request, int $id)
    {
        $app = $this->scoped($request->user())->with('documents')->findOrFail($id);

        return response()->json(['data' => $app->toArray() + ['document_list' => $app->documents->map(fn ($doc) => [
            'id' => $doc->id, 'type' => $doc->type, 'label' => KycDocument::TYPES[$doc->type] ?? $doc->type, 'status' => $doc->status,
            'expires_on' => $doc->expires_on?->toDateString(), 'file_url' => url("/api/admin/kyc-documents/{$doc->id}/file"),
        ])]]);
    }

    public function approve(Request $request, int $id, TenantService $tenants)
    {
        $user = $request->user();
        $app = $this->scoped($user)->where('status', 'pending')->findOrFail($id);
        $d = $request->validate(['credit_limit' => ['nullable', 'numeric', 'min:0'], 'expiry' => ['nullable', 'array'], 'expiry.*' => ['nullable', 'date']]);

        [$tenant, $agent] = DB::transaction(function () use ($app, $tenants, $user, $d) {
            $tenant = $tenants->createTenant([
                'type' => Tenant::TYPE_AGENT, 'name' => $app->company_name, 'status' => Tenant::STATUS_ACTIVE,
                'business_registration_no' => $app->gstin, 'pan' => $app->pan,
                'support_email' => $app->email, 'support_phone' => $app->phone,
                'business_address' => trim($app->address."\n".$app->city.', '.$app->state),
            ], Tenant::query()->findOrFail($app->parent_tenant_id));
            $agent = User::query()->create([
                'tenant_id' => $tenant->id, 'name' => $app->contact_name, 'email' => $app->email, 'phone' => $app->phone,
                'password_hash' => $app->password_hash, 'role' => User::ROLE_AGENT, 'status' => User::STATUS_ACTIVE,
            ]);
            foreach ($app->documents as $doc) {
                $doc->update(['tenant_id' => $tenant->id, 'status' => 'verified', 'expires_on' => $d['expiry'][$doc->type] ?? $doc->expires_on]);
            }
            $app->update(['status' => 'approved', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'tenant_id' => $tenant->id, 'user_id' => $agent->id]);

            return [$tenant, $agent];
        });

        $this->mail($app->email, 'Your agent account is ready', "Hello {$app->contact_name},\n\nYour agent account for {$app->company_name} is approved. Sign in with {$app->email} and the password you chose when you applied.\n\nThank you.");

        return response()->json(['data' => ['tenant_id' => $tenant->id, 'user_id' => $agent->id], 'message' => 'Approved. The agent can sign in now.']);
    }

    public function reject(Request $request, int $id)
    {
        $user = $request->user();
        $app = $this->scoped($user)->where('status', 'pending')->findOrFail($id);
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $app->update(['status' => 'rejected', 'rejection_reason' => $d['reason'], 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
        $this->mail($app->email, 'About your agent application', "Hello {$app->contact_name},\n\nWe could not approve your agent application for {$app->company_name}:\n\n{$d['reason']}\n\nYou are welcome to apply again with the corrected details.");

        return response()->json(['message' => 'Rejected. The applicant has been emailed the reason.']);
    }

    /** A KYC document, to staff or the partner reviewing it (never public). */
    public function file(Request $request, int $doc)
    {
        $user = $request->user();
        $d = KycDocument::query()->with('file')->findOrFail($doc);
        $allowed = $user->isPlatformStaff()
            || ($d->agent_application_id && $this->scoped($user)->whereKey($d->agent_application_id)->exists())
            || ($d->tenant_id && $user->role === User::ROLE_PARTNER_ADMIN && in_array($d->tenant_id, app(TenantService::class)->getDescendantIdsRobust($user->tenant), true));
        abort_unless($allowed && $d->file && Storage::disk('local')->exists($d->file->path), 404);

        return response()->file(Storage::disk('local')->path($d->file->path), ['Content-Type' => $d->file->mime ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    protected function scoped(User $user)
    {
        if ($user->isPlatformStaff()) {
            return AgentApplication::query();
        }
        abort_unless($user->role === User::ROLE_PARTNER_ADMIN, 403);

        return AgentApplication::query()->where('parent_tenant_id', $user->tenant_id);
    }

    protected function mail(string $to, string $subject, string $body): void
    {
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
