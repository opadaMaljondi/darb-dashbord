<?php

namespace App\Models\Admin;

use Carbon\Carbon;
use App\Models\User;
use App\Base\Uuid\UuidModel;
use App\Models\Traits\HasActive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Traits\HasActiveCompanyKey;
use Nicolaslopezj\Searchable\SearchableTrait;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;
use MatanYadaev\EloquentSpatial\Objects\MultiPolygon;
use MatanYadaev\EloquentSpatial\Objects\Point;

class Airport extends Model
{
    use HasActive, UuidModel,SearchableTrait,HasActiveCompanyKey;
    use HasSpatial;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'airports';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'service_location_id', 'name','active','coordinates','company_key','lat','lng','airport_surge_fee'
    ];

    protected $casts = [
        'coordinates' => MultiPolygon::class,
    ];

    /**
     * The relationships that can be loaded with query string filtering includes.
     *
     * @var array
     */
    public $includes = [
        'admin'
    ];


    public function serviceLocation()
    {
        return $this->belongsTo(ServiceLocation::class, 'service_location_id', 'id');
    }

    /**
    * Get formated and converted timezone of user's created at.
    *
    * @param string $value
    * @return string
    */
    public function getConvertedCreatedAtAttribute()
    {
        if ($this->created_at==null||!auth()->user()) {
            return null;
        }
        $timezone = auth()->user()->timezone?:env('SYSTEM_DEFAULT_TIMEZONE');
        return Carbon::parse($this->created_at)->setTimezone($timezone)->format('jS M h:i A');
    }
    /**
    * Get formated and converted timezone of user's created at.
    *
    * @param string $value
    * @return string
    */
    public function getConvertedUpdatedAtAttribute()
    {
        if ($this->updated_at==null||!auth()->user()) {
            return null;
        }
        $timezone = auth()->user()->timezone?:env('SYSTEM_DEFAULT_TIMEZONE');
        return Carbon::parse($this->updated_at)->setTimezone($timezone)->format('jS M h:i A');
    }

    protected $searchable = [
        'columns' => [
            'airports.name' => 20,
        ],
    ];

    /**
     * Scope a query to airports whose given spatial column contains the provided point.
     *
     * Mirrors the old Grimzy `contains` builder macro using MySQL ST_Contains.
     */
    public function scopeContains(Builder $query, string $column, Point $point): Builder
    {
        $table = $this->getTable();
        $wktPoint = sprintf('POINT(%F %F)', $point->longitude, $point->latitude); // POINT(lng lat)

        return $query->whereRaw("ST_Contains(`{$table}`.`{$column}`, ST_GeomFromText(?))", [$wktPoint]);
    }
}
