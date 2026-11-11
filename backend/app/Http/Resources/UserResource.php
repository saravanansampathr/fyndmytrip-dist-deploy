<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'status' => $this->status,
            // A guest checkout session (see GuestCheckoutService), not a
            // signed-in account.
            'is_guest' => (bool) $this->is_guest,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            // The logged-in user's own tenant's "fully custom" brand theme
            // (font/colors/button shape — see WhiteLabelController::update())
            // riding along on every /auth/login and /auth/me response so the
            // frontend's ThemeProvider can apply it the instant a session
            // starts, without a second round-trip.
            'tenant_theme' => $this->tenant?->config['theme'] ?? null,
        ];
    }
}
