<?php

namespace App\Domains\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContentItem extends Model
{
    public const TYPES = ['banner', 'offer', 'faq', 'seo', 'popular_route'];

    protected $fillable = ['tenant_id', 'type', 'module', 'title', 'body', 'image_url', 'link_url', 'data', 'sort_order', 'starts_at', 'ends_at', 'is_active', 'updated_by'];

    protected $casts = ['data' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];

    public function scopeLive(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
