<?php

namespace App\Listeners;

use App\Models\Customer;
use Tenancy\Identification\Contracts\Tenant;
use Tenancy\Affects\Connections\Events\Resolving;
use Tenancy\Affects\Connections\Events\Drivers\Configuring;
use Tenancy\Affects\Connections\Contracts\ProvidesConfiguration;
use Tenancy\Support\Contracts\ProvidesPassword;

class ResolveTenantConnection implements ProvidesConfiguration
{
    public function handle(Resolving $event)
    {
        return $this;
    }

    public function configure(Tenant $tenant): array
    {
        $customer = Customer::where('uuid', $tenant->getTenantKey())->first();
        dump(__FILE__.__METHOD__.__LINE__);
        dump($customer);
        $config = [
//            'host' => $customer->fqdn,
//            'database' => $customer->uuid,
//            'username' => $customer->uuid,
////            'password' => resolve(ProvidesPassword::class)->__invoke($tenant),
//            'password' => 'secret',
//            'driver' =>  \config('database.connections.mysql.driver'),
        ];
        dump($config);
        event(new Configuring($tenant, $config, $this));

        return $config;
    }
}
