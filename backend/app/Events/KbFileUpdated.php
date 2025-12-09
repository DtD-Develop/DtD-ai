<?php

namespace App\Events;

use App\Models\KbFile;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class KbFileUpdated implements ShouldBroadcast
{
    public $file;

    public function __construct(KbFile $file)
    {
        $this->file = $file;
    }

    public function broadcastOn()
    {
        return new Channel("kb-files");
    }
}
