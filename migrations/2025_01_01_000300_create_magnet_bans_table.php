<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('magnet_bans', function (Blueprint $table) {
    $table->increments('id');
    $table->string('ip_address', 45)->unique()->index();
    $table->timestamp('ban_time')->useCurrent();
});
