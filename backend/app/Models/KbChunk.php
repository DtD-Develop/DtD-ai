<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KbChunk extends Model
{
    protected $table = "kb_chunks";

    protected $fillable = ["kb_file_id", "chunk_index", "content", "point_id"];

    protected $casts = [
        "chunk_index" => "integer",
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(KbFile::class, "kb_file_id");
    }
}
