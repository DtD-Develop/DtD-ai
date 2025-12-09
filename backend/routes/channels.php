<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Register any event broadcasting channels your application supports.
| The given channel authorization callbacks are used to check if an
| authenticated user can listen to the channel.
|
*/

// ตัวอย่าง public channel สำหรับงานของคุณ (ปรับตามจริง)
Broadcast::channel("kb-files", fn() => true);
