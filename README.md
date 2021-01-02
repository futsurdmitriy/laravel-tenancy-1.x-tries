# Laravel Multitenant


```bash
## Create your project folder and install laradock (a docker for laravel)

# Store project name to a variable to be easily changed
PROJECT_NAME="laravel-tenancy-1.x-tries";

# Create project folder and switch to it
mkdir $PROJECT_NAME;
cd $PROJECT_NAME;

# Install laradock
git init
git submodule add https://github.com/Laradock/laradock.git
cd laradock
cp env-example .env

# Enable PHP exif used by Voyager Media manager
sed -i "s/PHP_FPM_INSTALL_EXIF=false/PHP_FPM_INSTALL_EXIF=true/g" .env

# Run docker containers and login into the workspace container
    # > Building docker containers can take significant time for the first run.
    # > We run phpmyadmin container to have a database management UI tool.
        # Available under localhost:8081
        # System: MySQL
        # Server: mysql
        # Username: default
        # Password: secret
docker-compose up -d mysql nginx phpmyadmin
docker-compose exec --user=laradock workspace bash

```

You should see smth. like `laradock@5326c549f4cb:/var/www` in your terminal. That means you are logged in into the docker linux container. We will work next here.

### Laravel Tenancy 

```bash

#Install LARAVEL and TENANCY of particular version to make it work as it was in a moment of writing
LARAVEL_VERSION="8.12"
TENANCY_VERSION="1.2"

# 01 Create laravel project.
# We need an intermediate tmp folder as our current folder is not
# empty (contains laradoc folder) and laravel installation would fail otherwise
# If you don't use docker, just install a new laravel project and
# change directory to it
composer create-project --prefer-dist laravel/laravel tmp $LARAVEL_VERSION

# Enable hidden files move
shopt -s dotglob

# Move laravel project files from ./tmp to the project root
mv ./tmp/* .

# Remove the temporary folder.
rm -rf ./tmp

## Update default database connection

## Manual:
# Edit you .env file DB connection like this
# NOTE! DB_HOST may differs for different server configurations. Usual values are `localhost`, `127.0.0.1`

# Mysql
# DB_CONNECTION=system
# DB_HOST=mysql
# DB_PORT=3306
# DB_DATABASE=default
# DB_USERNAME=default
# DB_PASSWORD=secret
# LIMIT_UUID_LENGTH_32=true

## Script way
sed -i "s/DB_CONNECTION=mysql/DB_CONNECTION=system/g" .env
sed -i "s/DB_HOST=127\.0\.0\.1/DB_HOST=mysql/g" .env
echo '' >> .env
echo '# Mysql additional setup' >> .env
echo 'LIMIT_UUID_LENGTH_32=true' >> .env
echo '' >> .env


## Install the tenancy package and rest of necessary packages
composer require "tenancy/tenancy:"$TENANCY_VERSION
composer require "ramsey/uuid":"4.1.1"
composer require "laravel/tinker": "2.5.0"

## When the installation is done, create a model and a migration for your Tenant model. We will use a Customer model.
php artisan make:model Customer -mc
```

### After you've created your model, make migration look like this:

```php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 32)->unique();
            $table->string('fqdn')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('customers');
    }
}

```
###Skip this, it's for future, it is here to not lose time that was spent on searching this info :(
   For later on times ... for our models to have uuid's correct implementation here is example of how we can make it.
   Sources:
   https://dev.to/wilburpowery/easily-use-uuids-in-laravel-45be
   https://medium.com/binary-cabin/automatically-generating-a-uuid-on-your-laravel-models-b8b9c3599e2b

   Create trait "UsesUuid" with code and then you will be able to use it in your models:
```php
namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait UsesUuid
{
    protected static function bootUsesUuid()
    {
        static::creating(function ($model) {
            if (! $model->getKey()) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function getIncrementing()
    {
        return false;
    }

    public function getKeyType()
    {
        return 'string';
    }
}
``` 
### Now back to model. After you've created your model and changed the migration, make Customer model look like this:

```php

namespace App\Models;

#use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tenancy\Identification\Concerns\AllowsTenantIdentification;
use Tenancy\Identification\Contracts\Tenant;
use Tenancy\Identification\Drivers\Http\Contracts\IdentifiesByHttp;
use Tenancy\Tenant\Events;
use Tenancy\Affects\Connections\Support\Traits\OnTenant;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use \Tenancy\Hooks\Hostname\Contracts\HasHostnames;

class Customer extends Model implements Tenant, IdentifiesByHttp, HasHostnames
{
    use AllowsTenantIdentification;

    /*
        Preparing for Lifecycle Hooks
        If you have no idea what the Lifecycle or Lifecycle Hooks are, we recommend you to read about those first(https://tenancy.dev/docs/tenancy/1.x/hooks-general).
    
        To prepare your tenant for lifecycle hooks, we will fire off some events for when a model is:

        Created
        Updated
        Deleted

        We can do this with Laravel's really handy dispatchesEvents variable on models. It allows you to define specific events that should be fired when a model is being made.

        Now when you're creating a model like you normally do, it will fire off the Events for tenancy. When Tenancy receives these events, it will start firing Lifecycle Hooks.
    */
    protected $dispatchesEvents = [
        'created' => Events\Created::class,
        'updated' => Events\Updated::class,
        'deleted' => Events\Deleted::class,
    ];

    public function getHostnames():array
    {
        return [
            $this->fqdn
        ];
    }

    public static function boot()
    {
        parent::boot();

        self::creating(
            function ($model) {
                $uuid = Uuid::uuid4()->toString();
//            dump(__FILE__.__METHOD__.__LINE__);
//            dd(\Config::get('custom.limit_uuid_length_32'));
                // fuda dummy
                if (env('LIMIT_UUID_LENGTH_32', true)) {
                    $uuid = str_replace('-', null, $uuid);
                }
                $model->uuid = $uuid;
            }
        );
    }

    /**
     * The attribute of the Model to use for the key.
     *
     * @return string
     */
    public function getTenantKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The actual value of the key for the tenant Model.
     *
     * @return string|int
     */
    public function getTenantKey()
    {
        return $this->uuid;
    }

    /**
     * A unique identifier, eg class or table to distinguish this tenant Model.
     *
     * @return string
     */
    public function getTenantIdentifier(): string
    {
        return get_class($this);
    }

    /**
     * Specify whether the tenant model is matching the request.
     *
     * @param Request $request
     * @return Tenant
     */
    public function tenantIdentificationByHttp(Request $request): ?Tenant
    {
        return $this->query()
            ->where('fqdn', $request->getHttpHost())
            ->first();
    }
}
```

You've now succesfully created a simple tenant model!

###Preparing for Identification

Tenancy needs to be aware of the tenant that you have just created in order to identify it. We will do this by registering the model into the TenantResolver (which is simply a class responsible for identifying tenants).

We can easily register a model by using a callback in one of Laravel's default Service Providers. Let's use the app/Providers/AppServiceProvider in this case.

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Customer;
use Tenancy\Identification\Contracts\ResolvesTenants;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->resolving(ResolvesTenants::class, function (ResolvesTenants $resolver){
            $resolver->addModel(Customer::class);
            return $resolver;
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
```
Now when the TenantResolver is being made, it will register the model. Really easy and really handy.
Tip: If you have multiple Tenant models, you could register all of them like this.

# Multi Database Setup
###Introduction
Setting up a multi database tenancy setup can be quite difficult. There's a lot of different (moving) pieces and that's exactly why we made this tutorial. In this tutorial we will talk about:

*  Setting up tenancy so it will create a new DB for each tenant
*  Changing the connection whenever a tenant is identified

!!! **This tutorial assumes that you have a basic setup (meaning a tenant model that is firing hooks )**. !!!

###Creation of the database
Creating of the database is a collaboration between 2 different type of packages:

    A Lifecycle Hook (tenancy/hooks-database in this case)
    A Database Driver (tenancy/db-driver-mysql for example). There are more Database Driver to choose from, in case you need one for a different Database.

###Setting Up
**If you installed the tenancy/tenacy package in a way that was described above then you need just to check if tenancy/hooks-database package is present in composer.lock file and if package is registered in /config/app.php file** 

/config/app.php file 
```php
        //          OTHER CODE

'providers' => [

        //          OTHER CODE

        // Necessary package service providers 
        /*
         * Package Service Providers...
         */
        Tenancy\Affects\Broadcasts\Provider::class,
        Tenancy\Affects\Cache\Provider::class,
        Tenancy\Affects\Configs\Provider::class,
        Tenancy\Affects\Connections\Provider::class,
        Tenancy\Affects\Filesystems\Provider::class,
        Tenancy\Affects\Logs\Provider::class,
        Tenancy\Affects\Mails\Provider::class,
        Tenancy\Affects\Models\Provider::class,
        Tenancy\Affects\Routes\Provider::class,
        Tenancy\Affects\URLs\Provider::class,
        Tenancy\Affects\Views\Provider::class,

        Tenancy\Hooks\Database\Provider::class,
        Tenancy\Hooks\Migration\Provider::class,
        Tenancy\Hooks\Hostname\Provider::class,

        Tenancy\Database\Drivers\Mysql\Provider::class,
        Tenancy\Identification\Drivers\Environment\Providers\IdentificationProvider::class,

        //          OTHER CODE
    ],

        //          OTHER CODE
```
composer.lock
```php
//          OTHER CODE
{
            "name": "tenancy/tenancy",
        //          OTHER CODE
            "replace": {
        //          OTHER CODE
                "tenancy/hooks-database": "self.version",
        //          OTHER CODE          
            },
        //          OTHER CODE          
}
//          OTHER CODE          
```

**Or if it's missing do the following:**
You can install this package by simply running the following command in your project:

```bash
composer require tenancy/hooks-database
```

After that is done, we will focus on configuring the creation of the database.

Configuring the database is fairly easy. It starts of with creating a new listener that listens to the Tenancy\Hooks\Database\Events\Drivers\Configuring event. Once you've created a listener, we will go to the actual configuration.

There are multiple ways to configure the Database Creation. In this tutorial we will use tenancy's default functionality in order to make it work. In your listener use the following code:

```php
namespace App\Listeners;

use Tenancy\Hooks\Database\Events\Drivers\Configuring;

class ConfigureTenantDatabase
{
    public function handle(Configuring $event)
    {
        $overrides = array_merge(
            [
                'host'=>'%',
                // -----for future use ------
                //'host' => $customer->fqdn,
                //'database' => $customer->uuid,
                //'username' => $customer->uuid,
                ////'password' => resolve(ProvidesPassword::class)->__invoke($tenant),
                //'password' => 'secret',
                //'driver' =>  \config('database.connections.mysql.driver'),
                // -----for future use ------
            ],
            $event->defaults($event->tenant)
        );
        $event->useConnection('mysql', $overrides);
    }
}
```
What this code will do is quite simple:

    We are overridng the connection setting of tenant by doing merge of special array with 'host' => '%' and default tenant configs.
    It will use the mysql configuration provided in the config/database.php of your application.
    It will use the tenant_key for a database name and database username
    It will use some information on the tenant for generating a secret password
    It will use our overrided configs.

You've now completely configured the creating of the database, but now it's time to actually create the database.

**If you installed tenancy/tenancy then you might skip installing 'tenancy/db-driver-mysql', if not then simply install the database driver of your choice (we recommend tenancy/db-driver-mysql) through composer.**

You should now be able to create a new Database, by simply creating a new tenant.

###Changing the connection
There's one package responsible for changing of the connection and that is affects-connections. Once a tenant is identified, it will creating a new tenant connection which will direct to the database that we setup earlier. 
**You need to skip installation if you have installed tenancy/tenacy package.**

So let's get started on this installation.

First, install the package by running:

```bash
composer require tenancy/affects-connections
```

After the installation, we will focusing on Resolving the connection. This is simply us telling tenancy what class it should use in order to get a configuration for the connection. Create a new listener that listens to the Tenancy\Affects\Connections\Events\Resolving event.

Important: This event expects to return an instance/class that will provide the configuration of the connection, not the configuration itself.

In this example, we will tell tenancy that this listener is responsible for configuring that connection. We will implement the ProvidesConfiguration contract which is responsible for providing a connection configuration to Tenant and we will fire off a Configuring event in order to configure connection in a different listener.

```php
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
        $config = [
            //'host' => $customer->fqdn,
            //'database' => $customer->uuid,
            //'username' => $customer->uuid,
            ////'password' => resolve(ProvidesPassword::class)->__invoke($tenant),
            //'password' => 'secret',
            //'driver' =>  \config('database.connections.mysql.driver'),
        ];
        event(new Configuring($tenant, $config, $this));

        return $config;
    }
}
```

After you've done this, we will move to the actual configuring of the connection. Create a new listener that listens to the Tenancy\Affects\Connections\Events\Drivers\Configuring event. This event is basically the same event as the event we used for the database. This allows us to use the exact same code that we used then in order to configure the database. You can see an example below.

```php
namespace App\Listeners;

use Tenancy\Affects\Connections\Events\Drivers\Configuring;

class ConfigureTenantConnection
{
    public function handle(Configuring $event)
    {
        $event->useConnection('mysql', $event->defaults($event->tenant));
    }
}
```

##Migration Command

##Customer Controller

##VerifyCSRFToken exclude

##Hostnames Handler

##EventServiceProvider activation

##Advice for database troubles with native password and authentication methods + creation of user and previledges

##app.php

##custom.php for uuid length

##database.php for mysql modes + laradock mysql config for user native password creation 

## tenant migrations folder and copy of migration files

## sessions table migration

## customer root seeder

## change of docker-compose for laradock to make command for - "--default-authentication-plugin=mysql_native_password" 

## resources + views + routes for forms to create customers


###Done!
You're all done now, congratulations! You've now setup your own multi tenancy setup using the Tenancy Ecosystem!

You should have the following result:

    Creating a new Customer (or your own tenant model), will result in a new database creation
    Switching to the tenant will create a new tenant connection.



_________________________________________________________________________

```bash
## Copy user tables migrations to tenant folder to have per-tenant user tables
# Make `database/migrations/tenant` folder
mkdir database/migrations/tenant
# Copy `2014_10_12_000000_create_users_table.php` and `2014_10_12_100000_create_password_resets_table.php`
# to the newly created folder so we will create user tables per tenant.
cp database/migrations/2014_10_12_000000_create_users_table.php database/migrations/tenant/
cp database/migrations/2014_10_12_100000_create_password_resets_table.php database/migrations/tenant/

# Run database migrations for the system DB only.
# After that you'll find the tables in your `default` database:
# `hostnames`, `migrations`, `websites`
php artisan migrate --database=system





# Create Hostname model for system Voyager
php artisan make:model Hostname

# Create a system domain seeder and run it
# Don't forget to replace 'voyager.test' with your system domain if needed.
cat << 'EOF' > database/seeders/HostnamesTableSeeder.php
<?php

namespace Database\Seeders;

use App\Models\Hostname;
use Illuminate\Database\Seeder;

class HostnamesTableSeeder extends Seeder
{
    /**
     * Auto generated seed file.
     */
    /**
     * Auto generated seed file.
     *
     * @return void
     */
    public function run()
    {
        $hostname = Hostname::firstOrNew(['fqdn' => 'voyager.test']);

        if (!$hostname->exists) {
            $hostname->fill([
                    'fqdn' => 'voyager.test',
                ])->save();
        }
    }
}

EOF

composer dump-autoload
php artisan db:seed --class=HostnamesTableSeeder


class TenantLoginAction extends AbstractAction
{
    public function getTitle()
    {
        return __('voyager::generic.login');
    }


php artisan config:clear

```


### Project setup

If using docker, you should be logged in inside the docker environment 
for now.

Otherwise go to the project root folder.

```bash
composer install;
php artisan vendor:publish --tag=tenancy

php artisan migrate --database=system

composer dump-autoload
php artisan db:seed --class=HostnamesTableSeeder
php artisan voyager:install --with-dummy
php artisan db:seed --class=HostnamesBreadSeeder
php artisan db:seed --class=PermissionRoleTableSeeder


php artisan config:clear

```

### Check results



## Working with docker

Go to your project folder and go to `laradock` subfolder.

### Stop docker

```bash
docker-compose down
```

### Run docker

Do not run just `docker-compose up`. Laradock contains dozens of containers and will try to run all of them.

Run only needed containers.

```bash
docker-compose up -d mysql nginx
```

If you need, you can also run `phpmyadmin`

```bash
docker-compose up -d phpmyadmin
```
