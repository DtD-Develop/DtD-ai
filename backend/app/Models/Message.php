<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        "conversation_id",
        "role",
        "content",
        "score",
        "is_training",
        "meta",
    ];

    protected $casts = [
        "is_training" => "boolean",
        "meta" => "array",
    ];
}
