<?php

namespace App\Http\Controllers\Api;

use App\Domains\Cms\Models\ContentItem;
use App\Domains\Cms\Models\MediaFile;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Website content admins edit without a release: home banners, offers,
 * FAQ, SEO title/description per page and popular routes. A white-label
 * partner's own items replace the platform's for that type on its site.
 */
class ContentController extends Controller
{
    /** Public: live items for this site. ?type=banner,offer&module=flight&path=/flights */
    public function index(Request $request)
    {
        $tenant = $request->attributes->get('resolved_tenant') ?? app(TenantContext::class)->get();
        $types = array_values(array_intersect(explode(',', (string) $request->query('type', 'banner,offer,faq')), ContentItem::TYPES));
        $chain = $tenant ? app(TenantService::class)->getAncestorIds($tenant) : [];
        $out = [];
        foreach ($types as $type) {
            $items = collect();
            // Nearest account that has items of this type wins, then the platform's.
            foreach (array_merge($chain, [null]) as $tid) {
                $items = ContentItem::query()->live()->where('type', $type)->where('tenant_id', $tid)
                    ->when($request->query('module'), fn ($q, $m) => $q->where(fn ($q) => $q->whereNull('module')->orWhere('module', $m)))
                    ->orderBy('sort_order')->orderByDesc('id')->get();
                if ($type === 'seo' && ($path = $request->query('path'))) {
                    $items = $items->filter(fn ($i) => rtrim((string) data_get($i->data, 'path'), '/') === rtrim($path, '/'))->values();
                }
                if ($items->isNotEmpty()) {
                    break;
                }
            }
            $out[$type] = $items->map(fn ($i) => $this->present($i, false))->values();
        }

        return response()->json(['data' => $out])->header('Cache-Control', 'public, max-age=60');
    }

    public function adminIndex(Request $request)
    {
        $tid = $this->scopeTenantId($request->user());
        $items = ContentItem::query()->where('tenant_id', $tid)
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->orderBy('type')->orderBy('sort_order')->orderByDesc('id')->get();

        return response()->json(['data' => $items->map(fn ($i) => $this->present($i, true)), 'types' => ContentItem::TYPES, 'modules' => Tenant::ALL_MODULES]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $item = ContentItem::query()->create($this->validated($request) + ['tenant_id' => $this->scopeTenantId($user), 'updated_by' => $user->id]);

        return response()->json(['data' => $this->present($item, true)], 201);
    }

    public function update(Request $request, ContentItem $item)
    {
        $user = $request->user();
        abort_unless($item->tenant_id === $this->scopeTenantId($user), 404);
        $item->update($this->validated($request) + ['updated_by' => $user->id]);

        return response()->json(['data' => $this->present($item, true)]);
    }

    public function destroy(Request $request, ContentItem $item)
    {
        abort_unless($item->tenant_id === $this->scopeTenantId($request->user()), 404);
        $item->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /** Image upload for banners and offers (JPG, PNG, WebP, GIF up to 5 MB). */
    public function upload(Request $request)
    {
        $user = $request->user();
        $tid = $this->scopeTenantId($user);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,gif']]);
        $f = $request->file('file');
        $path = $f->store('media/'.($tid ?? 'platform'), 'local');
        $m = MediaFile::query()->create(['tenant_id' => $tid, 'path' => $path, 'original_name' => $f->getClientOriginalName(), 'mime' => $f->getMimeType(), 'size' => $f->getSize(), 'is_public' => true, 'uploaded_by' => $user->id]);

        return response()->json(['data' => ['id' => $m->id, 'url' => $m->url()]], 201);
    }

    /** Public files only; KYC documents are never served here. */
    public function media(int $id)
    {
        $m = MediaFile::query()->where('is_public', true)->findOrFail($id);
        abort_unless(Storage::disk('local')->exists($m->path), 404);

        return response()->file(Storage::disk('local')->path($m->path), [
            'Content-Type' => $m->mime ?: 'application/octet-stream', 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function validated(Request $request): array
    {
        $d = $request->validate([
            'type' => ['required', Rule::in(ContentItem::TYPES)],
            'module' => ['nullable', Rule::in(Tenant::ALL_MODULES)],
            'title' => ['nullable', 'string', 'max:191'],
            'body' => ['nullable', 'string', 'max:20000'],
            'image_url' => ['nullable', 'string', 'max:500', 'regex:#^https?://#'],
            'link_url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/)#'],
            'data' => ['nullable', 'array'],
            'data.coupon_code' => ['nullable', 'string', 'max:40'],
            'data.path' => ['nullable', 'string', 'max:255'],
            'data.meta_title' => ['nullable', 'string', 'max:191'],
            'data.meta_description' => ['nullable', 'string', 'max:300'],
            'data.origin' => ['nullable', 'string', 'max:10'],
            'data.destination' => ['nullable', 'string', 'max:10'],
            'data.price_from' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (isset($d['body'])) {
            $d['body'] = CmsPageController::clean($d['body']);
        }

        return $d + ['is_active' => true, 'sort_order' => 0];
    }

    protected function present(ContentItem $i, bool $admin): array
    {
        return array_filter([
            'id' => $i->id, 'type' => $i->type, 'module' => $i->module, 'title' => $i->title, 'body' => $i->body,
            'image_url' => $i->image_url, 'link_url' => $i->link_url, 'data' => $i->data ?: null, 'sort_order' => $i->sort_order,
            'starts_at' => $i->starts_at?->toIso8601String(), 'ends_at' => $i->ends_at?->toIso8601String(),
        ], fn ($v) => $v !== null) + ($admin ? ['is_active' => $i->is_active, 'updated_at' => $i->updated_at?->toIso8601String()] : []);
    }

    protected function scopeTenantId(User $user): ?int
    {
        if ($user->isPlatformStaff()) {
            return null;
        }
        abort_unless($user->role === User::ROLE_PARTNER_ADMIN, 403, 'Only admins can edit website content.');

        return $user->tenant_id;
    }
}
