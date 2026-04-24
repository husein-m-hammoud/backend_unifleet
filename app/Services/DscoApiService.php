<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DscoApiService
{
    private string $baseUrl;

    public function __construct(private DscoAuthService $auth)
    {
        $this->baseUrl = rtrim(config('services.dsco.base_url'), '/');
    }

    // -------------------------------------------------------------------------
    // Transport
    // -------------------------------------------------------------------------

    private function post(string $endpoint, array $body = []): mixed
    {
        $response = $this->request('post', $endpoint, $body);
        return $response->json('result');
    }

    private function get(string $endpoint, array $query = []): mixed
    {
        $response = $this->request('get', $endpoint, $query);
        return $response->json('result');
    }

    private function request(string $method, string $endpoint, array $data = [])
    {
        $token = $this->auth->getToken();

        // Always send POST bodies as JSON — DSCO's parser requires Content-Type: application/json.
        // Empty PHP array [] encodes to "[]" but DSCO expects "{}",
        // so cast to object when data is empty.
        $body = empty($data) ? new \stdClass() : $data;

        $http = Http::asJson()->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ]);

        $response = match ($method) {
            'post' => $http->post("{$this->baseUrl}/{$endpoint}", $body),
            'get'  => $http->get("{$this->baseUrl}/{$endpoint}", $data),
        };

        // Token expired — clear cache and retry once
        if ($response->status() === 401) {
            $this->auth->forgetToken();
            $token = $this->auth->getToken();
            $http  = Http::asJson()->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept'        => 'application/json',
            ]);
            $response = match ($method) {
                'post' => $http->post("{$this->baseUrl}/{$endpoint}", $body),
                'get'  => $http->get("{$this->baseUrl}/{$endpoint}", $data),
            };
        }

        $response->throw();

        return $response;
    }

    // -------------------------------------------------------------------------
    // Utility / Reference
    // -------------------------------------------------------------------------

    /** GET /api/v2/site/list */
    public function getSites(): array
    {
        return $this->post('api/v2/site/list') ?? [];
    }

    /** GET /api/v2/category/list */
    public function getCategories(): array
    {
        return $this->post('api/v2/category/list') ?? [];
    }

    /** GET /api/v2/geofence/list */
    public function getGeofences(): array
    {
        return $this->post('api/v2/geofence/list') ?? [];
    }

    // -------------------------------------------------------------------------
    // Vehicles
    // -------------------------------------------------------------------------

    /**
     * POST /api/v2/vehicle/list-info
     * Full vehicle list with device, driver, site, category embedded.
     */
    public function getVehicleListInfo(): array
    {
        return $this->post('api/v2/vehicle/list-info') ?? [];
    }

    /**
     * POST /api/v2/vehicle/last-state
     * Live state (position, speed, ignition, odometer) for a set of vehicles.
     *
     * @param  array $vehicleIds   DSCO vehicle IDs
     * @param  bool  $live         true = real-time, false = historical
     */
    public function getVehicleLastState(array $vehicleIds, bool $live = true): array
    {
        return $this->post('api/v2/vehicle/last-state', [
            'live'      => $live,
            'startDate' => null,
            'endDate'   => null,
            'vehicles'  => $vehicleIds,
        ]) ?? [];
    }

    /**
     * POST /api/v2/vehicle/get-fuel-data
     *
     * @param  int        $vehicleId  DSCO vehicle ID
     * @param  float|null $startDate  Unix timestamp (null = API default)
     * @param  float|null $endDate    Unix timestamp (null = now)
     */
    public function getVehicleFuelData(int $vehicleId, ?float $startDate = null, ?float $endDate = null): ?array
    {
        return $this->post('api/v2/vehicle/get-fuel-data', [
            'vehicleId' => $vehicleId,
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ]);
    }

    /**
     * POST /api/v2/vehicle/get-speed-data
     *
     * @param  int        $vehicleId
     * @param  float|null $startDate
     * @param  float|null $endDate
     */
    public function getVehicleSpeedData(int $vehicleId, ?float $startDate = null, ?float $endDate = null): ?array
    {
        return $this->post('api/v2/vehicle/get-speed-data', [
            'vehicleId' => $vehicleId,
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ]);
    }

    /**
     * POST /api/v2/vehicle/get-weight-data
     *
     * @param  int        $vehicleId
     * @param  float|null $startDate
     * @param  float|null $endDate
     */
    public function getVehicleWeightData(int $vehicleId, ?float $startDate = null, ?float $endDate = null): ?array
    {
        return $this->post('api/v2/vehicle/get-weight-data', [
            'vehicleId' => $vehicleId,
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ]);
    }

    /**
     * POST /api/v2/vehicle/trips
     *
     * @param  int        $vehicleId
     * @param  float      $startDate  Unix timestamp — required by DSCO
     * @param  float|null $endDate
     */
    public function getVehicleTrips(int $vehicleId, float $startDate, ?float $endDate = null): array
    {
        return $this->post('api/v2/vehicle/trips', [
            'vehicleId' => $vehicleId,
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ]) ?? [];
    }

    // -------------------------------------------------------------------------
    // Drivers
    // -------------------------------------------------------------------------

    /**
     * POST /api/v2/driver/list-info
     * Full driver list with uuid, contact, license status, access key.
     */
    public function getDriverListInfo(): array
    {
        return $this->post('api/v2/driver/list-info') ?? [];
    }

    /**
     * POST /api/v2/driver/trips
     *
     * @param  int        $driverId
     * @param  float      $startDate
     * @param  float|null $endDate
     */
    public function getDriverTrips(int $driverId, float $startDate, ?float $endDate = null): array
    {
        return $this->post('api/v2/driver/trips', [
            'driverId'  => $driverId,
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ]) ?? [];
    }

    // -------------------------------------------------------------------------
    // Legacy / Other (kept for future use)
    // -------------------------------------------------------------------------

    /** POST /api/v2/vehicle/states  (bulk filter-based state query) */
    public function getVehicleStates(array $filter = []): array
    {
        return $this->post('api/v2/vehicle/states', $filter) ?? [];
    }

    /** POST /api/v2/trip/path */
    public function getTripPath(int $tripId): array
    {
        return $this->post('api/v2/trip/path', ['tripId' => $tripId]) ?? [];
    }

    /** POST /api/v2/event/list */
    public function getEvents(array $filter): array
    {
        return $this->post('api/v2/event/list', $filter) ?? [];
    }

    /** GET /api/v2/maintenance/tasks */
    public function getMaintenanceTasks(): array
    {
        return $this->get('api/v2/maintenance/tasks') ?? [];
    }
}
