<?php

namespace App\Listeners;

use Tenancy\Hooks\Migration\Events\ConfigureMigrations;
use Tenancy\Tenant\Events\Deleted;

class ConfigureTenantMigrations
{
    public function handle(ConfigureMigrations $event): void
    {
        $event->path(database_path('migrations/tenant'));

        if($event->event instanceof Deleted) {
            $event->disable();
        }
    }
}
