<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domains\Auth\Models\ApiPartnerCredential
 *
 * Never carries client_secret_hash (the model already hides it) and never
 * carries a plaintext secret — that's returned exactly once, outside this
 * resource, by ApiPartnerCredentialController::store()/regenerateSecret(),
 * the only two moments a secret is ever knowable again after creation.
 */
class ApiPartnerCredentialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'client_id' => $this->client_id,
            'allowed_domain' => $this->allowed_domain,
            'allowed_scopes' => $this->allowed_scopes,
            'rate_limit_per_min' => $this->rate_limit_per_min,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
