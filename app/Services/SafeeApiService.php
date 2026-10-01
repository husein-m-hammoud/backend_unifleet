<?php

namespace App\Services;

use App\Models\ProviderApiFailure;
use Illuminate\Support\Facades\Http;

/**
 * Client for the Safee Tracking REST Service (api/v2/*), scoped to one provider.
 *
 *   $api = new SafeeApiService('alrakeen');   // or null => config default
 *
 * Transport, response wrapper ({code,time,status,message,result}) and auth are
 * identical to the old DscoApiService. The request *shapes* below were corrected
 * against Safee v2.2.0.0 (verified live on Alrakeen 2026-07):
 *   - get-fuel/speed/weight-data take {id}, NOT {vehicleId,startDate,endDate}
 *     (the old shape returns HTTP 404 "Vehicle id is missing.")
 *   - vehicle/trips & driver/trips take {pageSize,pageIndex,filter}
 *   - driver list/get endpoints return under the `drivers`/`driver` key, not `result`
 *   - trip path is api/v2/vehicle/trip/path {id}; events are api/v2/vehicle/events
 */
class SafeeApiService
{
    private SafeeAuthService $auth;
    private string $baseUrl;

    public function __construct(SafeeAuthService|string|null $provider = null)
    {
        $this->auth    = $provider instanceof SafeeAuthService
            ? $provider
            : new SafeeAuthService($provider);
        $this->baseUrl = $this->auth->baseUrl();
    }

    public function provider(): string
    {
        return $this->auth->provider();
    }

    // -------------------------------------------------------------------------
    // Transport
    // -------------------------------------------------------------------------

    /**
     * @param string $resultKey Which top-level key holds the payload. Most
     *                          endpoints use "result"; driver endpoints return
     *                          "drivers"/"driver".
     */
    private function post(string $endpoint, array $body = [], string $resultKey = 'result', int $timeoutSeconds = 30): mixed
    {
        return $this->request('post', $endpoint, $body, $timeoutSeconds)->json($resultKey);
    }

    private function get(string $endpoint, array $query = [], string $resultKey = 'result'): mixed
    {
        return $this->request('get', $endpoint, $query)->json($resultKey);
    }

    private function request(string $method, string $endpoint, array $data = [], int $timeoutSeconds = 30)
    {
        $send = fn (string $token) => $this->dispatch($method, $endpoint, $data, $token, $timeoutSeconds);

        try {
            $response = $send($this->auth->getToken());

            // Token expired — clear cache and retry once with a fresh token.
            if ($response->status() === 401) {
                $this->auth->forgetToken();
                $response = $send($this->auth->getToken());
            }
        } catch (\Throwable $e) {
            // Connect timeout / DNS / TLS, or an auth failure thrown from
            // getToken(). Recorded so the diagnostics page can show when this
            // provider went dark; auth failures are logged by SafeeAuthService
            // itself, so only tag transport failures here.
            if (! $e instanceof \Illuminate\Http\Client\RequestException) {
                ProviderApiFailure::record($this->auth->provider(), 'request', $endpoint, null, $e->getMessage());
            }
            throw $e;
        }

        if ($response->failed()) {
            ProviderApiFailure::record(
                $this->auth->provider(),
                'request',
                $endpoint,
                $response->status(),
                $response->body(),
            );
        }

        $response->throw();

        ProviderApiFailure::markSuccess($this->auth->provider());

        return $response;
    }

    private function dispatch(string $method, string $endpoint, array $data, string $token, int $timeoutSeconds)
    {
        $http = Http::asJson()
            ->connectTimeout(10)   // fail fast on an unreachable provider (cURL default is ~300s)
            ->timeout($timeoutSeconds)
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept'        => 'application/json',
            ]);

        // Empty PHP [] encodes to "[]"; Safee's JSON parser expects "{}".
        $body = empty($data) ? new \stdClass() : $data;
        $url  = "{$this->baseUrl}/{$endpoint}";

        return $method === 'get'
            ? $http->get($url, $data)
            : $http->post($url, $body);
    }

    // -------------------------------------------------------------------------
    // Utility / Reference
    // -------------------------------------------------------------------------

    /** GET api/v2/status — integration health check. */
    public function status(): mixed
    {
        return $this->get('api/v2/status');
    }

    /** POST api/v2/site/list */
    public function getSites(): array
    {
        return $this->post('api/v2/site/list') ?? [];
    }

    /** POST api/v2/category/list */
    public function getCategories(): array
    {
        return $this->post('api/v2/category/list') ?? [];
    }

    /** POST api/v2/geofence/list */
    public function getGeofences(): array
    {
        return $this->post('api/v2/geofence/list') ?? [];
    }

    // -------------------------------------------------------------------------
    // Vehicles
    // -------------------------------------------------------------------------

    /** POST api/v2/vehicle/list-info — full list with device, driver, site, category embedded. */
    public function getVehicleListInfo(): array
    {
        return $this->post('api/v2/vehicle/list-info') ?? [];
    }

    /**
     * POST api/v2/vehicle/last-state — live state (position/speed/ignition) for a set of vehicles.
     * Per the spec the body is {live, endDate, vehicles} — no startDate.
     */
    public function getVehicleLastState(array $vehicleIds, bool $live = true, ?float $endDate = null): array
    {
        return $this->post('api/v2/vehicle/last-state', [
            'live'     => $live,
            'endDate'  => $endDate,
            'vehicles' => $vehicleIds,
        ]) ?? [];
    }

    /** POST api/v2/vehicle/get-fuel-data — last fuel snapshot. Body: {id}. */
    public function getVehicleFuelData(int $vehicleId): ?array
    {
        return $this->post('api/v2/vehicle/get-fuel-data', ['id' => $vehicleId]);
    }

    /**
     * POST api/v2/vehicle/get-speed-data — last/avg/max + hourly speed.
     * Body: {id, numberOfHours?} (numberOfHours optional, < 25, default 24).
     */
    public function getVehicleSpeedData(int $vehicleId, ?int $numberOfHours = null): ?array
    {
        $body = ['id' => $vehicleId];
        if ($numberOfHours !== null) {
            $body['numberOfHours'] = $numberOfHours;
        }

        return $this->post('api/v2/vehicle/get-speed-data', $body);
    }

    /** POST api/v2/vehicle/get-weight-data — last weight/load. Body: {id}. */
    public function getVehicleWeightData(int $vehicleId): ?array
    {
        return $this->post('api/v2/vehicle/get-weight-data', ['id' => $vehicleId]);
    }

    /**
     * POST api/v2/vehicle/trips — trips for a vehicle between two dates.
     * Body: {pageSize, pageIndex, filter: TripFilter}. pageSize 0 = no pagination.
     */
    public function getVehicleTrips(int $vehicleId, float $startDate, ?float $endDate = null, int $pageSize = 0, int $pageIndex = 0): array
    {
        return $this->post('api/v2/vehicle/trips', [
            'pageSize'  => $pageSize,
            'pageIndex' => $pageIndex,
            'filter'    => [
                'vehicleId' => $vehicleId,
                'startDate' => $startDate,
                'endDate'   => $endDate,
            ],
        ]) ?? [];
    }

    /**
     * POST api/v2/vehicle/positions — GPS position history for one vehicle over a date range.
     * Longer default timeout: some vehicles return large payloads.
     */
    public function getVehiclePositions(int $vehicleId, float $startDate, float $endDate, int $timeoutSeconds = 120): array
    {
        return $this->post('api/v2/vehicle/positions', [
            'vehicleId' => $vehicleId,
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ], 'result', $timeoutSeconds) ?? [];
    }

    /** POST api/v2/vehicle/events — events for a vehicle. status: READ|UNREAD|NEW|ALL. */
    public function getVehicleEvents(int $vehicleId, float $startDate, float $endDate, string $status = 'ALL'): array
    {
        return $this->post('api/v2/vehicle/events', [
            'vehicleId' => $vehicleId,
            'startDate' => $startDate,
            'endDate'   => $endDate,
            'status'    => $status,
        ]) ?? [];
    }

    /** POST api/v2/vehicle/trip/path — position points forming a trip. Body: {id}. */
    public function getTripPath(int $tripId): array
    {
        return $this->post('api/v2/vehicle/trip/path', ['id' => $tripId]) ?? [];
    }

    // -------------------------------------------------------------------------
    // Drivers  (return under the `drivers` / `driver` key, not `result`)
    // -------------------------------------------------------------------------

    /** POST api/v2/driver/list-info — full driver list (with uuid, access key, license status). */
    public function getDriverListInfo(): array
    {
        return $this->post('api/v2/driver/list-info', [], 'drivers') ?? [];
    }

    /** POST api/v2/driver/trips — trips for a driver. Body: {pageSize, pageIndex, filter: DriverTripFilter}. */
    public function getDriverTrips(int $driverId, float $startDate, ?float $endDate = null, int $pageSize = 0, int $pageIndex = 0): array
    {
        return $this->post('api/v2/driver/trips', [
            'pageSize'  => $pageSize,
            'pageIndex' => $pageIndex,
            'filter'    => [
                'driverId'  => $driverId,
                'startDate' => $startDate,
                'endDate'   => $endDate,
            ],
        ]) ?? [];
    }
}
