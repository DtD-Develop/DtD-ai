<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KbFile extends Model
{
    protected $fillable = [
        "source",
        "filename",
        "original_name",
        "mime_type",
        "size_bytes",
        "storage_path",
        "status",
        "progress",
        "auto_tags",
        "tags",
        "chunks_count",
        "error_message",
        "summary",
    ];

    protected $casts = [
        "auto_tags" => "array",
        "tags" => "array",
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(KbChunk::class);
    }
}
