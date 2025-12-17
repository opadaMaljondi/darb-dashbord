<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\Request\RequestPlace;
use App\Models\Admin\PeakZone;
use Carbon\Carbon;
use Kreait\Firebase\Contract\Database;
use Sk\Geohash\Geohash;
use Illuminate\Support\Facades\App;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Objects\LineString;
use MatanYadaev\EloquentSpatial\Objects\Polygon;
use MatanYadaev\EloquentSpatial\Objects\MultiPolygon;

class ValidateAndGeneratePeakZone implements ShouldQueue
{
    use Dispatchable, Queueable, InteractsWithQueue, SerializesModels;

    protected $pick_lat;
    protected $pick_lng;
    protected $zone_id;
    protected $timezone;

    public function __construct($pick_lat, $pick_lng, $zone_id, $timezone)
    {
        $this->pick_lat = $pick_lat;
        $this->pick_lng = $pick_lng;
        $this->zone_id = $zone_id;
        $this->timezone = $timezone;
    }

    public function handle()
    {
        $database = App::make(Database::class);

        $zone = find_zone($this->pick_lat, $this->pick_lng);

        $search_radius = $zone->peak_zone_radius;
        $findable_duration = $zone->peak_zone_history_duration;
        $expiry_duration = $zone->peak_zone_duration;
        $distance_price_percentage = $zone->distance_price_percentage;
        $minimum_no_rides = $zone->peak_zone_ride_count;

        $haversine = "(6371 * acos(cos(radians($this->pick_lat)) * cos(radians(pick_lat)) * cos(radians(pick_lng) - radians($this->pick_lng)) + sin(radians($this->pick_lat)) * sin(radians(pick_lat))))";

        $current_time = Carbon::now()->format('Y-m-d H:i:s');
        $sub_15_min = Carbon::now()->subMinutes($findable_duration)->format('Y-m-d H:i:s');

        $nearest_rides = RequestPlace::select('request_places.*')
            ->selectRaw("{$haversine} AS distance")
            ->whereRaw("{$haversine} < ?", [$search_radius])
            ->whereBetween('created_at', [$sub_15_min, $current_time])
            ->get();

        $ride_count = $nearest_rides->count();

        if ($ride_count == 0 || $minimum_no_rides > $ride_count) {
            return;
        }

        // Collect coordinates
        $coordinates = $nearest_rides->map(fn($ride) => [
            'lat' => (float)$ride->pick_lat,
            'lng' => (float)$ride->pick_lng,
            'pick_address' => $ride->pick_address,
        ])->toArray();

        // Calculate centroid
        $centroidLat = collect($coordinates)->avg('lat');
        $centroidLng = collect($coordinates)->avg('lng');

        // Find coordinate closest to centroid
        $centerCoord = collect($coordinates)->sortBy(fn($coord) => 
            pow($centroidLat - $coord['lat'], 2) + pow($centroidLng - $coord['lng'], 2)
        )->first();

        $center_lat = $centerCoord['lat'];
        $center_lng = $centerCoord['lng'];
        $zone_name = $centerCoord['pick_address'];

        // Check existing peak zone
        $peak_zone = find_peak_zone($center_lat, $center_lng);

        if ($peak_zone) {
            if ($peak_zone->active) {
                return;
            } else {
                $start_time = Carbon::now()->format('H:i:s');
                $end_time = Carbon::now()->addMinutes($expiry_duration)->format('H:i:s');
                $start_time_timestamp = Carbon::now()->timestamp;
                $end_time_timestamp = Carbon::now()->addMinutes($expiry_duration)->timestamp;

                $peak_zone->update([
                    'active' => true,
                    'start_time' => $start_time,
                    'end_time' => $end_time
                ]);

                $database->getReference('peak-zones/'.$peak_zone->id)->update([
                    'active' => true,
                    'start_time' => $start_time,
                    'start_time_timestamp' => $start_time_timestamp,
                    'end_time_timestamp' => $end_time_timestamp,
                    'end_time' => $end_time,
                    'updated_at' => Database::SERVER_TIMESTAMP
                ]);

                return;
            }
        }

        // Generate polygon coordinates
        $numPoints = 5;
        $polygonCoordinates = generatePolygonCoordinates($center_lat, $center_lng, $search_radius, $numPoints);

        $points = collect($polygonCoordinates)->map(fn($c) => new Point((float)$c['latitude'], (float)$c['longitude']))->toArray();
        array_push($points, $points[0]); // close polygon

        $lineString = new LineString($points);
        $polygon = new Polygon([$lineString]);
        $multiPolygon = new MultiPolygon([$polygon]);

        $start_time = Carbon::now()->format('H:i:s');
        $end_time = Carbon::now()->addMinutes($expiry_duration)->format('H:i:s');
        $start_time_timestamp = Carbon::now()->timestamp;
        $end_time_timestamp = Carbon::now()->addMinutes($expiry_duration)->timestamp;

        $created_params = [
            'zone_id' => $this->zone_id,
            'coordinates' => $multiPolygon,
            'unit' => 1,
            'lat' => $center_lat,
            'lng' => $center_lng,
            'name' => $zone_name,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'distance_price_percentage' => $distance_price_percentage
        ];

        $zone = PeakZone::create($created_params);

        $g = new Geohash();
        $geohash = $g->encode($center_lat, $center_lng, 12);

        $database->getReference('peak-zones/'.$zone->id)->set([
            'id' => $zone->id,
            'name' => $zone->name,
            'active' => 1,
            'g' => $geohash,
            'start_time' => $start_time,
            'start_time_timestamp' => $start_time_timestamp,
            'end_time_timestamp' => $end_time_timestamp,
            'end_time' => $end_time,
            'coordinates' => $polygonCoordinates,
            'updated_at' => Database::SERVER_TIMESTAMP
        ]);
        
        end:
    }
}
