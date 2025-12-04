<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        "user_id",
        "title",
        "mode",
        "is_title_generated",
        "last_message_at",
    ];

    protected $casts = [
        "is_title_generated" => "boolean",
        "last_message_at" => "datetime",
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
