<?php

/*
 * Prepares the throw-away Flarum install the integration suite runs against.
 * Defaults to SQLite (no server needed); point DB_DRIVER / DB_HOST / DB_PORT /
 * DB_DATABASE / DB_USERNAME / DB_PASSWORD / DB_PREFIX at a MySQL/MariaDB or
 * PostgreSQL test database to run the suite there instead. NEVER point it at a
 * production database — every table is dropped first.
 */

use Flarum\Testing\integration\Setup\SetupScript;

require __DIR__.'/../../vendor/autoload.php';

$setup = new SetupScript();

$setup->run();

// Core's translation catalogue cache writes here; flarum/testing doesn't create it.
@mkdir($setup->tmpDir().'/storage/locale', 0777, true);
