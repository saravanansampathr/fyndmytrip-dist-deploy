<?php

namespace App\Http\Controllers\Api;

use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The CRM screen the feature request asked for: "Once Akbar Travels &
 * TripJack API done in crm we need an option to add the API Key not from
 * the Background" — i.e. a tenant admin enters their own supplier
 * credentials here rather than an engineer editing .env/config/suppliers.php.
 *
 * The credential plumbing this writes to (tenant_supplier_configs,
 * encrypted at rest via the model's `encrypted:array` cast) already existed
 * and is already what SupplierOrchestrator resolves at search/hold/confirm
 * time (falling back to the Master tenant's config when a tenant hasn't
 * connected its own) — this controller is the missing UI-facing piece, not
 * a new storage layer.
 */
class SupplierConnectionController extends Controller
{
    /**
     * Every catalog supplier, each annotated with the acting tenant's own
     * connection (if any) — TenantSupplierConfig's global TenantScope
     * means the query below is already scoped to $user->tenant_id (Master,
     * for platform staff — see Tenant model's docblock).
     */
    public function index(Request $request)
    {
        $suppliers = Supplier::query()->orderBy('name')->get();

        $configs = TenantSupplierConfig::query()
            ->whereIn('supplier_id', $suppliers->pluck('id'))
            ->get()
            ->keyBy('supplier_id');

        $credentialFields = config('suppliers.credential_fields', []);

        $data = $suppliers->map(function (Supplier $supplier) use ($configs, $credentialFields) {
            $config = $configs->get($supplier->id);

            return [
                'supplier_id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'supported_modules' => $supplier->supported_modules,
                'credential_fields' => $credentialFields[$supplier->code] ?? [],
                'is_connected' => (bool) $config,
                'config_id' => $config?->id,
                'is_active' => $config?->is_active ?? false,
                'priority_order' => $config?->priority_order ?? 1,
                // Never echo credential values back — only which keys are
                // already on file, so the form can show "•••• saved" per
                // field without ever round-tripping a secret to the client.
                'configured_keys' => $config ? array_keys($config->credentials_encrypted ?? []) : [],
                'updated_at' => $config?->updated_at,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Connect (or reconnect) a supplier for the acting tenant. Credentials
     * are a free-form string=>string map — config/suppliers.php's
     * credential_fields tells the frontend which keys a given supplier
     * expects, but the backend doesn't hard-code them so a tenant's own
     * custom supplier (Supplier model docblock) works the same way.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user->hasPermission('supplier.configure')) {
            abort(403, 'Missing required permission: supplier.configure');
        }

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'credentials' => ['required', 'array', 'min:1'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'priority_order' => ['nullable', 'integer', 'min:1', 'max:255'],
        ]);

        $config = TenantSupplierConfig::query()->updateOrCreate(
            ['tenant_id' => $user->tenant_id, 'supplier_id' => $data['supplier_id']],
            [
                'credentials_encrypted' => array_filter($data['credentials'], fn ($v) => $v !== null && $v !== ''),
                'is_active' => $data['is_active'] ?? true,
                'priority_order' => $data['priority_order'] ?? 1,
            ],
        );

        return response()->json(['data' => $this->maskedConfig($config->fresh('supplier'))], 201);
    }

    public function update(Request $request, TenantSupplierConfig $tenantSupplierConfig)
    {
        $user = $request->user();

        if (! $user->hasPermission('supplier.configure')) {
            abort(403, 'Missing required permission: supplier.configure');
        }

        abort_if($tenantSupplierConfig->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s supplier connection.');

        $data = $request->validate([
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'priority_order' => ['nullable', 'integer', 'min:1', 'max:255'],
        ]);

        if (array_key_exists('credentials', $data) && $data['credentials'] !== null) {
            // Merge rather than replace so re-saving the "Active" toggle
            // alone (with masked/blank secret fields left untouched by the
            // frontend) never wipes previously-saved secrets.
            $existing = $tenantSupplierConfig->credentials_encrypted ?? [];
            $incoming = array_filter($data['credentials'], fn ($v) => $v !== null && $v !== '');
            $data['credentials_encrypted'] = array_merge($existing, $incoming);
            unset($data['credentials']);
        }

        $tenantSupplierConfig->update($data);

        return response()->json(['data' => $this->maskedConfig($tenantSupplierConfig->fresh('supplier'))]);
    }

    public function destroy(Request $request, TenantSupplierConfig $tenantSupplierConfig)
    {
        $user = $request->user();

        if (! $user->hasPermission('supplier.configure')) {
            abort(403, 'Missing required permission: supplier.configure');
        }

        abort_if($tenantSupplierConfig->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s supplier connection.');

        $tenantSupplierConfig->delete();

        return response()->json(null, 204);
    }

    protected function maskedConfig(TenantSupplierConfig $config): array
    {
        return [
            'supplier_id' => $config->supplier_id,
            'code' => $config->supplier->code,
            'name' => $config->supplier->name,
            'is_connected' => true,
            'config_id' => $config->id,
            'is_active' => $config->is_active,
            'priority_order' => $config->priority_order,
            'configured_keys' => array_keys($config->credentials_encrypted ?? []),
            'updated_at' => $config->updated_at,
        ];
    }
}
