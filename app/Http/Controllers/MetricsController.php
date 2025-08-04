<?php

namespace App\Http\Controllers;

use App\Models\App as SoketiApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Config;

// Bring in global helpers for static analysis (Laravel provides them at runtime)
use function abort;
use function response;

class MetricsController extends Controller
{
    private string $baseUrl;

    public function __construct()
    {
        // Prefer config('services.prometheus.url') if present; fallback to env('UI_PROMETHEUS_URL')
        $cfgUrl = (string) (Config::get('services.prometheus.url') ?? '');
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

        $this->guardQuery($query);

        $params = ['query' => $query];
        if ($time) {
            $params['time'] = $time;
        }

        $resp = Http::timeout(5)->get("{$this->baseUrl}/api/v1/query", $params);

        return response()->json($resp->json(), $resp->status());
    }

    // Range query for time series
    public function queryRange(Request $request, SoketiApp $app)
    {
        $query = $request->query('query');
        $start = $request->query('start');
        $end   = $request->query('end');
        $step  = $request->query('step', '30s');

        $this->guardQuery($query);

        $params = array_filter([
            'query' => $query,
            'start' => $start,
            'end'   => $end,
            'step'  => $step,
        ], fn ($v) => $v !== null && $v !== '');

        $resp = Http::timeout(10)->get("{$this->baseUrl}/api/v1/query_range", $params);

        return response()->json($resp->json(), $resp->status());
    }

    // Simple allowlist to avoid arbitrary PromQL
    private function guardQuery(?string $query): void
    {
        if (!$this->baseUrl) {
            abort(503, 'Prometheus URL not configured');
        }
        if (!$query) {
            abort(400, 'Missing query');
        }

        $allow = [
            'soketi_6001_connected',
            'increase(soketi_6001_new_connections_total[5m])',
            'increase(soketi_6001_new_disconnections_total[5m])',
            'rate(soketi_6001_socket_received_bytes[5m])',
            'rate(soketi_6001_socket_sent_bytes[5m])',
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