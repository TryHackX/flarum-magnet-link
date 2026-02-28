<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('magnet_custom_names', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('magnet_link_id');
    $table->unsignedInteger('post_id');
    $table->unsignedInteger('user_id');
    $table->string('custom_name', 500);
    $table->timestamps();

    // Jedna niestandardowa nazwa na magnet per post
    $table->unique(['magnet_link_id', 'post_id']);

    $table->foreign('magnet_link_id')
        ->references('id')
        ->on('magnet_links')
        ->onDelete('cascade');
});
