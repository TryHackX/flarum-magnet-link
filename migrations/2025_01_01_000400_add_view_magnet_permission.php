<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    // Domyślnie wszyscy zarejestrowani użytkownicy mogą widzieć magnet linki
    'tryhackx-magnet-link.viewMagnetLinks' => Group::MEMBER_ID,
]);
