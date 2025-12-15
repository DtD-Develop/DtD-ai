<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    protected $table = "feedbacks";
    protected $fillable = [
        "conversation_id",
        "message_id",
        "user_id",
        "question",
        "answer",
        "score",
        "meta",
    ];
    protected $casts = [
        "meta" => "array",
    ];
}
