<?php

namespace App\Domains\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class CmsPage extends Model
{
    protected $fillable = ['tenant_id', 'slug', 'title', 'body', 'meta_description', 'is_published', 'updated_by'];

    protected $casts = ['is_published' => 'boolean'];
}
