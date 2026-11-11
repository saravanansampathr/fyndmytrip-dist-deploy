<?php

namespace App\Domains\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class MediaFile extends Model
{
    protected $fillable = ['tenant_id', 'path', 'original_name', 'mime', 'size', 'is_public', 'uploaded_by'];

    protected $casts = ['is_public' => 'boolean'];

    /** Public files are served by the API itself (no storage:link needed on cPanel). */
    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/media/'.$this->id.'/'.rawurlencode(basename($this->original_name ?: $this->path));
    }
}
