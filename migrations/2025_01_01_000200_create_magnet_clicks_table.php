<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('magnet_clicks', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('magnet_link_id');
    $table->string('ip_address', 45)->index(); // IPv6 może mieć do 45 znaków
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedInteger('post_id')->nullable();
    $table->timestamp('click_time')->useCurrent();
    
    // Indeksy dla szybkiego wyszukiwania
    $table->index(['magnet_link_id', 'ip_address', 'click_time']);
    $table->index(['ip_address', 'click_time']);
    
    // Klucz obcy
    $table->foreign('magnet_link_id')
        ->references('id')
        ->on('magnet_links')
        ->onDelete('cascade');
});
