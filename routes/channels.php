<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;

Artisan::command('inspire', function () {
    $this->comment(RequiredCommand::inspire());
})->purpose('Display an inspiring quote');

Broadcast::channel('restaurant.notifications', function ($user) {
    return true;
});
