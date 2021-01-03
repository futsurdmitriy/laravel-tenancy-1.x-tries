<?php

namespace App\Listeners;

use Tenancy\Identification\Events\NothingIdentified;

class NoTenantIdentified
{
    public function handle(NothingIdentified $event)
    {
        dump(__FILE__.__METHOD__.__LINE__);
        dump("Tenant not identified");
//        abort(404);
    }
}
