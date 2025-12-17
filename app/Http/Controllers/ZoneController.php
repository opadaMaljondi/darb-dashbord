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

        // Build proper MultiPolygon object
        $polygons = [];

        foreach ($decodedCoordinates as $coordinates) {
            $points = [];

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
                } else {
                    throw ValidationException::withMessages(['coordinates' => __('Invalid coordinate data')]);
                }
            }

            // Close the polygon by adding first point at the end
            if (count($points) > 0) {
                array_push($points, $points[0]);
            }

            $lineStrings = [new LineString($points)];
            $polygons[] = new Polygon($lineStrings);
        }

        // Create MultiPolygon object (this will be properly cast)
        $multiPolygon = new MultiPolygon($polygons);

        $created_params['name'] = $validated['languageFields']['en'];
        $created_params['coordinates'] = $multiPolygon;
        $created_params['maximum_outstation_distance'] = $created_params['maximum_outstation_distance'] ?? 0;
        $created_params['maximum_distance'] = $created_params['maximum_distance'] ?? 0;
        $created_params['peak_zone_radius'] = $created_params['peak_zone_radius'] ?? 0;
        $created_params['peak_zone_duration'] = $created_params['peak_zone_duration'] ?? 0;
        $created_params['peak_zone_history_duration'] = $created_params['peak_zone_history_duration'] ?? 0;
        $created_params['peak_zone_ride_count'] = $created_params['peak_zone_ride_count'] ?? 0;
        $created_params['distance_price_percentage'] = $created_params['distance_price_percentage'] ?? 0;

        // Create zone using Eloquent (this properly handles the geometry)
        $zone = Zone::create($created_params);

        // Add translations
        $translationData = [];
        $translations_data = [];
        foreach ($validated['languageFields'] as $code => $language) {
            $translationData[] = ['name' => $language, 'locale' => $code, 'zone_id' => $zone->id];
            $translations_data[$code] = (object)['locale'=>$code,'name'=>$language];
        }

        $zone->zoneTranslationWords()->insert($translationData);
        $zone->translation_dataset = json_encode($translations_data);
        $zone->save();

        // Return zone without binary coordinates
        $zoneData = $zone->toArray();
        unset($zoneData['coordinates']);

        return response()->json(['zone' => $zoneData], 201);
    }<?php

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
use Illuminate\Support\Str;
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
                    foreach ($zone->coordinates as $polygon) {
                        foreach ($polygon as $lineString) {
                            $points = [];
                            foreach ($lineString as $point) {
                                $points[] = [$point->longitude, $point->latitude];
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
                // For MatanYadaev library, MultiPolygon is iterable
                foreach ($multiPolygon as $polygon) {
                    // Polygon is also iterable, contains LineStrings
                    foreach ($polygon as $lineString) {
                        $polygonPoints = [];
                        foreach ($lineString as $point) {
                            $polygonPoints[] = [
                                'lat' => $point->latitude,
                                'lng' => $point->longitude,
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
        $polygons = [];

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
                $firstCoord = $coordinates[0];
                $coordinateStrings[] = ((float)$firstCoord[0]) . " " . ((float)$firstCoord[1]);
                array_push($points, $points[0]);
            }

            $polygonStrings[] = "(" . implode(", ", $coordinateStrings) . ")";

            $lineStrings = [new LineString($points)];
            $polygons[] = new Polygon($lineStrings);
        }

        // Create WKT string
        $wkt = "MULTIPOLYGON((" . implode(", ", $polygonStrings) . "))";

        // Create zone using Eloquent first (without coordinates)
        $created_params['name'] = $validated['languageFields']['en'];

        // Generate ID first
        $zoneId = \Illuminate\Support\Str::uuid()->toString();

        // Insert using DB to handle geometry
        DB::table('zones')->insert([
            'id' => $zoneId,
            'service_location_id' => $created_params['service_location_id'] ?? null,
            'unit' => $created_params['unit'],
            'lat' => $created_params['lat'],
            'lng' => $created_params['lng'],
            'name' => $created_params['name'],
            'maximum_outstation_distance' => $created_params['maximum_outstation_distance'] ?? 0,
            'maximum_distance' => $created_params['maximum_distance'] ?? 0,
            'peak_zone_radius' => $created_params['peak_zone_radius'] ?? 0,
            'peak_zone_duration' => $created_params['peak_zone_duration'] ?? 0,
            'peak_zone_history_duration' => $created_params['peak_zone_history_duration'] ?? 0,
            'peak_zone_ride_count' => $created_params['peak_zone_ride_count'] ?? 0,
            'distance_price_percentage' => $created_params['distance_price_percentage'] ?? 0,
            'coordinates' => DB::raw("ST_GeomFromText('$wkt')"),
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Get the created zone
        $zone = Zone::find($zoneId);

        // Add translations
        $translationData = [];
        $translations_data = [];
        foreach ($validated['languageFields'] as $code => $language) {
            $translationData[] = ['name' => $language, 'locale' => $code, 'zone_id' => $zone->id];
            $translations_data[$code] = (object)['locale'=>$code,'name'=>$language];
        }

        $zone->zoneTranslationWords()->insert($translationData);
        $zone->translation_dataset = json_encode($translations_data);
        $zone->save();

        // Return zone without binary coordinates
        $zone = Zone::find($zoneId);
        $zoneData = $zone->toArray();
        unset($zoneData['coordinates']);

        return response()->json(['zone' => $zoneData], 201);
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
                // MultiPolygon is iterable, contains Polygons
                foreach ($multiPolygon as $polygon) {
                    // Polygon is iterable, contains LineStrings
                    foreach ($polygon as $lineString) {
                        $polygonPoints = [];
                        // LineString is iterable, contains Points
                        foreach ($lineString as $point) {
                            $polygonPoints[] = [
                                'lat' => $point->latitude,
                                'lng' => $point->longitude
                            ];
                        }
                        $existing_coordinates[] = $polygonPoints;
                    }
                }
            }
        }

        // Convert zone coordinates to array format
        $zone_coordinates = [];
        if ($zone->coordinates instanceof MultiPolygon) {
            foreach ($zone->coordinates as $polygon) {
                foreach ($polygon as $lineString) {
                    $points = [];
                    foreach ($lineString as $point) {
                        $points[] = [$point->longitude, $point->latitude];
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

        // Build proper MultiPolygon object
        $polygons = [];

        foreach ($decodedCoordinates as $coordinates) {
            $points = [];

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
                } else {
                    throw ValidationException::withMessages(['coordinates' => __('Invalid coordinate data')]);
                }
            }

            if (count($points) > 0) {
                array_push($points, $points[0]);
            }

            $lineStrings = [new LineString($points)];
            $polygons[] = new Polygon($lineStrings);
        }

        // Create MultiPolygon object
        $multiPolygon = new MultiPolygon($polygons);

        $updated_params['name'] = $validated['languageFields']['en'];
        $updated_params['coordinates'] = $multiPolygon;

        $zone->zoneTranslationWords()->delete();
        $translationData = [];
        $translations_data = [];
        foreach ($validated['languageFields'] as $code => $language) {
            $translationData[] = ['name' => $language, 'locale' => $code, 'zone_id' => $zone->id];
            $translations_data[$code] = (object)['locale'=>$code,'name'=>$language];
        }

        $zone->zoneTranslationWords()->insert($translationData);
        $updated_params['translation_dataset'] = json_encode($translations_data);

        $zone->update($updated_params);

        // Return zone without binary coordinates
        $zoneData = $zone->toArray();
        unset($zoneData['coordinates']);

        return response()->json(['zone' => $zoneData], 200);
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
