<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('magnet_links', function (Blueprint $table) {
    $table->increments('id');
    $table->string('token', 64)->unique()->index(); // SHA256 token (nie hash magnetu!)
    $table->string('info_hash', 40)->index(); // Hash info z magnetu (btih)
    $table->text('magnet_uri'); // Pełny magnet link (zaszyfrowany lub nie)
    $table->string('name', 500)->nullable(); // Nazwa torrenta
    $table->unsignedInteger('click_count')->default(0);
    $table->timestamps();
});
