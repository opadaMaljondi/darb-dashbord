<?php

namespace App\Http\Controllers\Web\Admin;

use App\Models\User;
use App\Models\Admin\Airport;
use App\Http\Controllers\ApiController;
use Inertia\Inertia;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Objects\LineString;
use MatanYadaev\EloquentSpatial\Objects\Polygon;
use MatanYadaev\EloquentSpatial\Objects\MultiPolygon;
use App\Base\Constants\Auth\Role as RoleSlug;
use App\Base\Exceptions\CustomValidationException;
use App\Base\Filters\Master\CommonMasterFilter;
use App\Base\Libraries\QueryFilter\QueryFilterContract;
use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Request\Request;
use Carbon\Carbon;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Stream;
use Prewk\XmlStringStreamer\Parser;

/**
 * @resource Airport
 *
 * Airport CRUD Apis
 */
class AirportController extends BaseController
{
    protected $airport;

    public function __construct(Airport $airport)
    {
        $this->airport = $airport;
    }

    public function index()
    {
        return inertia('airport/index', ['app_for'=>env('APP_FOR')]);
    }

    public function getAllAirports(QueryFilterContract $queryFilter)
    {
        $query = Airport::companyKey();
        $results = $queryFilter->builder($query)->customFilter(new CommonMasterFilter)->paginate();

        return response()->json([
            'results' => $results->items(),
            'paginator' => $results,
        ]);
    }

    public function create()
    {
        $googleMapKey = get_map_settings('google_map_key');
        $map_type = get_map_settings('map_type');

        if ($map_type == "open_street_map") {
            return inertia('airport/open-create', [
                'default_lat'=>get_settings('default_latitude'),
                'default_lng'=>get_settings('default_longitude'),
            ]);
        } else {
            return inertia('airport/create', [
                'default_lat'=>get_settings('default_latitude'),
                'default_lng'=>get_settings('default_longitude'),
                'googleMapKey' => $googleMapKey,
            ]);
        }
    }

    public function getById($id)
    {
        $airport = Airport::findOrFail($id);
        $googleMapKey = get_map_settings('google_map_key');
        $map_type = get_map_settings('map_type');

        if ($map_type == "open_street_map") {
            return inertia('airport/open-edit', [
                'airport' => $airport,
                'default_lat'=>get_settings('default_latitude'),
                'default_lng'=>get_settings('default_longitude'),
            ]);
        } else {
            return inertia('airport/edit', [
                'airport' => $airport,
                'default_lat'=>get_settings('default_latitude'),
                'default_lng'=>get_settings('default_longitude'),
                'googleMapKey' => $googleMapKey,
                'app_for'=>env('APP_FOR'),
            ]);
        }
    }

    public function store(HttpRequest $request)
    {
        if(env('APP_FOR') == 'demo'){
            return response()->json(['alertMessage' => 'You are not Authorized'], 403);
        }

        $validated = $request->validate(['name' => 'required']);
        $created_params = $request->only(['service_location_id','airport_surge_fee']);
        $set = [];

        if (!$request->coordinates) {
            throw ValidationException::withMessages(['name' => __('Please Complete the shape before submit')]);
        }

        $decodedCoordinates = json_decode($request->coordinates, true);
        if ($decodedCoordinates === null) {
            throw ValidationException::withMessages(['coordinates' => __('Invalid coordinates format')]);
        }

        foreach ($decodedCoordinates as $coordinates) {
            $points = [];
            foreach ($coordinates as $key => $coordinate) {
                if (is_array($coordinate) && count($coordinate) === 2) {
                    if ($key == 0) {
                        $created_params['lat'] = (float)$coordinate[1];
                        $created_params['lng'] = (float)$coordinate[0];
                    }

                    $point = new Point((float)$coordinate[1], (float)$coordinate[0]);

                    $check_if_exists = Airport::companyKey()->whereContains('coordinates', $point)->exists();
                    if ($check_if_exists) {
                        throw ValidationException::withMessages(['airport_name' => __('Coordinates already exists with our exists airport')]);
                    }

                    $points[] = $point;
                } else {
                    throw ValidationException::withMessages(['coordinates' => __('Invalid coordinate data')]);
                }
            }

            if (count($points) > 0) {
                array_push($points, $points[0]); // Close polygon
            }

            $lineStrings = [new LineString($points)];
            $set[] = new Polygon($lineStrings);
        }

        $multi_polygon = new MultiPolygon($set);

        $created_params['name'] = $request->input('name');
        $created_params['airport_surge_fee'] = $request->input('airport_surge_fee') ?? 0;
        $created_params['coordinates'] = $multi_polygon;
        $created_params['company_key'] = auth()->user()->company_key;

        $airport = $this->airport->create($created_params);

        return response()->json(['airport' => $airport], 201);
    }

    public function list() 
    {
        $results = get_user_locations(auth()->user());
        return response()->json(['results' => $results]);
    }

    public function update(Airport $airport, HttpRequest $request)
    {
        if(env('APP_FOR') == 'demo'){
            return response()->json(['alertMessage' => 'You are not Authorized'], 403);
        }

        $validated = $request->validate([
            'coordinates' => 'required',
            'name' => 'required',
        ]);

        $updated_params['service_location_id'] = $request->service_location_id;
        $set = [];

        $decodedCoordinates = json_decode($request->coordinates, true);
        if ($decodedCoordinates === null) {
            throw ValidationException::withMessages(['coordinates' => __('Invalid coordinates format')]);
        }

        foreach ($decodedCoordinates as $coordinates) {
            $points = [];
            foreach ($coordinates as $key => $coordinate) {
                if (is_array($coordinate) && count($coordinate) === 2) {
                    if ($key == 0) {
                        $updated_params['lat'] = (float)$coordinate[1];
                        $updated_params['lng'] = (float)$coordinate[0];
                    }

                    $point = new Point((float)$coordinate[1], (float)$coordinate[0]);

                    $check_if_exists = Airport::companyKey()
                        ->whereContains('coordinates', $point)
                        ->where('id','!=',$airport->id)
                        ->exists();

                    if ($check_if_exists) {
                        throw ValidationException::withMessages(['airport_name' => __('Coordinates already exists with our exists airport')]);
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
            $set[] = new Polygon($lineStrings);
        }

        $multi_polygon = new MultiPolygon($set);

        $updated_params['name'] = $validated['name'];
        $updated_params['coordinates'] = $multi_polygon;

        $airport->update($updated_params);

        return response()->json(['airport' => $airport], 200);
    }

    public function airportMapView($id)
    {
        $airport = Airport::findOrFail($id);
        $googleMapKey = get_map_settings('google_map_key');
        $map_type = get_map_settings('map_type');

        if ($map_type=="open_street_map") {
            return inertia('airport/open-map', ['airport' => $airport]);
        } else {
            return inertia('airport/map', [
                'airport' => $airport,
                'googleMapKey' => $googleMapKey,
            ]);
        }
    }

    public function delete(Airport $airport)
    {
        if(env('APP_FOR') == 'demo'){
            return response()->json(['alertMessage' => 'You are not Authorized'], 403);
        }
        $airport->delete();

        return response()->json(['successMessage' => 'Airport deleted successfully']);
    }

    public function toggleAirportStatus(Airport $airport,HttpRequest $request)
    {
        if(env('APP_FOR') == 'demo'){
            return response()->json(['alertMessage' => 'You are not Authorized'], 403);
        }
        Airport::where('id', $request->id)->update(['active'=> $request->status]);

        return response()->json(['successMessage' => 'Airport status updated successfully']);
    }
}
