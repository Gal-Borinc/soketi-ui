<?php

namespace App\Http\Controllers;

use App\Models\App as SoketiApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Config;

// Use helpers and facades explicitly to satisfy static analysis and ensure availability
use Illuminate\Support\Facades\Response as FacadeResponse;
use Illuminate\Support\Facades\Config as FacadeConfig;
use Illuminate\Support\Facades\Http as FacadeHttp;
use Illuminate\Support\Facades\Auth;
use function abort;
use function response;
use function request;

class MetricsController extends Controller
{
    private string $baseUrl;

    public function __construct()
    {
        // Prefer config('services.prometheus.url') if present; fallback to env('UI_PROMETHEUS_URL')
        $cfgUrl = (string) (FacadeConfig::get('services.prometheus.url') ?? '');
        $envUrl = (string) (getenv('UI_PROMETHEUS_URL') ?: '');
        $this->baseUrl = rtrim($cfgUrl !== '' ? $cfgUrl : $envUrl, '/');
    }

    // Render Metrics page for an app
    public function page(Request $request, SoketiApp $app)
    {
        return Inertia::render('Metrics', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name ?? (string) $app->id,
            ],
        ]);
    }

    // Instant vector/scalar query
    public function query(Request $request, SoketiApp $app)
    {
        $query = $request->query('query');
        $time  = $request->query('time');

        $this->guardQuery($request, $query);

        $params = ['query' => $query];
        if ($time) {
            $params['time'] = $time;
        }

        $resp = FacadeHttp::timeout(5)->get("{$this->baseUrl}/api/v1/query", $params);

        return Response::json($resp->json(), $resp->status());
    }

    // Range query for time series
    public function queryRange(Request $request, SoketiApp $app)
    {
        $query = $request->query('query');
        $start = $request->query('start');
        $end   = $request->query('end');
        $step  = $request->query('step', '30s');

        $this->guardQuery($request, $query);

        $params = array_filter([
            'query' => $query,
            'start' => $start,
            'end'   => $end,
            'step'  => $step,
        ], fn ($v) => $v !== null && $v !== '');

        $resp = FacadeHttp::timeout(10)->get("{$this->baseUrl}/api/v1/query_range", $params);

        return Response::json($resp->json(), $resp->status());
    }

    // Simple allowlist to avoid arbitrary PromQL
    private function guardQuery(Request $request, ?string $query): void
    {
        if ($this->baseUrl === '') {
            abort(503, 'Prometheus URL not configured');
        }

        // Enforce auth (routes already behind auth; this is defense in depth)
        if (!Auth::check()) {
            abort(403, 'Forbidden');
        }

        // Only allow GET
        if (strtoupper($request->getMethod()) !== 'GET') {
            abort(405, 'Method Not Allowed');
        }

        if ($query === null || $query === '') {
            abort(400, 'Missing query');
        }

        // Updated allowlist to match actual Soketi metric names observed
        $allow = [
            'soketi_connected',
            'increase(soketi_new_connections_total[5m])',
            'increase(soketi_new_disconnections_total[5m])',
            'rate(soketi_socket_received_bytes[5m])',
            'rate(soketi_socket_transmitted_bytes[5m])',
        ];

        $normalized = preg_replace('/\s+/', '', $query);
        foreach ($allow as $pattern) {
            if ($normalized === preg_replace('/\s+/', '', $pattern)) {
                return;
            }
        }

        abort(403, 'Query not allowed');
    }
}