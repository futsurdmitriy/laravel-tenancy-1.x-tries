<?php

namespace App\Listeners;

use \Tenancy\Hooks\Hostname\Hooks\HostnamesHook;

class ConfigureHostnameHandlers
{
    public function handle(HostnamesHook $event)
    {
        $event->registerHandler(new TenantFQDNHandler());
    }
}
