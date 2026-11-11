<?php

namespace App\Http\Controllers\Api;

use App\Domains\Auth\Models\RolePermission;
use App\Domains\Auth\Services\PermissionRegistry;
use App\Domains\Settings\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin settings → Roles: what each role may do. Super admins always have
 * every right. Changes take effect on the user's next request.
 */
class RolesController extends Controller
{
    public const LABELS = [
        User::ROLE_STAFF => 'Platform staff',
        User::ROLE_PARTNER_ADMIN => 'White-label admins',
        User::ROLE_AGENT => 'Agents',
        User::ROLE_SUB_AGENT => 'Sub-agents',
        User::ROLE_STAFF_USER => 'Account staff users',
        User::ROLE_API_USER => 'API partners',
        User::ROLE_CUSTOMER => 'Customers',
    ];

    public function __construct(protected SettingsService $settings) {}

    public static function catalogue(): array
    {
        return config('permissions');
    }

    public static function allKeys(): array
    {
        return array_merge(...array_values(array_map('array_keys', self::catalogue())));
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->isPlatformStaff(), 403);
        $map = app(PermissionRegistry::class)->map();
        $customised = (array) $this->settings->value('roles', 'customised', []);

        return response()->json(['data' => [
            'catalogue' => collect(self::catalogue())->map(fn ($items, $group) => ['group' => $group, 'items' => collect($items)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()])->values(),
            'roles' => collect(self::LABELS)->map(fn ($label, $role) => [
                'key' => $role, 'label' => $label,
                'permissions' => array_values($map[$role] ?? []),
                'customised' => in_array($role, $customised, true),
            ])->values(),
            'can_edit' => $request->user()->isSuperAdmin(),
        ]]);
    }

    public function update(Request $request, string $role)
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only super admins can change what a role may do.');
        abort_unless(isset(self::LABELS[$role]), 404);
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(self::allKeys())],
        ]);
        $this->sync($role, array_values(array_unique($data['permissions'])));
        $customised = array_values(array_unique(array_merge((array) $this->settings->value('roles', 'customised', []), [$role])));
        $this->settings->put('roles', ['customised' => $customised], $request->user()->id);

        return $this->index($request);
    }

    /** Back to the built-in rights for this role. */
    public function reset(Request $request, string $role)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        abort_unless(isset(self::LABELS[$role]), 404);
        $customised = array_values(array_diff((array) $this->settings->value('roles', 'customised', []), [$role]));
        $this->settings->put('roles', ['customised' => $customised], $request->user()->id);
        $this->sync($role, RolePermissionSeeder::defaults()[$role] ?? []);

        return $this->index($request);
    }

    protected function sync(string $role, array $permissions): void
    {
        foreach ($permissions as $p) {
            RolePermission::query()->firstOrCreate(['role_key' => $role, 'permission_key' => $p]);
        }
        RolePermission::query()->where('role_key', $role)->whereNotIn('permission_key', $permissions ?: ['__none__'])->get()->each->delete();
        app(PermissionRegistry::class)->forget();
    }
}
