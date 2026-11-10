<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domains\Tenancy\Models\Tenant
 */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'parent_tenant_id' => $this->parent_tenant_id,
            'type' => $this->type,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'custom_domain' => $this->custom_domain,
            'subdomain' => $this->subdomain,
            'status' => $this->status,
            'max_sub_tenant_depth' => $this->max_sub_tenant_depth,
            'template_id' => $this->template_id,
            'logo_url' => $this->logo_url,
            'support_email' => $this->support_email,
            'support_phone' => $this->support_phone,
            'business_address' => $this->business_address,
            'business_registration_no' => $this->business_registration_no,
            'dns_status' => $this->dns_status,
            'dns_verified_at' => $this->dns_verified_at,
            'enabled_modules' => $this->enabled_modules,
            'social_links' => $this->config['social_links'] ?? null,
            'app_links' => $this->config['app_links'] ?? null,
            'certifications' => $this->config['certifications'] ?? null,
            'platform_settings' => $this->config['platform_settings'] ?? null,
            'theme' => $this->config['theme'] ?? null,
            'icon_style' => $this->config['icon_style'] ?? null,
            'about_content' => $this->config['about_content'] ?? null,
            'contact_content' => $this->config['contact_content'] ?? null,
            'created_at' => $this->created_at,
        ];
    }
}
