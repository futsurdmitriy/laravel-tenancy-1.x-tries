<?php

namespace App\Listeners;

use Tenancy\Hooks\Hostname\Contracts\HostnameHandler;
use Tenancy\Tenant\Events\Event;
use Illuminate\Support\Facades\Mail;

class TenantFQDNHandler implements HostnameHandler
{
    public function handle(Event $event): void
    {
        // here should be logic for handling hostname of a tenant
//        if(!$this->hasValidDomains($event->tenant)){
//            Mail::to($event->tenant->email)->send(new DomainsNotValid($event->tenant->getHostnames()));
//        }
    }
}
