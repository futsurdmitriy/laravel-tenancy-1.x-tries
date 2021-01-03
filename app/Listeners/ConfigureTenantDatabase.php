<?php

namespace App\Listeners;

use Tenancy\Hooks\Database\Events\Drivers\Configuring;

class ConfigureTenantDatabase
{
    public function handle(Configuring $event)
    {
        dump(__FILE__.__METHOD__.__LINE__);

        $overrides = array_merge(
            [
                'host'=>'%',
    //        'host' => $customer->fqdn,
    //            'database' => $customer->uuid,
    //            'username' => $customer->uuid,
    ////            'password' => resolve(ProvidesPassword::class)->__invoke($tenant),
    //            'password' => 'secret',
    //            'driver' =>  \config('database.connections.mysql.driver'),
            ],
            $event->defaults($event->tenant)
        );
        $event->useConnection('mysql', $overrides);
    }
}
