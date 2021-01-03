<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
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
//        dump(__FILE__ . __METHOD__ . __LINE__);
//        dump($request->getHttpHost());
//        dump(
//            $this->query()
//                ->where('fqdn', $request->getHttpHost())
//                ->first()
//        );
        return $this->query()
            ->where('fqdn', $request->getHttpHost())
            ->first();
    }
}
