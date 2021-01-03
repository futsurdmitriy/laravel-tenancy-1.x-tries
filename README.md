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
### Skip this, it's for future, it is here to not lose time that was spent on searching this info :(
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

## Migration Command

In migration of tenants might happen some issues. E.g.

Problem

I've set up the lifecycle-hooks Database and Migration. When creating a new tenant all my migrations for tenant databases are run. No matter what I try (artisan migrate, artisan migrate --tenant, ...), I can't get new migrations to be applied on existing tenant databases.

Solution

Migrations for tenant databases are triggered by lifecycle events. So, all you have to do is to fire an Updated event for the tenant you want to migrate. Yes, this does mean you need to fire one event for each of your tenants if you want to migrate all at once.

You can do this via artisan tinker, create a custom artisan command (e.g. migrate:tenants, inside your own tenant managing commands, ...), etc.

You can create the command class via artisan command:

```bash
php artisan make:command MigrateTenants
```
Make the class look like:

```php
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

```

You must have Customer model to make command work. This command will trigger update event for all the tenants (customers) you have and trigger the migration of existinf tenants.

You can use it by 
```bash
php artisan migrate:tenants
```

## Customer Controller

For basic CRUD operation we will implement CustomerController. We have already created model and controller via command

```bash
php artisan make:model Customer -mc
```

So just changed it to look like this:

```php
namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Symfony\Component\Console\Input\Input;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function index()
    {
//        dd(DB::connection()->getConfig());

        // get all the customers
        $customers = Customer::all();

        // load the view and pass the customers
        return View::make('customers.index')
            ->with('customers', $customers);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function create()
    {
        // load the create form (app/views/customers/create.blade.php)
        return View::make('customers.create');

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        // validate
        // read more on validation at http://laravel.com/docs/validation
        $rules = array(
            'fqdn'       => 'required',
        );
        $validator = Validator::make($request->all(), $rules);

        // process the login
        if ($validator->fails()) {
            return Redirect::to('customers/create')
                ->withErrors($validator)
                ->withInput($request->except('password'));
        } else {
            // store
            $customer = new Customer;
            $customer->fqdn = $request->get('fqdn');
            $customer->save();

            // redirect
            $request->session()->flash('message', 'Successfully created customer!');
            return Redirect::to('customers');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Contracts\View\View
     */
    public function show($id)
    {
        // get the customer
        $customer = Customer::find($id);

        // show the view and pass the customer to it
        return View::make('customers.show')
            ->with('customer', $customer);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($id)
    {
        $customer = Customer::find($id);
        if ($customer) {
            $customer->delete();
            return redirect()->route('customers.index')
                ->with('success', 'Customer deleted successfully');
        }
        return redirect()->back();
    }
}
```

## VerifyCSRFToken exclude

To avoid annoying message page expired (419 error), we will tel the laravel not to verify CSRF token for customer routes.
IT IS NOT RECOMMENDED, just in our demostration case we disabled verification. To do this change the class \App\Http\Middleware\VerifyCsrfToken to:

```php
namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        'customers/*',
    ];
}
```

## Hostnames Handler

Our tenant should resolve it's hostnames (Our business logic requries it). For now it's not work. But recommended way to enable hostnames is next:

Create listener e.g. \App\Listeners\TenantFQDNHandler

```php
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
```

And create listener e.g. \App\Listeners\ConfigureHostnameHandlers

```php
namespace App\Listeners;

use \Tenancy\Hooks\Hostname\Hooks\HostnamesHook;

class ConfigureHostnameHandlers
{
    public function handle(HostnamesHook $event)
    {
        $event->registerHandler(new TenantFQDNHandler());
    }
}
```

Model Customers must also implement interface \Tenancy\Hooks\Hostname\Contracts\HasHostnames 

Check official tenancy/tenancy docs (https://tenancy.dev/docs/tenancy/1.x/hooks-hostname) for more info.

## EventServiceProvider activation

To make every listener listen to event we also must enable discover events in shouldDiscoverEvents method of \App\Providers\EventServiceProvider class.

```php
namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return true;
    }
}

``` 

## Advice for database troubles with native password and authentication methods + creation of user and previledges

If you face with this kind of problems we recommend to read next articles:

*  https://tenancy.dev/docs/tenancy/1.x/database-mysql
*  https://stackoverflow.com/questions/52364415/php-with-mysql-8-0-error-the-server-requested-authentication-method-unknown-to
*  https://www.mysqltutorial.org/mysql-show-users/
*  https://dev.mysql.com/doc/refman/8.0/en/show-grants.html
*  https://stackoverflow.com/questions/59838692/mysql-root-password-is-set-but-getting-access-denied-for-user-rootlocalhost
*  https://stackoverflow.com/questions/50994393/laravel-php-artisan-migrate
*  https://github.com/laravel/framework/issues/23961
*  https://dev.mysql.com/doc/refman/5.7/en/drop-user.html
*  https://www.tutorialspoint.com/fix-error-1064-42000-while-creating-a-database-in-mysql#:~:text=The%20ERROR%201064%20(42000)%20mainly,in%20ERROR%201064%20(42000).&text=Now%20database%20is%20created%20successfully.


## app.php

If you installed the tenancy/tenancy package - it's universal way and many packages will be installed as dependencies you just need to include necessary package services in /config/app.php file.

/config/app.php
```php
return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    'asset_url' => env('ASSET_URL', null),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by the translation service provider. You are free to set this value
    | to any of the locales which will be supported by the application.
    |
    */

    'locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Application Fallback Locale
    |--------------------------------------------------------------------------
    |
    | The fallback locale determines the locale to use when the current one
    | is not available. You may change the value to correspond to any of
    | the language folders that are provided through your application.
    |
    */

    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Faker Locale
    |--------------------------------------------------------------------------
    |
    | This locale will be used by the Faker PHP library when generating fake
    | data for your database seeds. For example, this will be used to get
    | localized telephone numbers, street address information and more.
    |
    */

    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the Illuminate encrypter service and should be set
    | to a random, 32 character string, otherwise these encrypted strings
    | will not be safe. Please do this before deploying an application!
    |
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [

        /*
         * Laravel Framework Service Providers...
         */
        Illuminate\Auth\AuthServiceProvider::class,
        Illuminate\Broadcasting\BroadcastServiceProvider::class,
        Illuminate\Bus\BusServiceProvider::class,
        Illuminate\Cache\CacheServiceProvider::class,
        Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
        Illuminate\Cookie\CookieServiceProvider::class,
        Illuminate\Database\DatabaseServiceProvider::class,
        Illuminate\Encryption\EncryptionServiceProvider::class,
        Illuminate\Filesystem\FilesystemServiceProvider::class,
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        Illuminate\Hashing\HashServiceProvider::class,
        Illuminate\Mail\MailServiceProvider::class,
        Illuminate\Notifications\NotificationServiceProvider::class,
        Illuminate\Pagination\PaginationServiceProvider::class,
        Illuminate\Pipeline\PipelineServiceProvider::class,
        Illuminate\Queue\QueueServiceProvider::class,
        Illuminate\Redis\RedisServiceProvider::class,
        Illuminate\Auth\Passwords\PasswordResetServiceProvider::class,
        Illuminate\Session\SessionServiceProvider::class,
        Illuminate\Translation\TranslationServiceProvider::class,
        Illuminate\Validation\ValidationServiceProvider::class,
        Illuminate\View\ViewServiceProvider::class,
        Collective\Html\HtmlServiceProvider::class,
        /*
         * Package Service Providers...
         */
        Barryvdh\Debugbar\ServiceProvider::class,
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
//        Tenancy\Database\Drivers\Sqlite\Provider::class,
        Tenancy\Identification\Drivers\Environment\Providers\IdentificationProvider::class,


        /*
         * Application Service Providers...
         */
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        // App\Providers\BroadcastServiceProvider::class,
        App\Providers\EventServiceProvider::class,
        App\Providers\RouteServiceProvider::class,


    ],

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    |
    | This array of class aliases will be registered when this application
    | is started. However, feel free to register as many as you wish as
    | the aliases are "lazy" loaded so they don't hinder performance.
    |
    */

    'aliases' => [

        'App' => Illuminate\Support\Facades\App::class,
        'Arr' => Illuminate\Support\Arr::class,
        'Artisan' => Illuminate\Support\Facades\Artisan::class,
        'Auth' => Illuminate\Support\Facades\Auth::class,
        'Blade' => Illuminate\Support\Facades\Blade::class,
        'Broadcast' => Illuminate\Support\Facades\Broadcast::class,
        'Bus' => Illuminate\Support\Facades\Bus::class,
        'Cache' => Illuminate\Support\Facades\Cache::class,
        'Config' => Illuminate\Support\Facades\Config::class,
        'Cookie' => Illuminate\Support\Facades\Cookie::class,
        'Crypt' => Illuminate\Support\Facades\Crypt::class,
        'DB' => Illuminate\Support\Facades\DB::class,
        'Eloquent' => Illuminate\Database\Eloquent\Model::class,
        'Event' => Illuminate\Support\Facades\Event::class,
        'File' => Illuminate\Support\Facades\File::class,
        'Gate' => Illuminate\Support\Facades\Gate::class,
        'Hash' => Illuminate\Support\Facades\Hash::class,
        'Http' => Illuminate\Support\Facades\Http::class,
        'Lang' => Illuminate\Support\Facades\Lang::class,
        'Log' => Illuminate\Support\Facades\Log::class,
        'Mail' => Illuminate\Support\Facades\Mail::class,
        'Notification' => Illuminate\Support\Facades\Notification::class,
        'Password' => Illuminate\Support\Facades\Password::class,
        'Queue' => Illuminate\Support\Facades\Queue::class,
        'Redirect' => Illuminate\Support\Facades\Redirect::class,
        // 'Redis' => Illuminate\Support\Facades\Redis::class,
        'Request' => Illuminate\Support\Facades\Request::class,
        'Response' => Illuminate\Support\Facades\Response::class,
        'Route' => Illuminate\Support\Facades\Route::class,
        'Schema' => Illuminate\Support\Facades\Schema::class,
        'Session' => Illuminate\Support\Facades\Session::class,
        'Storage' => Illuminate\Support\Facades\Storage::class,
        'Str' => Illuminate\Support\Str::class,
        'URL' => Illuminate\Support\Facades\URL::class,
        'Validator' => Illuminate\Support\Facades\Validator::class,
        'View' => Illuminate\Support\Facades\View::class,
        'Form' => Collective\Html\FormFacade::class,
        'HTML' => Collective\Html\HtmlFacade::class,
        'Debugbar' => Barryvdh\Debugbar\Facade::class,

    ],

];
```

In aliases array 'View','Form', 'HTML' classes necessary for render form fields.

## custom.php for uuid length

The main idea to create this file is to hold necessary variables that will be used in different environment conditions.
One of the reasons is to hold variable 'limit_uuid_length_32' that should have value - true to set the length of uuid filed for our tenant. Mysql can't use default uuid because it's too long for some mysql default attributes (username, db name and others.) When we create a tenant, for tenant in encapsulated way will be created db name and username which will be taken from uuid field.
For now exists dummy implementtion to get  varible from custom.php. In Customer model exists static method - 'boot'. I this method  varible from custom.php is checked, but really default value 'true' is taken. It should be changed in next versions.

Customer model code

```php

//          OTHER CODE

class Customer extends Model implements Tenant, IdentifiesByHttp, HasHostnames
{

//          OTHER CODE

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
``` 

/config/custom.php file
```php

    return [
        'limit_uuid_length_32' => env('LIMIT_UUID_LENGTH_32', true),
    ];

```

## database.php for mysql modes + laradock mysql config for user native password creation 

If you use mysql as database and facing with native password and authentication methods problems try to change native password setting in mysql by adding some configs to database.php file.

/config/database.php
```php
/*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */
'connections' => [

        // OTHER CONNECTIONS

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'modes' => [
                'ONLY_FULL_GROUP_BY',
                'STRICT_TRANS_TABLES',
                'NO_ZERO_IN_DATE',
                'NO_ZERO_DATE',
                'ERROR_FOR_DIVISION_BY_ZERO',
                'NO_ENGINE_SUBSTITUTION',
            ],
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // OTHER CONNECTIONS

],   
```
If you use docker try to add setting to laradock or mysql docker configurations.
For my.cnf file add [mysqld] section and everything that folows that section

/laradock/mysql/my.cnf
```conf
# The MySQL  Client configuration file.
#
# For explanations see
# http://dev.mysql.com/doc/mysql/en/server-system-variables.html

[mysql]

[mysqld]
sql-mode="STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"
character-set-server=utf8
default-authentication-plugin=mysql_native_password
```

For docker-compose.yml file add 'command' section and - "--default-authentication-plugin=mysql_native_password" parameter

/laradock/docker-compose.yml

```yml
### MySQL ################################################
    mysql:
      build:
        context: ./mysql
        args:
          - MYSQL_VERSION=${MYSQL_VERSION}
      command:
        - "--default-authentication-plugin=mysql_native_password"
      environment:
        - MYSQL_DATABASE=${MYSQL_DATABASE}
        - MYSQL_USER=${MYSQL_USER}
        - MYSQL_PASSWORD=${MYSQL_PASSWORD}
        - MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
        - TZ=${WORKSPACE_TIMEZONE}
      volumes:
        - ${DATA_PATH_HOST}/mysql:/var/lib/mysql
        - ${MYSQL_ENTRYPOINT_INITDB}:/docker-entrypoint-initdb.d
      ports:
        - "${MYSQL_PORT}:3306"
      networks:
        - backend
```

## Tenant migrations folder and copy of migration files

Tenats must have separate directory where all tenant's migration will be. THat is all migration that should run for every tenant when it will be created shoul be in special directory which we need to specify in specific place in configurations.

```bash
## Copy user tables migrations to tenant folder to have per-tenant user tables
# Make `database/migrations/tenant` folder
mkdir database/migrations/tenant
# Copy `2014_10_12_000000_create_users_table.php` and `2014_10_12_100000_create_password_resets_table.php`
# to the newly created folder so we will create user tables per tenant.
cp database/migrations/2014_10_12_000000_create_users_table.php database/migrations/tenant/
cp database/migrations/2014_10_12_100000_create_password_resets_table.php database/migrations/tenant/
```

You have created migration for customers so just change the name of migration file to yours 

```bash
cp database/migrations/2020_12_21_170414_create_customers_table.php database/migrations/tenant/
```

As for migration directory configration we need to create listener where we set up the migration path for tenant.
To prevent error when we delete tenant we avoid event activation if it delete event have been trigered.

```php
namespace App\Listeners;

use Tenancy\Hooks\Migration\Events\ConfigureMigrations;
use Tenancy\Tenant\Events\Deleted;

class ConfigureTenantMigrations
{
    public function handle(ConfigureMigrations $event): void
    {
        $event->path(database_path('migrations/tenant'));

        if($event->event instanceof Deleted) {
            $event->disable();
        }
    }
}
```

## Sessions table migration

We use sessions in our CustomerController so we need sesions table to save some records in it.

Create migration with
```bash
php artisan make:migration create_sessions_table
```

Make it look like:
```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSessionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sessions');
    }
}
```

## Customer root seeder

When our project will be ready we need some customer to be created so we will use seeder to save needed first record in customers table. We need this because we use identification methods for tenant. If ther will be no tenants we will have error.

To create an seeder run command:
```bash
php artisan make:seeder CustomerRootSeeder
```
and change it:

```php
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
```

## Resources + views + routes for forms to create customers

Last but not least thing is routes, resources and views.

### Views

Create customers folder inside views folder. Create next files in customers folder:
resources/views/customers/index.blade.php
```html
<!DOCTYPE html>
<html>
<head>
    <title>customer App</title>
    <link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.0.0/css/bootstrap.min.css">
</head>
<body>
<div class="container">

    <nav class="navbar navbar-inverse">
        <div class="navbar-header">
            <a class="navbar-brand" href="{{ URL::to('customers') }}">customer Alert</a>
        </div>
        <ul class="nav navbar-nav">
            <li><a href="{{ URL::to('customers') }}">View All customers</a></li>
            <li><a href="{{ URL::to('customers/create') }}">Create a customer</a>
        </ul>
    </nav>

    <h1>All the customers</h1>

    <!-- will be used to show any messages -->
    @if (Session::has('message'))
        <div class="alert alert-info">{{ Session::get('message') }}</div>
    @endif

    <table class="table table-striped table-bordered">
        <thead>
        <tr>
            <td>ID</td>
            <td>UUID</td>
            <td>FQDN</td>
            <td>Actions</td>
        </tr>
        </thead>
        <tbody>
        @foreach($customers as $customer => $value)
            <tr>
                <td>{{ $value->id }}</td>
                <td>{{ $value->uuid }}</td>
                <td>{{ $value->fqdn }}</td>

                <!-- we will also add show, edit, and delete buttons -->
                <td>

                    <!-- delete the customer (uses the destroy method DESTROY /customers/{id} -->
                    <!-- we will add this later since its a little more complicated than the other two buttons -->
                    {{ Form::open(array('url' => 'customers/' . $value->id, 'class' => 'pull-right')) }}
                    @csrf
                    {{ Form::hidden('_method', 'DELETE') }}
                    {{ Form::submit('Delete this customer', array('class' => 'btn btn-warning')) }}
                    {{ Form::close() }}

                    <!-- show the customer (uses the show method found at GET /customers/{id} -->
                    <a class="btn btn-small btn-success" href="{{ URL::to('customers/' . $value->id) }}">Show this customer</a>

                    <!-- edit this customer (uses the edit method found at GET /customers/{id}/edit -->
                    <a class="btn btn-small btn-info" href="{{ URL::to('customers/' . $value->id . '/edit') }}">Edit this customer</a>

                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

</div>
</body>
</html>
```
resources/views/customers/create.blade.php
```html
<!DOCTYPE html>
<html>
    <head>
        <title>customer App</title>
        <link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.0.0/css/bootstrap.min.css">
    </head>
    <body>
        <div class="container">

            <nav class="navbar navbar-inverse">
                <div class="navbar-header">
                    <a class="navbar-brand" href="{{ URL::to('customers') }}">customer Alert</a>
                </div>
                <ul class="nav navbar-nav">
                    <li><a href="{{ URL::to('customers') }}">View All customers</a></li>
                    <li><a href="{{ URL::to('customers/create') }}">Create a customer</a>
                </ul>
            </nav>

            <h1>Create a customer</h1>

            <!-- if there are creation errors, they will show here -->
            {{ HTML::ul($errors->all()) }}


            <form method="POST" action={{ url("customers") }} accept-charset="UTF-8">
                @csrf
                <div class="form-group">
                    <label for="fqdn">FQDN</label>
                    <input class="form-control" name="fqdn" type="text" id="fqdn" placeholder="{{old('fqdn')}}">
                </div>
                <input class="btn btn-primary" type="submit" value="Create the customer!">
            </form>

        </div>
    </body>
</html>
```
for future times
*  resources/views/customers/edit.blade.php
*  resources/views/customers/show.blade.php



### Routes

routes/web.php

```php
<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});
Route::resource('customers', 'CustomerController');

```

To make routes work you need to uncomment namespace property in \App\Providers\RouteServiceProvider

```php
namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
     protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}
```

## Last steps

```bash
php artisan migrate --database=system

composer dump-autoload
php artisan db:seed --class=CustomerRootSeeder

php artisan routes:clear
php artisan views:clear
php artisan config:clear
php artisan config:cache
```

### Done!

You're all done now, congratulations! You've now setup your own multi tenancy setup using the Tenancy Ecosystem!

You should have the following result:

    Creating a new Customer (or your own tenant model), will result in a new database creation
    Switching to the tenant will create a new tenant connection.


For example you may look at my implementation of this project in github

* https://github.com/futsurdmitriy/laravel-tenancy-1.x-tries
_________________________________________________________________________

# Test info below --- not needed for now ---
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
# Test info above --- not needed for now ---