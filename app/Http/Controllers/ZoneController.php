<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Admin\Zone;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Objects\LineString;
use MatanYadaev\EloquentSpatial\Objects\Polygon;
use MatanYadaev\EloquentSpatial\Objects\MultiPolygon;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Base\Libraries\QueryFilter\QueryFilterContract;
use App\Base\Filters\Admin\ZoneFilter;
use App\Models\Admin\Setting;

class ZoneController extends Controller
{
    public function index() {
        $settings = Setting::where('category', 'peak_zone_settings')->get()->pluck('value', 'name')->toArray();
        return inertia('pages/zone/index', ['app_for'=>env('APP_FOR'),'settings' => $settings]);
    }

    public function updateZoneFeature(Request $request)
    {
        $validated = $request->validate([
            'enable_peak_zone_feature' => 'required|boolean',
        ]);

        Setting::updateOrCreate(
            ['name' => 'enable_peak_zone_feature', 'category' => 'peak_zone_settings'],
            ['value' => $validated['enable_peak_zone_feature']]
        );

        return response()->json(['success' => true]);
    }

    public function fetch(QueryFilterContract $queryFilter)
    {
        $query = Zone::query();

        $results = $queryFilter->builder($query)->customFilter(new ZoneFilter)->paginate();

        // Convert geometry to readable format for each zone
        $items = collect($results->items())->map(function ($zone) {
            if ($zone->coordinates) {
                // Convert coordinates to array format
                $coordinatesArray = [];
                if ($zone->coordinates instanceof MultiPolygon) {
                    foreach ($zone->coordinates->getPolygons() as $polygon) {
                        foreach ($polygon->getLineStrings() as $lineString) {
                            $points = [];
                            foreach ($lineString->getPoints() as $point) {
                                $points[] = [$point->getLng(), $point->getLat()];
                            }
                            $coordinatesArray[] = $points;
                        }
                    }
                }
                $zone->coordinates_array = $coordinatesArray;
                unset($zone->coordinates); // Remove binary data
            }
            return $zone;
        });

        return response()->json([
            'results' => $items,
            'paginator' => $results,
        ]);
    }

    public function create()
    {
        $googleMapKey = get_map_settings('google_map_key');
        $settings = Setting::where('category', 'peak_zone_settings')->get()->pluck('value', 'name')->toArray();

        $map_type = get_map_settings('map_type');
        $existingZones = Zone::companyKey()->get();
        $existing_coordinates = [];

        foreach ($existingZones as $zone) {
            $multiPolygon = $zone->coordinates;

            if ($multiPolygon instanceof MultiPolygon) {
                foreach ($multiPolygon->getPolygons() as $polygon) {
                    foreach ($polygon->getLineStrings() as $lineString) {
                        $polygonPoints = [];
                        foreach ($lineString->getPoints() as $point) {
                            $polygonPoints[] = [
                                'lat' => $point->getLat(),
                                'lng' => $point->getLng(),
                            ];
                        }
                        $existing_coordinates[] = $polygonPoints;
                    }
                }
            }
        }

        if($map_type=="open_street_map") {
            return inertia('pages/zone/open-create',[
                'enable_maximum_distance_feature'=>get_settings('enable_maximum_distance_feature') == 1,
                'default_lat'=>get_settings('default_latitude'),
                'default_lng'=>get_settings('default_longitude'),
                'existingZones'=>$existing_coordinates,
                'settings' => $settings
            ]);
        } else {
            return inertia('pages/zone/create',[
                'enable_maximum_distance_feature'=>get_settings('enable_maximum_distance_feature') == 1,
                'default_lat'=>get_settings('default_latitude'),
                'default_lng'=>get_settings('default_longitude'),
                'googleMapKey' => $googleMapKey,
                'existingZones'=>$existing_coordinates,
                'settings' => $settings
            ]);
        }
    }

    public function store(Request $request)
    {
        if(env('APP_FOR') == 'demo'){
            return response()->json(['alertMessage' => 'You are not Authorized'], 403);
        }

        $validated = $request->validate(['languageFields' => 'required|array']);
        $created_params = $request->only([
            'service_location_id','unit','maximum_outstation_distance','maximum_distance',
            'peak_zone_radius','peak_zone_duration','peak_zone_history_duration',
            'peak_zone_ride_count','distance_price_percentage'
        ]);

        $created_params['unit'] = (int) $request->unit;

        if (!$request->coordinates) {
            throw ValidationException::withMessages(['name' => __('Please Complete the shape before submit')]);
        }

        $decodedCoordinates = json_decode($request->coordinates, true);
        if ($decodedCoordinates === null) {
            throw ValidationException::withMessages(['coordinates' => __('Invalid coordinates format')]);
        }

        // Build WKT string manually for better control
        $polygonStrings = [];

        foreach ($decodedCoordinates as $coordinates) {
            $points = [];
            $coordinateStrings = [];

            foreach ($coordinates as $key => $coordinate) {
                if (is_array($coordinate) && count($coordinate) === 2) {
                    $lng = (float)$coordinate[0];
                    $lat = (float)$coordinate[1];

                    if ($key == 0) {
                        $created_params['lat'] = $lat;
                        $created_params['lng'] = $lng;
                    }

                    // Check if coordinates exist in other zones
                    $point = new Point($lat, $lng);
                    $check_if_exists = Zone::companyKey()->whereContains('coordinates', $point)->exists();
                    if ($check_if_exists) {
                        throw ValidationException::withMessages(['zone_name' => __('Coordinates already exists with our exists zone')]);
                    }

                    $points[] = $point;
                    $coordinateStrings[] = "$lng $lat";
                } else {
                    throw ValidationException::withMessages(['coordinates' => __('Invalid coordinate data')]);
                }
            }

            // Close the polygon by adding first point at the end
            if (count($points) > 0) {
                $firstCoord = $decodedCoordinates[0][0];
                $coordinateStrings[] = ((float)$firstCoord[0]) . " " . ((float)$firstCoord[1]);
                array_push($points, $points[0]);
            }

            $polygonStrings[] = "(" . implode(", ", $coordinateStrings) . ")";

            $lineStrings = [new LineString($points)];
            $polygons[] = new Polygon($lineStrings);
        }

        // Create WKT string
        $wkt = "MULTIPOLYGON((" . implode(", ", $polygonStrings) . "))";

        // Use DB::raw with ST_GeomFromText to create the geometry
        $created_params['name'] = $validated['languageFields']['en'];

        // Create zone using raw SQL for coordinates
        $zone = new Zone();
        $zone->service_location_id = $created_params['service_location_id'] ?? null;
        $zone->unit = $created_params['unit'];
        $zone->lat = $created_params['lat'];
        $zone->lng = $created_params['lng'];
        $zone->name = $created_params['name'];
        $zone->maximum_outstation_distance = $created_params['maximum_outstation_distance'] ?? 0;
        $zone->maximum_distance = $created_params['maximum_distance'] ?? 0;
        $zone->peak_zone_radius = $created_params['peak_zone_radius'] ?? 0;
        $zone->peak_zone_duration = $created_params['peak_zone_duration'] ?? 0;
        $zone->peak_zone_history_duration = $created_params['peak_zone_history_duration'] ?? 0;
        $zone->peak_zone_ride_count = $created_params['peak_zone_ride_count'] ?? 0;
        $zone->distance_price_percentage = $created_params['distance_price_percentage'] ?? 0;

        // Set coordinates using raw SQL
        $zone->coordinates = DB::raw("ST_GeomFromText('$wkt')");
        $zone->save();

        foreach ($validated['languageFields'] as $code => $language) {
            $translationData[] = ['name' => $language, 'locale' => $code, 'zone_id' => $zone->id];
            $translations_data[$code] = (object)['locale'=>$code,'name'=>$language];
        }

        $zone->zoneTranslationWords()->insert($translationData);
        $zone->translation_dataset = json_encode($translations_data);
        $zone->save();

        return response()->json(['zone' => $zone], 201);
    }

    public function list()
    {
        $results = get_user_locations(auth()->user());
        return response()->json(['results' => $results]);
    }

    public function edit($id)
    {
        $zone = Zone::findOrFail($id);
        $googleMapKey = get_map_settings('google_map_key');
        $settings = Setting::where('category', 'peak_zone_settings')->get()->pluck('value', 'name')->toArray();

        $existingZones = Zone::companyKey()->where('id','!=',$id)->get();
        $existing_coordinates = [];

        foreach ($existingZones as $existingZone) {
            $multiPolygon = $existingZone->coordinates;
            if ($multiPolygon instanceof MultiPolygon) {
                foreach ($multiPolygon->getPolygons() as $polygon) {
                    foreach ($polygon->getLineStrings() as $lineString) {
                        $polygonPoints = [];
                        foreach ($lineString->getPoints() as $point) {
                            $polygonPoints[] = ['lat' => $point->getLat(), 'lng' => $point->getLng()];
                        }
                        $existing_coordinates[] = $polygonPoints;
                    }
                }
            }
        }

        // Convert zone coordinates to array format
        $zone_coordinates = [];
        if ($zone->coordinates instanceof MultiPolygon) {
            foreach ($zone->coordinates->getPolygons() as $polygon) {
                foreach ($polygon->getLineStrings() as $lineString) {
                    $points = [];
                    foreach ($lineString->getPoints() as $point) {
                        $points[] = [$point->getLng(), $point->getLat()];
                    }
                    $zone_coordinates[] = $points;
                }
            }
        }
        $zone->coordinates_array = json_encode($zone_coordinates);
        unset($zone->coordinates); // Remove binary data

        foreach ($zone->zoneTranslationWords as $language) {
            $languageFields[$language->locale] = $language->name;
        }
        $zone->languageFields = $languageFields ?? null;

        $map_type = get_map_settings('map_type');

        if($map_type=="open_street_map") {
            return inertia('pages/zone/open-edit',[
                'zone' => $zone,
                'enable_maximum_distance_feature'=>get_settings('enable_maximum_distance_feature') == 1,
                'default_lat'=>get_settings('default_latitude'),
                'default_lng'=>get_settings('default_longitude'),
                'existingZones'=>$existing_coordinates,
                'settings' => $settings
            ]);
        } else {
            return inertia('pages/zone/edit',[
                'zone' => $zone,
                'enable_maximum_distance_feature'=>get_settings('enable_maximum_distance_feature') == 1,
                'default_lat'=>get_settings('default_latitude'),
                'default_lng'=>get_settings('default_longitude'),
                'existingZones'=>$existing_coordinates,
                'googleMapKey' => $googleMapKey,
                'app_for'=>env('APP_FOR'),
                'settings' => $settings
            ]);
        }
    }

    public function update(Request $request, Zone $zone)
    {
        if(env('APP_FOR') == 'demo'){
            return response()->json(['alertMessage' => 'You are not Authorized'], 403);
        }

        $validated = $request->validate([
            'unit' => 'required',
            'languageFields' => 'required|array',
        ]);

        $updated_params = [
            'unit' => (int)$request->unit,
            'maximum_distance' => (double)$request->maximum_distance ?? 0,
            'maximum_outstation_distance' => (double)$request->maximum_outstation_distance ?? 0,
            'peak_zone_radius' => (double)$request->peak_zone_radius ?? 0,
            'peak_zone_ride_count' => (double)$request->peak_zone_ride_count ?? 0,
            'distance_price_percentage' => (double)$request->distance_price_percentage ?? 0,
            'peak_zone_duration' => (double)$request->peak_zone_duration ?? 0,
            'peak_zone_history_duration' => (double)$request->peak_zone_history_duration ?? 0,
            'service_location_id' => $request->service_location_id
        ];

        if (!$request->coordinates) {
            throw ValidationException::withMessages(['name' => __('Please Complete the shape before submit')]);
        }

        $decodedCoordinates = json_decode($request->coordinates, true);
        if ($decodedCoordinates === null) {
            throw ValidationException::withMessages(['coordinates' => __('Invalid coordinates format')]);
        }

        // Build WKT string manually
        $polygonStrings = [];
        $polygons = [];

        foreach ($decodedCoordinates as $coordinates) {
            $points = [];
            $coordinateStrings = [];

            foreach ($coordinates as $key => $coordinate) {
                if (is_array($coordinate) && count($coordinate) === 2) {
                    $lng = (float)$coordinate[0];
                    $lat = (float)$coordinate[1];

                    if ($key == 0) {
                        $updated_params['lat'] = $lat;
                        $updated_params['lng'] = $lng;
                    }

                    $point = new Point($lat, $lng);
                    $check_if_exists = Zone::companyKey()->whereContains('coordinates', $point)->where('id','!=',$zone->id)->exists();
                    if ($check_if_exists) {
                        throw ValidationException::withMessages(['zone_name' => __('Coordinates already exists with our exists zone')]);
                    }

                    $points[] = $point;
                    $coordinateStrings[] = "$lng $lat";
                } else {
                    throw ValidationException::withMessages(['coordinates' => __('Invalid coordinate data')]);
                }
            }

            if (count($points) > 0) {
                $firstCoord = $decodedCoordinates[0][0];
                $coordinateStrings[] = ((float)$firstCoord[0]) . " " . ((float)$firstCoord[1]);
                array_push($points, $points[0]);
            }

            $polygonStrings[] = "(" . implode(", ", $coordinateStrings) . ")";

            $lineStrings = [new LineString($points)];
            $polygons[] = new Polygon($lineStrings);
        }

        // Create WKT string
        $wkt = "MULTIPOLYGON((" . implode(", ", $polygonStrings) . "))";

        $updated_params['name'] = $validated['languageFields']['en'];

        // Update zone fields
        $zone->service_location_id = $updated_params['service_location_id'];
        $zone->unit = $updated_params['unit'];
        $zone->lat = $updated_params['lat'];
        $zone->lng = $updated_params['lng'];
        $zone->name = $updated_params['name'];
        $zone->maximum_distance = $updated_params['maximum_distance'];
        $zone->maximum_outstation_distance = $updated_params['maximum_outstation_distance'];
        $zone->peak_zone_radius = $updated_params['peak_zone_radius'];
        $zone->peak_zone_ride_count = $updated_params['peak_zone_ride_count'];
        $zone->distance_price_percentage = $updated_params['distance_price_percentage'];
        $zone->peak_zone_duration = $updated_params['peak_zone_duration'];
        $zone->peak_zone_history_duration = $updated_params['peak_zone_history_duration'];

        // Update coordinates using raw SQL
        DB::table('zones')
            ->where('id', $zone->id)
            ->update(['coordinates' => DB::raw("ST_GeomFromText('$wkt')")]);

        $zone->zoneTranslationWords()->delete();
        foreach ($validated['languageFields'] as $code => $language) {
            $translationData[] = ['name' => $language, 'locale' => $code, 'zone_id' => $zone->id];
            $translations_data[$code] = (object)['locale'=>$code,'name'=>$language];
        }

        $zone->zoneTranslationWords()->insert($translationData);
        $zone->translation_dataset = json_encode($translations_data);
        $zone->save();

        return response()->json(['zone' => $zone], 200);
    }

    public function destroy(Zone $zone)
    {
        if(env('APP_FOR') == 'demo'){
            return response()->json(['alertMessage' => 'You are not Authorized'], 403);
        }
        $zone->delete();

        return response()->json(['successMessage' => 'Zone deleted successfully']);
    }

    public function updateStatus(Request $request)
    {
        if(env('APP_FOR') == 'demo'){
            return response()->json(['alertMessage' => 'You are not Authorized'], 403);
        }

        Zone::where('id', $request->id)->update(['active'=> $request->status]);

        return response()->json(['successMessage' => 'Zone status updated successfully']);
    }

    public function map($id)
    {
        $zone = Zone::findOrFail($id);
        $googleMapKey = get_map_settings('google_map_key');
        $map_type = get_map_settings('map_type');

        if($map_type=="open_street_map") {
            return inertia('pages/zone/open-map', ['zone' => $zone]);
        } else {
            return inertia('pages/zone/map', ['zone' => $zone,'googleMapKey'=>$googleMapKey]);
        }
    }
}
