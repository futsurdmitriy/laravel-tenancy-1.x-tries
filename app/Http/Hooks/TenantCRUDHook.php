<?php

namespace App\Http\Hooks;

use Tenancy\Lifecycle\Hook;


class TenantCRUDHook extends Hook
{
    public function fire():void
    {
        // Created Event
        if($this->event instanceof \Tenancy\Tenant\Events\Created)
        {
            dump("Running hook for the creation of a tenant");
            return;
        }
        // Deleted Event
        if($this->event instanceof \Tenancy\Tenant\Events\Deleted)
        {
            dump("Running hook for the deletion of a tenant");
            return;
        }
    }
}
