<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KbFile extends Model
{
    protected $table = "kb_files";

    protected $fillable = [
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
    ];

    protected $casts = [
        "size_bytes" => "integer",
        "progress" => "integer",
        "chunks_count" => "integer",
        "auto_tags" => "array",
        "tags" => "array",
    ];

    public const STATUS_UPLOADED = "uploaded";
    public const STATUS_PARSING = "parsing";
    public const STATUS_TAGGED = "tagged";
    public const STATUS_EMBEDDING = "embedding";
    public const STATUS_READY = "ready";
    public const STATUS_FAILED = "failed";

    public function chunks(): HasMany
    {
        return $this->hasMany(KbChunk::class, "kb_file_id");
    }
}
