<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerRootSeeder extends Seeder
{
    /**
     * Run the customer root database seeder.
     *
     * @return void
     */
    public function run()
    {
        $hostname = Customer::firstOrNew(['fqdn' => 'localhost']);

        if (!$hostname->exists) {
            $hostname->fill([
                'fqdn' => 'localhost',
            ])->save();
        }
    }
}
