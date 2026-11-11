<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Concerns\FiltersByDateRangePreset;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared CRUD for the six dedicated per-product-module inventory
 * controllers (Flight/Hotel/Bus/Cab/Insurance/Cruise) — same
 * tenant-scoping/authorization behaviour the old single
 * InventoryItemController had, just parameterized over which model/table a
 * given product module's subclass points at, so the "Build the super
 * product" split into six real schemas didn't also mean six copies of this
 * plumbing.
 *
 * Route model binding isn't used here (each subclass would need its own
 * differently-typed method signature for that) — update()/destroy() just
 * resolve the row directly off {@see modelClass()} by id instead.
 */
abstract class BaseInventoryItemController extends Controller
{
    use FiltersByDateRangePreset;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    abstract protected function rules(bool $isUpdate): array;

    /**
     * Extra columns this module's rows can be searched on beyond the common
     * 'title' — e.g. Hotel adds city/hotel_name, Bus adds origin/destination.
     * Kept as a hook rather than a fixed list since each module's dedicated
     * schema has different "what would I search for" columns.
     */
    protected function searchableColumns(): array
    {
        return ['title'];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $modelClass = $this->modelClass();

        // "custom" with neither from nor to given means "no date filter" —
        // an inventory manager's default view is everything, narrowed by
        // date only when they ask.
        $dateFilterActive = $request->filled('range') || $request->filled('from') || $request->filled('to');
        [$from, $to] = $dateFilterActive ? $this->resolveDateRangePreset($request) : [null, null];

        $items = $modelClass::query()
            ->when(! $user->isPlatformStaff(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($dateFilterActive, fn ($q) => $q->whereBetween('created_at', [$from, $to]))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $columns = $this->searchableColumns();
                $q->where(function ($sub) use ($term, $columns) {
                    foreach ($columns as $i => $column) {
                        $i === 0 ? $sub->where($column, 'like', $term) : $sub->orWhere($column, 'like', $term);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 25), 500));

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate($this->rules(false));

        if (! $user->isPlatformStaff()) {
            $data['tenant_id'] = $user->tenant_id;
        }

        $item = $this->modelClass()::query()->create($data);

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, int $id)
    {
        $modelClass = $this->modelClass();
        $item = $modelClass::query()->findOrFail($id);
        $this->authorizeAccess($request->user(), $item);

        $data = $request->validate($this->rules(true));

        if ($request->user()->isPlatformStaff() && array_key_exists('tenant_id', $data)) {
            $item->tenant_id = $data['tenant_id'];
            unset($data['tenant_id']);
        } else {
            unset($data['tenant_id']);
        }

        $item->update($data);

        return response()->json(['data' => $item->fresh()]);
    }

    public function destroy(Request $request, int $id)
    {
        $modelClass = $this->modelClass();
        $item = $modelClass::query()->findOrFail($id);
        $this->authorizeAccess($request->user(), $item);

        $item->delete();

        return response()->json(null, 204);
    }

    protected function authorizeAccess($user, Model $item): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if($item->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s inventory.');
    }

    /**
     * The handful of validation rules identical across every module —
     * subclasses spread this into their own rules() alongside their
     * product-specific fields.
     */
    protected function commonRules(bool $isUpdate): array
    {
        $modelClass = $this->modelClass();

        return [
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:191'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'net_price' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'share_on_platform_site' => ['nullable', 'boolean'],
            'share_with_network' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:'.$modelClass::STATUS_ACTIVE.','.$modelClass::STATUS_INACTIVE],
        ];
    }
}
