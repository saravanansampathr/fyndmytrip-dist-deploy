<?php

return [
    // Hard ceiling enforced by TenantService regardless of any individual
    // tenants.max_sub_tenant_depth override — see Database Design Document §3.1.
    'platform_max_sub_tenant_depth' => (int) env('TENANCY_PLATFORM_MAX_SUB_TENANT_DEPTH', 8),

    // Default applied to tenants.max_sub_tenant_depth when a tenant is created
    // without specifying its own value — see Master Brief §3.
    'default_max_sub_tenant_depth' => (int) env('TENANCY_DEFAULT_MAX_SUB_TENANT_DEPTH', 5),

    // Default *.fyndmytrip.com hosting suffix used to resolve a tenant from the
    // request Host header when no custom_domain match is found.
    'default_hosting_suffix' => env('TENANCY_DEFAULT_HOSTING_SUFFIX', '.fyndmytrip.com'),
];
