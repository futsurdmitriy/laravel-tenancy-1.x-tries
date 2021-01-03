<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tenancy\Tenant\Events\Updated;

class MigrateTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:tenants';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate all the tenants';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        \App\Models\Customer::cursor()->each(
            function ($tenant) {
                event(new Updated($tenant));
            }
        );
    }
}
