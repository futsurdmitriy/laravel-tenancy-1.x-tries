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
    # > We run adminer container to have a database management UI tool.
        # Available under localhost:8080
        # System: PostgreSQL
        # Server: postgres
        # Username: default
        # Password: secret
docker-compose up -d mysql nginx phpmyadmin
docker-compose exec --user=laradock workspace bash

```

You should see smth. like `laradock@5326c549f4cb:/var/www` in your terminal. That means you are logged in into the docker linux container. We will work next here.

### Laravel Tenancy 

```bash
LARAVEL_VERSION="8.*"
TENANCY_VERSION="1.*"
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


## Install package and configure the mulitenancy package
composer require "tenancy/tenancy:"$TENANCY_VERSION

## When the installation is done, create a model and a migration for your Tenant model. We will use a Customer model.

php artisan make:model Customer -mc
```
```php
## After you've created your model, make sure it implements the Tenancy\Identification\Contracts\Tenant contract.

use Illuminate\Database\Eloquent\Model;
use Tenancy\Identification\Concerns\AllowsTenantIdentification;
use Tenancy\Identification\Contracts\Tenant;

class Customer extends Model implements Tenant
{
    use AllowsTenantIdentification;
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
}
```
Now when the TenantResolver is being made, it will register the model. Really easy and really handy.
Tip: If you have multiple Tenant models, you could register all of them like this.

###Preparing for Lifecycle Hooks
If you have no idea what the Lifecycle or Lifecycle Hooks are, we recommend you to read about those first(https://tenancy.dev/docs/tenancy/1.x/hooks-general).

To prepare your tenant for lifecycle hooks, we will fire off some events for when a model is:

    Created
    Updated
    Deleted

We can do this with Laravel's really handy dispatchesEvents variable on models. It allows you to define specific events that should be fired when a model is being made. Here's an example of how to make it work for our Customer model: app/Models/Customer.php

```php
use Illuminate\Database\Eloquent\Model;
use Tenancy\Identification\Concerns\AllowsTenantIdentification;
use Tenancy\Identification\Contracts\Tenant;
use Tenancy\Tenant\Events;

class Customer extends Model implements Tenant
{
    use AllowsTenantIdentification;

    protected $dispatchesEvents = [
        'created' => Events\Created::class,
        'updated' => Events\Updated::class,
        'deleted' => Events\Deleted::class,
    ];
}
```
Now when you're creating a model like you normally do, it will fire off the Events for tenancy. When Tenancy receives these events, it will start firing Lifecycle Hooks.

# Multi Database Setup
###Introduction
Setting up a multi database tenancy setup can be quite difficult. There's a lot of different (moving) pieces and that's exactly why we made this tutorial. In this tutorial we will talk about:

Setting up tenancy so it will create a new DB for each tenant
Changing the connection whenever a tenant is identified
This tutorial assumes that you have a basic setup (meaning a tenant model that is firing hooks).
###Creation of the database
Creating of the database is a collaboration between 2 different type of packages:

    A Lifecycle Hook (tenancy/hooks-database in this case)
    A Database Driver (tenancy/db-driver-mysql for example). There are more Database Driver to choose from, in case you need one for a different Database.
###Setting Up

We will first focus on installing hooks-database. You can install this package by simply running the following command in your project:

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
        $event->useConnection('mysql', $event->defaults($event->tenant));
    }
}
```
What this code will do is quite simple:

    It will use the mysql configuration provided in the config/database.php of your application.
    It will use the tenant_key for a database name and database username
    It will use some information on the tenant for generating a secret password
You've now completely configured the creating of the database, but now it's time to actually create the database.

Creating the database is really easy, simply install the database driver of your choice (we recommend tenancy/db-driver-mysql) through composer.

You should now be able to create a new Database, by simply creating a new tenant.

###Changing the connection
There's one package responsible for changing of the connection and that is affects-connections. Once a tenant is identified, it will creating a new tenant connection which will direct to the database that we setup earlier. So let's get started on this installation.

First, install the package by running:

```bash
composer require tenancy/affects-connections
```

After the installation, we will focusing on Resolving the connection. This is simply us telling tenancy what class it should use in order to get a configuration for the connection. Create a new listener that listens to the Tenancy\Affects\Connections\Events\Resolving event.

Important: This event expects to return an instance/class that will provide the configuration of the connection, not the configuration itself.

In this example, we will tell tenancy that this listener is responsible for configuring that connection. We do this by simply returning the instance like shown below.

```php
namespace App\Listeners;

use Tenancy\Affects\Connections\Events\Resolving;

class ResolveTenantConnection
{
    public function handle(Resolving $event)
    {
        return $this;
    }
}
```

However, this class does not implements the ProvidesConfiguration contract which is responsible for providing a connection configuration to Tenant, so we will do that.

```php
namespace App\Listeners;

use Tenancy\Identification\Contracts\Tenant;
use Tenancy\Affects\Connections\Contracts\ProvidesConfiguration;
use Tenancy\Affects\Connections\Events\Resolving;

class ResolveTenantConnection implements ProvidesConfiguration
{
    public function handle(Resolving $event)
    {
        return $this;
    }

    public function configure(Tenant $tenant): array
    {
        return [];
    }
}
```
Right now we're providing an empty array as a connection setting. This won't work, and we will have to implement some logic in order to provide an actual connection configuration. You can do 2 different types of setups in this case:

    You can put all the logic for a connection here.
    You can fire off a Configuring event in order to configure it in a different listener.
In this tutorial we decided to fire off a Configuring event. You can do that by looking at the example below.

```php
namespace App\Listeners;

use Tenancy\Identification\Contracts\Tenant;
use Tenancy\Affects\Connections\Events\Resolving;
use Tenancy\Affects\Connections\Events\Drivers\Configuring;
use Tenancy\Affects\Connections\Contracts\ProvidesConfiguration;

class ResolveTenantConnection implements ProvidesConfiguration
{
    public function handle(Resolving $event)
    {
        return $this;
    }

    public function configure(Tenant $tenant): array
    {
        $config = [];

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

# 03 Add a helper class, which will do the tenant creation/deletions job

## Here is the logic of what to install per-tenant. 

cat << 'EOF' > app/Tenant.php
<?php

namespace App;

use Hyn\Tenancy\Environment;
use Hyn\Tenancy\Models\Hostname;
use Hyn\Tenancy\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Hyn\Tenancy\Contracts\Repositories\HostnameRepository;
use Hyn\Tenancy\Contracts\Repositories\WebsiteRepository;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * @property Website website
 * @property Hostname hostname
 * @property User admin
 */
class Tenant
{
    public function __construct(Website $website = null, Hostname $hostname = null, User $admin = null)
    {
        $this->website = $website;
        $this->hostname = $hostname;
        $this->admin = $admin;
    }

    public static function getRootFqdn()
    {
        return Hostname::where('website_id', null)->first()->fqdn;
    }

    public static function delete($name)
    {
        // $baseUrl = env('APP_URL_BASE');
        // $name = "{$name}.{$baseUrl}";
        if ($tenant = Hostname::where('fqdn', $name)->firstOrFail()) {
            app(HostnameRepository::class)->delete($tenant, true);
            app(WebsiteRepository::class)->delete($tenant->website, true);
            return "Tenant {$name} successfully deleted.";
        }
    }

    public static function deleteById($id)
    {
        if ($tenant = Hostname::where('id', $id)->firstOrFail()) {
            app(HostnameRepository::class)->delete($tenant, true);
            app(WebsiteRepository::class)->delete($tenant->website, true);
            return "Tenant with id {$id} successfully deleted.";
        }
    }

    public static function deleteByFqdn($fqdn)
    {
        if ($tenant = Hostname::where('fqdn', $fqdn)->firstOrFail()) {
            app(HostnameRepository::class)->delete($tenant, true);
            app(WebsiteRepository::class)->delete($tenant->website, true);
            return "Tenant {$fqdn} successfully deleted.";
        }
    }

    public static function registerTenant($name, $email = null, $password = null): Tenant
    {
        // Convert all to lowercase
        $name = strtolower($name);
        $email = strtolower($email);

        // check context from which we are running
        // from console context or not if from console then we should use getcwd() func
        // otherwise base_path() cause from different context ther is different paths for getcwd()
        $root_path = (strpos(php_sapi_name(), 'cli') !== false)
            ? getcwd()
            : base_path();

        $website = new Website;
        app(WebsiteRepository::class)->create($website);

        // associate the website with a hostname
        $hostname = new Hostname;
        // $baseUrl = env('APP_URL_BASE', 'localhost');
        // $hostname->fqdn = "{$name}.{$baseUrl}";
        $hostname->fqdn = $name;
        app(HostnameRepository::class)->attach($hostname, $website);

        // make hostname current
        app(Environment::class)->tenant($hostname->website);

        // We rename temporary tenant migrations to avoid creating system tenant tables in the tenant database
        $migrations = $root_path . '/database/migrations/';
        $files_to_preserve = glob($migrations . '*.php');

        foreach ($files_to_preserve as $file) {
            rename($file, $file . '.txt');
        }

        // In case we want to install voyager for newly created tenant we need to
        // switch database to tenant`s "tenant", cause voyager doesn`t know about it`s existance
        // and if we will not switch db manually than neccesary tables wouldn`t be created.
        DB::setDefaultConnection("tenant");

        // !!! IMPORTANT !!! We should fix the voyager issue
        // when doing Artisan::call "install --with-dummy" from browser context 
        //"what we are doing right now" voyager publishable migration resources for dummy 
        // will not be published. So we need to override "\TCG\Voyager\Providers\VoyagerDummyServiceProvider"
        // class method "register" to make $this->registerPublishableResources(); run for case when not running in 
        // console too. So it should be ...
        //
        /**
        * Register the application services.
        */
        public function register()
        {
            $this->app->register(WidgetServiceProvider::class);

            $this->registerConfigs();

            // our code down below
            if ($this->app->runningInConsole()) {
                $this->registerPublishableResources();
            } else {
                $this->registerPublishableResources();
            }
        }
        */

        // \Artisan::call('voyager:install');
        \Artisan::call('config:clear');
        \Artisan::call('voyager:install', ['--with-dummy' => true ]);
        //\Artisan::call('passport:install');
        
        foreach ($files_to_preserve as $file) {
            rename($file.'.txt', $file);
        }

        // Switch the database back to "system" 
        DB::setDefaultConnection("system");


        // Cleanup Voyager dummy migrations from system migration folder
        $voyager_migrations = $root_path . '/vendor/tcg/voyager/publishable/database/migrations/*.php';
        $files_to_kill = glob($voyager_migrations);
        $files_to_kill = array_map('basename', $files_to_kill);

        foreach ($files_to_kill as $file) {
            $path = $migrations. '/'. $file;
            unlink($path);
        }

        // Make the registered user the default Admin of the site.
        $admin = null;
        if ($email) {
            $admin = static::makeAdmin($name, $email, $password);
        }

        return new Tenant($website, $hostname, $admin);
    }

    private static function makeAdmin($name, $email, $password): User
    {
        $admin = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
        // $admin->guard_name = 'web';
        $admin->setRole('admin')->save();

        return $admin;
    }

    public static function tenantExists($name)
    {
        // $name = $name . '.' . env('APP_URL_BASE');
        return Hostname::where('fqdn', $name)->exists();
    }
}

EOF

# 04 Voyager installation

# Disable autodiscover for Voyager to load it only after your AppServiceProvider is loaded.
# This is needed, because you must be sure Voyager loads all it's staff after the
# DB connection is switched to tenant

# Alas composer CLI way to update composer.json fails here (is not able to write as waay)
# `composer config extra.laravel.dont-discover tcg/voyager`
# So we need to update composer.json on our own.

# Manual
# In composer.json add `tcg/voyager` to `dont-disover` array:
# "extra": {
#     "laravel": {
#         "dont-discover": [
#             "tcg/voyager"
#             "hyn/multi-tenant"
#         ]
#     }
# },

# Bash script
composer config extra.laravel.dont-discover null
sed -i "s/\"dont\-discover\"\: \"null\"/\"dont\-discover\"\: [\"tcg\/voyager\", \"hyn\/multi-tenant\"]/g" composer.json

# Install Voyager composer package
composer require tcg/voyager

# 05 Voyager setup

# Add `TCG\Voyager\VoyagerServiceProvider::class` to config/app.php providers array. Remember, we have disabled autodiscover.
sed -i "s/\(App\\\Providers\\\RouteServiceProvider::class,\)/\1\n        TCG\\\Voyager\\\VoyagerServiceProvider::class,/g" config/app.php


# Add
#```
#        App\Providers\CacheServiceProvider::class,
#        Hyn\Tenancy\Providers\TenancyProvider::class,
#        Hyn\Tenancy\Providers\WebserverProvider::class,
#```
# to config/app.php providers array. Remember, we have disabled autodiscover.
sed -i "s/\(App\\\Providers\\\AppServiceProvider::class,\)/App\\\Providers\\\CacheServiceProvider::class,\n        \1/g" config/app.php
sed -i "s/\(App\\\Providers\\\AppServiceProvider::class,\)/Hyn\\\Tenancy\\\Providers\\\TenancyProvider::class,\n        \1/g" config/app.php
sed -i "s/\(App\\\Providers\\\AppServiceProvider::class,\)/Hyn\\\Tenancy\\\Providers\\\WebserverProvider::class,\n        \1/g" config/app.php


# Register Voyager install command to app/Console/Kernel.php. It will be needed to create tenants via system Voyager.
sed -i "s/\(protected \$commands = \[\)/\1\n        \\\TCG\\\Voyager\\\Commands\\\InstallCommand::class,/g" app/Console/Kernel.php

# Update your AppServiceProvider.php to switch to tenant DB and filesystem when requesting a tenant URL
cat << 'EOF' > app/Providers/AppServiceProvider.php
<?php

namespace App\Providers;

use Hyn\Tenancy\Environment;
use TCG\Voyager\Facades\Voyager;
use App\Actions\TenantViewAction;
use App\Actions\TenantLoginAction;
use App\Actions\TenantDeleteAction;
use TCG\Voyager\Actions\ViewAction;
use TCG\Voyager\Actions\DeleteAction;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $env = app(Environment::class);

        $isSystem = true; 

        if ($fqdn = optional($env->hostname())->fqdn) {
            if (\App\Tenant::getRootFqdn() !== $fqdn ) {
                config(['database.default' => 'tenant']);
                config(['voyager.storage.disk' => 'tenant']);
                $isSystem = false; 
            }
        }

        if ($isSystem) {
            Voyager::addAction(TenantLoginAction::class);
            Voyager::replaceAction(ViewAction::class, TenantViewAction::class);
            Voyager::replaceAction(DeleteAction::class, TenantDeleteAction::class);
        }
        //
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}

EOF

# Create own cache provider
cat << 'EOF' > app/Providers/CacheServiceProvider.php
<?php

namespace App\Providers;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\ServiceProvider;


class CacheServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $namespace = function($app) {

            if (PHP_SAPI === 'cli') {
                return $app['config']['cache.default'];
            }

            $fqdn = request()->getHost();

            $uuid = \DB::table('hostnames')
                ->select('websites.uuid')
                ->join('websites', 'hostnames.website_id', '=', 'websites.id')
                ->where('fqdn', $fqdn)
                ->value('uuid');

            return $uuid;
        };

        $cacheDriver = config('cache.default');
        switch ($cacheDriver) {
            case 'file':
                \Cache::extend($cacheDriver, function ($app) use ($namespace){
                    $namespace = $namespace($app);

                    return \Cache::repository(new FileStore(
                        $app['files'],
                        $app['config']['cache.stores.file.path'].$namespace
                    ));
                });
                break;
            case 'database':
                \Cache::extend($cacheDriver, function ($app) use ($namespace){
                    $namespace = $namespace($app);

                    return \Cache::repository(new DatabaseStore(
                        $app['db.connection'],
                        'cache',
                        $namespace
                    ));
                });
                break;
            case 'redis':
                // But if not yet instantiated, then we are able to redifine namespace (prefix). Works for Redis only
                if (PHP_SAPI === 'cli') {
                    $namespace = str_slug(env('APP_NAME', 'laravel'), '_').'_cache';
                } else {
                    $fqdn = request()->getHost();
                    $namespace = \DB::table('hostnames')
                        ->select('websites.uuid')
                        ->join('websites', 'hostnames.website_id', '=', 'websites.id')
                        ->where('fqdn', $fqdn)
                        ->value('uuid');
                }
                \Cache::setPrefix($namespace);
                break;
            default:
        }
    }
}

EOF

# Override a buggy template
cat << 'EOF' > vendor/tcg/voyager/resources/views/bread/partials/actions.blade.php
@if($data)
    @php
        // need to recreate object because policy might depend on record data
        // ##mygruz20190924185517 { An override to make code work again!
         $class = is_object($action) ? get_class($action) : $action;
        // ##mygruz20190924185517 }
        $action = new $class($dataType, $data);
    @endphp
    @can ($action->getPolicy(), $data)
        <a href="{{ $action->getRoute($dataType->name) }}" title="{{ $action->getTitle() }}" {!! $action->convertAttributesToHtml() !!}>
            <i class="{{ $action->getIcon() }}"></i> <span class="hidden-xs hidden-sm">{{ $action->getTitle() }}</span>
        </a>
    @endcan
@elseif (method_exists($action, 'massAction'))
    <form method="post" action="{{ route('voyager.'.$dataType->slug.'.action') }}" style="display:inline">
        {{ csrf_field() }}
        <button type="submit" {!! $action->convertAttributesToHtml() !!}><i class="{{ $action->getIcon() }}"></i>  {{ $action->getTitle() }}</button>
        <input type="hidden" name="action" value="{{ get_class($action) }}">
        <input type="hidden" name="ids" value="" class="selected_ids">
    </form>
@endif

EOF


# Override Hyn Laravel tenanty Mediacontroller to make it work with Voyager.
# Hyn forces to use `media` folder to store files while Voyager reads root
# of the storage folder.
# So we create our own controller.
cat << 'EOF' > app/Http/Controllers/HynOverrideMediaController.php
<?php

namespace App\Http\Controllers;

use Hyn\Tenancy\Website\Directory;
use Illuminate\Support\Facades\Storage;

/**
 * Class MediaController
 *
 * @use Route::get('/storage/{path}', App\MediaController::class)
 *          ->where('path', '.+')
 *          ->name('tenant.media');
 */
class HynOverrideMediaController extends \Hyn\Tenancy\Controllers\MediaController
{
    /**
     * @var Directory
     */
    private $directory;

    public function __construct(Directory $directory)
    {
        $this->directory = $directory;
    }

    public function __invoke(string $path)
    {
        // $path = "media/$path";

        if ($this->directory->exists($path)) {
            return response($this->directory->get($path))
                ->header('Content-Type', Storage::disk('tenant')->mimeType($path));
        }

        return abort(404);
    }
}

EOF

# Set all paths requesting uploaded files to use just created controller.
cat << 'EOF' >> routes/web.php
Route::get('/storage/{path}', '\App\Http\Controllers\HynOverrideMediaController')
    ->where('path', '.+')
    ->name('tenant.media');
EOF

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

# Install system voyager with dummy data. We need dummy data to have some fallback data for tenants,
# if they use dummy as well.
php artisan voyager:install --with-dummy

# Create a controller for the system Voyager to manage tenants
cat << 'EOF' > app/Http/Controllers/VoyagerTenantsController.php
<?php

namespace App\Http\Controllers;

use App\Tenant;
use Hyn\Tenancy\Environment;
use Illuminate\Http\Request;
use Hyn\Tenancy\Models\Hostname;
use TCG\Voyager\Facades\Voyager;
use Illuminate\Support\Facades\DB;
use TCG\Voyager\Events\BreadDataAdded;
use TCG\Voyager\Events\BreadDataDeleted;


class VoyagerTenantsController extends \TCG\Voyager\Http\Controllers\VoyagerBaseController
{
    /**
     * Check if current request is an add of a tenant
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    private function isTenantOperation(Request $request) {
        $slug = $this->getSlug($request);

        $env = app(Environment::class);
        $fqdn = optional($env->hostname())->fqdn;

        if (\App\Tenant::getRootFqdn() !== $fqdn || 'hostnames' !== $slug) {
            return false;
        }

        return true;
    }

    /**
     * POST BRE(A)D - Store data.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        if (!$this->isTenantOperation($request)) {
            return parent::store($request);
        }

        $fqdn = $request->get('fqdn');
        $request->offsetSet('fqdn', $fqdn);


        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        // Check permission
        $this->authorize('add', app($dataType->model_name));

        // Validate fields with ajax
        $val = $this->validateBread($request->all(), $dataType->addRows);

        if ($val->fails()) {
            return response()->json(['errors' => $val->messages()]);
        }

        if (!$request->has('_validate')) {

            $tenant = Tenant::registerTenant($fqdn);

            $data = Hostname::where('fqdn', $fqdn)->firstOrFail(); 

            // This line is stored just in case from the parent class method. Would try to save to tenant `hostnames`. 
            // So it's of no use. Leave here as an example and just in case.
            // $data = $this->insertUpdateData($request, $slug, $dataType->addRows, new $dataType->model_name());

            // !!! IMPORTANT 
            // If you add additional fields to system `hostnames` table
            // (it's assumed you have created and executed corresponding migrations, updated `hostnames` Voyager bread) 
            // and want to save the additional fields, just uncomment the line below.
            // $data = $this->insertUpdateData($request, $slug, $dataType->editRows, $data);

            event(new BreadDataAdded($dataType, $data));

            if ($request->ajax()) {
                return response()->json(['success' => true, 'data' => $data]);
            }

            return redirect()
                ->route("voyager.{$dataType->slug}.index")
                ->with([
                        'message'    => __('voyager::generic.successfully_added_new')." {$dataType->display_name_singular}",
                        'alert-type' => 'success',
                    ]);
        }
    }

    //***************************************
    //                _____
    //               |  __ \
    //               | |  | |
    //               | |  | |
    //               | |__| |
    //               |_____/
    //
    //         Delete an item BREA(D)
    //
    //****************************************

    public function destroy(Request $request, $id)
    {
        if (!$this->isTenantOperation($request)) {
            return parent::destroy($request);
        }

        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        $fqdn = Hostname::where('id', $id)->firstOrFail(['fqdn'])->fqdn; 
        $systemSite = \App\Tenant::getRootFqdn();

        if ( $systemSite === $fqdn ) {
            return redirect()
                ->route("voyager.{$dataType->slug}.index")
                ->with([
                        'message'    => __('voyager::generic.system.site.cannot.be.deleted'),
                        'alert-type' => 'error',
                    ]);
        }

        // Check permission
        $this->authorize('delete', app($dataType->model_name));

        // Init array of IDs
        $ids = [];
        if (empty($id)) {
            // Bulk delete, get IDs from POST
            $ids = explode(',', $request->ids);
        } else {
            // Single item delete, get ID from URL
            $ids[] = $id;
        }

        $res = false;
        foreach ($ids as $id) {
            $data = call_user_func([$dataType->model_name, 'findOrFail'], $id, $columns = array('fqdn') );
            $this->cleanup($dataType, $data);
            $res = Tenant::deleteById($id);
        }

        $displayName = count($ids) > 1 ? $dataType->display_name_plural : $dataType->display_name_singular;

        // TODO ##mygruz20190213014253 
        // If deleting several domains, we can get partial successfull result. We must properly handle the situations.
        // Currently if we have at least one (or last) success, we return a success message.
        $data = $res
            ? [
                'message'    => __('voyager::generic.successfully_deleted')." {$displayName}",
                'alert-type' => 'success',
            ]
            : [
                'message'    => __('voyager::generic.error_deleting')." {$displayName}",
                'alert-type' => 'error',
            ];

        if ($res) {
            event(new BreadDataDeleted($dataType, $data));
        }

        return redirect()->route("voyager.{$dataType->slug}.index")->with($data);
    }

    // POST BR(E)AD
    public function update(Request $request, $id)
    {
        if (!$this->isTenantOperation($request)) {
            return parent::update($request, $id);
        }
        

        $systemSiteId = Hostname::where('website_id', null)->first()->id;
        $systemSite = \App\Tenant::getRootFqdn();

        if ( $systemSiteId === intval($id) ) {

            parent::update($request, $id);

            return redirect()->to('//' . $request->fqdn  . '/admin/');
        } else {
            return parent::update($request, $id);
        }
    }


}

EOF

# Create Bread for hostnames in system Voyager
composer require --dev gruz/voyager-bread-generator

cat << 'EOF' > database/seeders/HostnamesBreadSeeder.php
<?php

namespace Database\Seeders;

use stdClass;
use Illuminate\Database\Seeder;
use VoyagerBread\Traits\BreadSeeder;

class HostnamesBreadSeeder extends Seeder
{
    use BreadSeeder;

    public function bread()
    {
        return [
            // usually the name of the table
            'name'                  => 'hostnames',
            'slug'                  => 'hostnames',
            'display_name_singular' => 'Hostname',
            'display_name_plural'   => 'Hostnames',
            'icon'                  => 'voyager-ship',
            'model_name'            => 'App\Models\Hostname',
            'controller'            => '\App\Http\Controllers\VoyagerTenantsController',
            'generate_permissions'  => 1,
            'description'           => '',
            'details'               => null
        ];
    }

    public function inputFields()
    {
        return [
            'id' => [
                'type'         => 'number',
                'display_name' => 'ID',
                'required'     => 1,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'details'      => new stdClass,
                'order'        => 1,
            ],
            'website_id' => [
                'type'         => 'text',
                'display_name' => 'Website Id',
                'required'     => 1,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'details'      => new stdClass,
                'order'        => 2,
            ],
            'fqdn' => [
                'type'         => 'text',
                'display_name' => 'Domain name',
                'required'     => 1,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 1,
                'add'          => 1,
                'delete'       => 1,
                'details'      => [
                    'description' => 'A Fully-qualified domain name. No protocol. Only domain name itself.',
                    'validation' => [
                      'rule' => 'unique:hostnames,fqdn',
                    ],
                ],
                'order'        => 3,
            ],
            'redirect_to' => [
                'type'         => 'text',
                'display_name' => 'Redirect To',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'details'      => new stdClass,
                'order'        => 4,
            ],
            'force_https' => [
                'type'         => 'text',
                'display_name' => 'Force Https',
                'required'     => 1,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'details'      => [  
                    'default' => '0',
                    'options' => [
                            0 => 'No',
                            1 => 'Yes',
                        ],
                ],
                'order'        => 5,
            ],
            'under_maintenance_since' => [
                'type'         => 'timestamp',
                'display_name' => 'Under Maintenance Since',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'details'      => new stdClass,
                'order'        => 6,
            ],
            'created_at' => [
                'type'         => 'timestamp',
                'display_name' => 'created_at',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'details'      => new stdClass,
                'order'        => 7,
            ],
            'updated_at' => [
                'type'         => 'timestamp',
                'display_name' => 'updated_at',
                'required'     => 0,
                'browse'       => 1,
                'read'         => 1,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'details'      => new stdClass,
                'order'        => 8,
            ],
            'deleted_at' => [
                'type'         => 'timestamp',
                'display_name' => 'Deleted At',
                'required'     => 0,
                'browse'       => 0,
                'read'         => 0,
                'edit'         => 0,
                'add'          => 0,
                'delete'       => 0,
                'details'      => new stdClass,
                'order'        => 9,
            ],
        ];
    }

    public function menuEntry()
    {
        return [
            'role'        => 'admin',
            'title'       => 'Hostnames',
            'url'         => '',
            'route'       => 'voyager.hostnames.index',
            'target'      => '_self',
            'icon_class'  => 'voyager-ship',
            'color'       => null,
            'parent_id'   => null,
            'parameters' => null,
            'order'       => 1,

        ];
    }
}

EOF

composer dump-autoload
php artisan db:seed --class=HostnamesBreadSeeder


cat << 'EOF' > database/seeders/PermissionRoleTableSeeder.php
<?php

namespace Database\Seeders;

use App\Models\Hostname;
require './vendor/tcg/voyager/publishable/database/seeds/PermissionRoleTableSeeder.php';

class PermissionRoleTableSeeder extends \PermissionRoleTableSeeder
{

}

EOF

php artisan db:seed --class=PermissionRoleTableSeeder


# Alter action buttons at system hostnames Voyager view to have login button, alter view button and block system domain deletion
mkdir app/Actions/
cat << 'EOF' > app/Actions/TenantDeleteAction.php
<?php

namespace App\Actions;

use TCG\Voyager\Actions\DeleteAction;

class TenantDeleteAction extends DeleteAction
{
    public function getAttributes()
    {
        $fqdn = $this->data->fqdn; 
        $systemSite = \App\Tenant::getRootFqdn();

        if ( $systemSite === $fqdn ) {
            return [
                'class' => 'hide',
            ];
        }
        else {
            return parent::getAttributes();
        }
    }
}

EOF

cat << 'EOF' > app/Actions/TenantLoginAction.php
<?php

namespace App\Actions;

use TCG\Voyager\Actions\AbstractAction;

class TenantLoginAction extends AbstractAction
{
    public function getTitle()
    {
        return __('voyager::generic.login');
    }

    public function getIcon()
    {
        return 'voyager-ship';
    }

    public function getPolicy()
    {
        return 'read';
    }

    public function getDataType()
    {
        return 'hostnames';
    }

    public function getAttributes()
    {
        $fqdn = $this->data->fqdn; 
        $systemSite = \App\Tenant::getRootFqdn();

        if ( $systemSite === $fqdn ) {
            return [
                'class' => 'hide',
            ];
        }
        else {

            return [
                'class' => 'btn btn-sm btn-warning pull-left login',
                'target' => '_blank'
            ];
        }
    }

    public function getDefaultRoute()
    {
        $route = '//'. $this->data->fqdn . '/admin';

        return $route;
    }
}

EOF

cat << 'EOF' > app/Actions/TenantViewAction.php
<?php

namespace App\Actions;

use TCG\Voyager\Actions\ViewAction;

class TenantViewAction extends ViewAction
{
    public function getAttributes()
    {
        $fqdn = $this->data->fqdn; 
        $systemSite = \App\Tenant::getRootFqdn();

        if ( $systemSite === $fqdn ) {
            return [
                'class' => 'hide',
            ];
        }
        else {
            return array_merge( parent::getAttributes(), [ 'target' => '_blank'] );
        }


    }

    public function getDefaultRoute()
    {
        $route = '//'. $this->data->fqdn;

        return $route;
    }
}

EOF

# Override a Voyager template to show 'System domain' text for a system domain in system Voyager

mkdir -p resources/views/vendor/voyager/hostnames

cat << 'EOF' > resources/views/vendor/voyager/hostnames/browse.blade.php
@extends('voyager::master')

@section('page_title', __('voyager::generic.viewing').' '.$dataType->display_name_plural)

@section('page_header')
    <div class="container-fluid">
        <h1 class="page-title">
            <i class="{{ $dataType->icon }}"></i> {{ $dataType->display_name_plural }}
        </h1>
        @can('add', app($dataType->model_name))
            <a href="{{ route('voyager.'.$dataType->slug.'.create') }}" class="btn btn-success btn-add-new">
                <i class="voyager-plus"></i> <span>{{ __('voyager::generic.add_new') }}</span>
            </a>
        @endcan
        @can('delete', app($dataType->model_name))
            @include('voyager::partials.bulk-delete')
        @endcan
        @can('edit', app($dataType->model_name))
            @if(isset($dataType->order_column) && isset($dataType->order_display_column))
                <a href="{{ route('voyager.'.$dataType->slug.'.order') }}" class="btn btn-primary">
                    <i class="voyager-list"></i> <span>{{ __('voyager::bread.order') }}</span>
                </a>
            @endif
        @endcan
        @include('voyager::multilingual.language-selector')
    </div>
@stop

@section('content')
    <div class="page-content browse container-fluid">
        @include('voyager::alerts')
        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-bordered">
                    <div class="panel-body">
                        @if ($isServerSide)
                            <form method="get" class="form-search">
                                <div id="search-input">
                                    <select id="search_key" name="key">
                                        @foreach($searchable as $key)
                                            <option value="{{ $key }}" @if($search->key == $key || $key == $defaultSearchKey){{ 'selected' }}@endif>{{ ucwords(str_replace('_', ' ', $key)) }}</option>
                                        @endforeach
                                    </select>
                                    <select id="filter" name="filter">
                                        <option value="contains" @if($search->filter == "contains"){{ 'selected' }}@endif>contains</option>
                                        <option value="equals" @if($search->filter == "equals"){{ 'selected' }}@endif>=</option>
                                    </select>
                                    <div class="input-group col-md-12">
                                        <input type="text" class="form-control" placeholder="{{ __('voyager::generic.search') }}" name="s" value="{{ $search->value }}">
                                        <span class="input-group-btn">
                                            <button class="btn btn-info btn-lg" type="submit">
                                                <i class="voyager-search"></i>
                                            </button>
                                        </span>
                                    </div>
                                </div>
                            </form>
                        @endif
                        <div class="table-responsive">
                            <table id="dataTable" class="table table-hover">
                                <thead>
                                    <tr>
                                        @can('delete',app($dataType->model_name))
                                            <th>
                                                <input type="checkbox" class="select_all">
                                            </th>
                                        @endcan
                                        @foreach($dataType->browseRows as $row)
                                        <th>
                                            @if ($isServerSide)
                                                <a href="{{ $row->sortByUrl($orderBy, $sortOrder) }}">
                                            @endif
                                            {{ $row->display_name }}
                                            @if ($isServerSide)
                                                @if ($row->isCurrentSortField($orderBy))
                                                    @if ($sortOrder == 'asc')
                                                        <i class="voyager-angle-up pull-right"></i>
                                                    @else
                                                        <i class="voyager-angle-down pull-right"></i>
                                                    @endif
                                                @endif
                                                </a>
                                            @endif
                                        </th>
                                        @endforeach
                                        <th class="actions text-right">{{ __('voyager::generic.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($dataTypeContent as $data)
                                    <tr>
                                        @can('delete',app($dataType->model_name))
                                            <td>
                                                <input type="checkbox" name="row_id" id="checkbox_{{ $data->getKey() }}" value="{{ $data->getKey() }}">
                                            </td>
                                        @endcan
                                        @foreach($dataType->browseRows as $row)

                                            @if($row->field == 'website_id' && empty($data->website_id))
                                                <?php
                                                $data->website_id = 'System domain';
                                                ?>
                                            @endif
                                            <td>
                                                @if($row->type == 'image')
                                                    <img src="@if( !filter_var($data->{$row->field}, FILTER_VALIDATE_URL)){{ Voyager::image( $data->{$row->field} ) }}@else{{ $data->{$row->field} }}@endif" style="width:100px">
                                                @elseif($row->type == 'relationship')
                                                    @include('voyager::formfields.relationship', ['view' => 'browse','options' => $row->details])
                                                @elseif($row->type == 'select_multiple')
                                                    @if(property_exists($row->details, 'relationship'))

                                                        @foreach($data->{$row->field} as $item)
                                                            {{ $item->{$row->field} }}
                                                        @endforeach

                                                    @elseif(property_exists($row->details, 'options'))
                                                        @if (count(json_decode($data->{$row->field})) > 0)
                                                            @foreach(json_decode($data->{$row->field}) as $item)
                                                                @if (@$row->details->options->{$item})
                                                                    {{ $row->details->options->{$item} . (!$loop->last ? ', ' : '') }}
                                                                @endif
                                                            @endforeach
                                                        @else
                                                            {{ __('voyager::generic.none') }}
                                                        @endif
                                                    @endif

                                                @elseif($row->type == 'select_dropdown' && property_exists($row->details, 'options'))

                                                    {!! isset($row->details->options->{$data->{$row->field}}) ?  $row->details->options->{$data->{$row->field}} : '' !!}

                                                @elseif($row->type == 'date' || $row->type == 'timestamp')
                                                    {{ property_exists($row->details, 'format') ? \Carbon\Carbon::parse($data->{$row->field})->formatLocalized($row->details->format) : $data->{$row->field} }}
                                                @elseif($row->type == 'checkbox')
                                                    @if(property_exists($row->details, 'on') && property_exists($row->details, 'off'))
                                                        @if($data->{$row->field})
                                                            <span class="label label-info">{{ $row->details->on }}</span>
                                                        @else
                                                            <span class="label label-primary">{{ $row->details->off }}</span>
                                                        @endif
                                                    @else
                                                    {{ $data->{$row->field} }}
                                                    @endif
                                                @elseif($row->type == 'color')
                                                    <span class="badge badge-lg" style="background-color: {{ $data->{$row->field} }}">{{ $data->{$row->field} }}</span>
                                                @elseif($row->type == 'text')
                                                    @include('voyager::multilingual.input-hidden-bread-browse')
                                                    <div class="readmore">{{ mb_strlen( $data->{$row->field} ) > 200 ? mb_substr($data->{$row->field}, 0, 200) . ' ...' : $data->{$row->field} }}</div>
                                                @elseif($row->type == 'text_area')
                                                    @include('voyager::multilingual.input-hidden-bread-browse')
                                                    <div class="readmore">{{ mb_strlen( $data->{$row->field} ) > 200 ? mb_substr($data->{$row->field}, 0, 200) . ' ...' : $data->{$row->field} }}</div>
                                                @elseif($row->type == 'file' && !empty($data->{$row->field}) )
                                                    @include('voyager::multilingual.input-hidden-bread-browse')
                                                    @if(json_decode($data->{$row->field}))
                                                        @foreach(json_decode($data->{$row->field}) as $file)
                                                            <a href="{{ Storage::disk(config('voyager.storage.disk'))->url($file->download_link) ?: '' }}" target="_blank">
                                                                {{ $file->original_name ?: '' }}
                                                            </a>
                                                            <br/>
                                                        @endforeach
                                                    @else
                                                        <a href="{{ Storage::disk(config('voyager.storage.disk'))->url($data->{$row->field}) }}" target="_blank">
                                                            Download
                                                        </a>
                                                    @endif
                                                @elseif($row->type == 'rich_text_box')
                                                    @include('voyager::multilingual.input-hidden-bread-browse')
                                                    <div class="readmore">{{ mb_strlen( strip_tags($data->{$row->field}, '<b><i><u>') ) > 200 ? mb_substr(strip_tags($data->{$row->field}, '<b><i><u>'), 0, 200) . ' ...' : strip_tags($data->{$row->field}, '<b><i><u>') }}</div>
                                                @elseif($row->type == 'coordinates')
                                                    @include('voyager::partials.coordinates-static-image')
                                                @elseif($row->type == 'multiple_images')
                                                    @php $images = json_decode($data->{$row->field}); @endphp
                                                    @if($images)
                                                        @php $images = array_slice($images, 0, 3); @endphp
                                                        @foreach($images as $image)
                                                            <img src="@if( !filter_var($image, FILTER_VALIDATE_URL)){{ Voyager::image( $image ) }}@else{{ $image }}@endif" style="width:50px">
                                                        @endforeach
                                                    @endif
                                                @else
                                                    @include('voyager::multilingual.input-hidden-bread-browse')
                                                    <span>{{ $data->{$row->field} }}</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="no-sort no-click" id="bread-actions">
                                            @foreach(Voyager::actions() as $action)
                                                @include('voyager::bread.partials.actions', ['action' => $action])
                                            @endforeach
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($isServerSide)
                            <div class="pull-left">
                                <div role="status" class="show-res" aria-live="polite">{{ trans_choice(
                                    'voyager::generic.showing_entries', $dataTypeContent->total(), [
                                        'from' => $dataTypeContent->firstItem(),
                                        'to' => $dataTypeContent->lastItem(),
                                        'all' => $dataTypeContent->total()
                                    ]) }}</div>
                            </div>
                            <div class="pull-right">
                                {{ $dataTypeContent->appends([
                                    's' => $search->value,
                                    'filter' => $search->filter,
                                    'key' => $search->key,
                                    'order_by' => $orderBy,
                                    'sort_order' => $sortOrder
                                ])->links() }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Single delete modal --}}
    <div class="modal modal-danger fade" tabindex="-1" id="delete_modal" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('voyager::generic.close') }}"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title"><i class="voyager-trash"></i> {{ __('voyager::generic.delete_question') }} {{ strtolower($dataType->display_name_singular) }}?</h4>
                </div>
                <div class="modal-footer">
                    <form action="#" id="delete_form" method="POST">
                        {{ method_field('DELETE') }}
                        {{ csrf_field() }}
                        <input type="submit" class="btn btn-danger pull-right delete-confirm" value="{{ __('voyager::generic.delete_confirm') }}">
                    </form>
                    <button type="button" class="btn btn-default pull-right" data-dismiss="modal">{{ __('voyager::generic.cancel') }}</button>
                </div>
            </div><!-- /.modal-content -->
        </div><!-- /.modal-dialog -->
    </div><!-- /.modal -->
@stop

@section('css')
@if(!$dataType->server_side && config('dashboard.data_tables.responsive'))
    <link rel="stylesheet" href="{{ voyager_asset('lib/css/responsive.dataTables.min.css') }}">
@endif
@stop

@section('javascript')
    <!-- DataTables -->
    @if(!$dataType->server_side && config('dashboard.data_tables.responsive'))
        <script src="{{ voyager_asset('lib/js/dataTables.responsive.min.js') }}"></script>
    @endif
    <script>
        $(document).ready(function () {
            @if (!$dataType->server_side)
                var table = $('#dataTable').DataTable({!! json_encode(
                    array_merge([
                        "order" => $orderColumn,
                        "language" => __('voyager::datatable'),
                        "columnDefs" => [['targets' => -1, 'searchable' =>  false, 'orderable' => false]],
                    ],
                    config('voyager.dashboard.data_tables', []))
                , true) !!});
            @else
                $('#search-input select').select2({
                    minimumResultsForSearch: Infinity
                });
            @endif

            @if ($isModelTranslatable)
                $('.side-body').multilingual();
                //Reinitialise the multilingual features when they change tab
                $('#dataTable').on('draw.dt', function(){
                    $('.side-body').data('multilingual').init();
                })
            @endif
            $('.select_all').on('click', function(e) {
                $('input[name="row_id"]').prop('checked', $(this).prop('checked'));
            });
        });


        var deleteFormAction;
        $('td').on('click', '.delete', function (e) {
            $('#delete_form')[0].action = '{{ route('voyager.'.$dataType->slug.'.destroy', ['id' => '__id']) }}'.replace('__id', $(this).data('id'));
            $('#delete_modal').modal('show');
        });
    </script>
@stop

EOF

php artisan config:clear

```

## Installation from the repository

### Docker

```bash
git clone git@github.com:gruz/multi-tenancy-voyager-tries.git multi-tenancy-voyager;
cd multi-tenancy-voyager;
git submodule update --init --recursive;
cd laradock;
cp env-example .env

# Enable PHP exif used by Voyager Media manager
sed -i "s/PHP_FPM_INSTALL_EXIF=false/PHP_FPM_INSTALL_EXIF=true/g" .env

# Run docker containers and login into the workspace container
    # > Building docker containers can take significant time for the first run.
    # > We run adminer container to have a database management UI tool.
        # Available under localhost:8080
        # System: PostgreSQL
        # Server: postgres
        # Username: default
        # Password: secret
docker-compose up -d postgres nginx adminer
docker-compose exec --user=laradock workspace bash

```

### Dockerless

```bash
git clone git@github.com:gruz/multi-tenancy-voyager-tries.git
```

It's assumed, that you setup your HTTP server to open project `public` folder for your domain. So when you try to visit your web-site, the server tries to open the `public` folder.

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

Open [http://voyager.test/admin](http://voyager.test/admin) and login with `admin@admin.com`/`password`

Go to `Hostnames` sidebar menu and create a tenant like `dnipro.voyager.test` or `kyiv.voyager.test`.
Remember editing `hosts` file at the tutorial begining.

Open newly created [http://dnipro.voyager.test/admin](http://dnipro.voyager.test/admin)
in your browser and login using credentials `admin@admin.com`/`password`.

Try editing data and uploading files to different tenants to be sure the data is different per tenant.

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
docker-compose up -d postgres nginx
```

If you need, you can also run `adminer`

```bash
docker-compose up -d adminer
```
