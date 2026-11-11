<?php

namespace App\Http\Controllers\Api;

use App\Domains\Cms\Models\CmsPage;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Website pages admins edit themselves: privacy, terms, refund and
 * cancellation policy, disclaimer, about us, and any extra page. A
 * white-label partner can publish its own version; otherwise the
 * platform's page is shown.
 */
class CmsPageController extends Controller
{
    /** Public: the published page for this site, or 404 (the website then shows its built-in page). */
    public function show(Request $request, string $slug)
    {
        $tenant = $request->attributes->get('resolved_tenant') ?? app(TenantContext::class)->get();
        $page = CmsPage::query()->where('slug', $slug)->where('is_published', true)
            ->where(fn ($q) => $q->where('tenant_id', $tenant?->id ?? 0)->orWhereNull('tenant_id'))
            ->orderByDesc('tenant_id')->first();

        abort_if(! $page, 404, 'Page not found.');

        return response()->json(['data' => [
            'slug' => $page->slug,
            'title' => $page->title,
            'body' => $page->body,
            'meta_description' => $page->meta_description,
            'updated_at' => $page->updated_at?->toDateString(),
        ]]);
    }

    /** Admin list: saved pages plus the starter pages not yet saved. */
    public function index(Request $request)
    {
        $tenantId = $this->scopeTenantId($request->user());
        $saved = CmsPage::query()->where('tenant_id', $tenantId)->get()->keyBy('slug');
        $rows = [];

        foreach (config('cms_pages') as $slug => $starter) {
            $page = $saved->pull($slug);
            $rows[] = $this->row($slug, $page, $starter);
        }
        foreach ($saved as $slug => $page) {
            $rows[] = $this->row($slug, $page, null);
        }

        return response()->json(['data' => $rows]);
    }

    public function upsert(Request $request, string $slug)
    {
        $user = $request->user();
        $tenantId = $this->scopeTenantId($user);
        abort_unless(preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug), 422, 'Use lowercase letters, numbers and hyphens for the page address.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:200000'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'is_published' => ['required', 'boolean'],
        ]);

        $page = CmsPage::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'slug' => $slug],
            array_merge($data, ['body' => self::clean($data['body']), 'updated_by' => $user->id]),
        );

        return response()->json(['data' => $this->row($slug, $page, config("cms_pages.{$slug}"))]);
    }

    public function destroy(Request $request, string $slug)
    {
        $tenantId = $this->scopeTenantId($request->user());
        CmsPage::query()->where('tenant_id', $tenantId)->where('slug', $slug)->delete();

        return response()->json(['message' => 'Page removed.']);
    }

    /** Strips scripts and inline event handlers so a page can't run code on the site. */
    public static function clean(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|iframe|object|embed)[^>]*/?>#i', '', $html);
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

        return preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1="#"', $html);
    }

    protected function row(string $slug, ?CmsPage $page, ?array $starter): array
    {
        return [
            'slug' => $slug,
            'title' => $page->title ?? $starter['title'] ?? $slug,
            'body' => $page->body ?? $starter['body'] ?? '',
            'meta_description' => $page?->meta_description,
            'is_published' => (bool) ($page?->is_published),
            'is_saved' => (bool) $page,
            'is_starter' => $starter !== null,
            'updated_at' => $page?->updated_at?->toIso8601String(),
        ];
    }

    /** Platform staff edit the platform pages; a partner admin edits their own site's. */
    protected function scopeTenantId(User $user): ?int
    {
        if ($user->isPlatformStaff()) {
            return null;
        }
        abort_unless($user->role === User::ROLE_PARTNER_ADMIN, 403, 'Only admins can edit website pages.');

        return $user->tenant_id;
    }
}
