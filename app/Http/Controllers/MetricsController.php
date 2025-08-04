<?php

namespace App\Http\Controllers;

use App\Models\App as SoketiApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use function abort;

class MetricsController extends Controller
{
    private string $baseUrl;
    private int $defaultTimeout = 10; // seconds
    private int $cacheTimeout = 30; // seconds for query caching
    
    // Define allowed metric patterns for better maintainability
    private array $allowedMetricPatterns = [
        // Current connections (instant value)
        'instant' => [
            'soketi_connected',
            'soketi:total_connected', // aggregated version from recording rules
        ],
        // Real-time rate metrics using irate() for fast-changing metrics
        'rate_realtime' => [
            'irate(soketi_new_connections_total[1m])',
            'irate(soketi_new_disconnections_total[1m])',
            'irate(soketi_socket_received_bytes[1m])',
            'irate(soketi_socket_transmitted_bytes[1m])',
            'irate(soketi_socket_sent_bytes[1m])', // compatibility
            // Pre-calculated recording rules (better performance)
            'soketi:new_connections_rate_1m',
            'soketi:new_disconnections_rate_1m',
            'soketi:rx_bytes_rate_1m',
            'soketi:tx_bytes_rate_1m',
            'soketi:tx_bytes_rate_1m_alt',
        ],
        // Rate-based metrics for trending (using longer windows for stability)
        'rate_trending' => [
            'rate(soketi_new_connections_total[5m])',
            'rate(soketi_new_disconnections_total[5m])',
            'rate(soketi_socket_received_bytes[5m])',
            'rate(soketi_socket_transmitted_bytes[5m])',
            'rate(soketi_socket_sent_bytes[5m])', // compatibility
            // Pre-calculated recording rules
            'soketi:new_connections_rate_5m',
            'soketi:new_disconnections_rate_5m',
            'soketi:rx_bytes_rate_5m',
            'soketi:tx_bytes_rate_5m',
        ],
        // Increase-based metrics for counters
        'increase' => [
            'increase(soketi_new_connections_total[1m])',
            'increase(soketi_new_connections_total[5m])',
            'increase(soketi_new_disconnections_total[1m])',
            'increase(soketi_new_disconnections_total[5m])',
            // Pre-calculated recording rules
            'soketi:new_connections_increase_5m',
            'soketi:new_disconnections_increase_5m',
        ],
        // Combined/calculated metrics
        'calculated' => [
            'irate(soketi_new_connections_total[1m]) - irate(soketi_new_disconnections_total[1m])',
            'irate(soketi_new_connections_total[1m]) + irate(soketi_new_disconnections_total[1m])',
            'rate(soketi_new_connections_total[5m]) - rate(soketi_new_disconnections_total[5m])',
            'rate(soketi_new_connections_total[5m]) + rate(soketi_new_disconnections_total[5m])',
        ],
        // Aggregated and health metrics
        'aggregated' => [
            'sum(soketi_connected)',
            'avg(soketi_connected)',
            'sum(rate(soketi_new_connections_total[5m]))',
            'sum(rate(soketi_new_disconnections_total[5m]))',
            // Pre-calculated recording rules
            'soketi:total_connected',
            'soketi:avg_connection_rate_5m',
            'soketi:total_rx_rate_5m',
            'soketi:total_tx_rate_5m',
            'soketi:connection_churn_rate_1m',
            'soketi:net_connection_rate_1m',
            'soketi:total_bandwidth_rate_1m',
        ]
    ];

    public function __construct()
    {
        // Get configuration from services config with fallbacks
        $prometheusConfig = Config::get('services.prometheus', []);
        
        $this->baseUrl = rtrim($prometheusConfig['url'] ?? getenv('UI_PROMETHEUS_URL') ?: '', '/');
        $this->defaultTimeout = $prometheusConfig['timeout'] ?? 10;
        $this->cacheTimeout = $prometheusConfig['cache_ttl'] ?? 30;
    }

    // Render Metrics page for an app
    public function page(Request $request, SoketiApp $app)
    {
        return Inertia::render('Metrics', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name ?? (string) $app->id,
            ],
            'config' => [
                'realtime_refresh_interval' => 5000, // 5 seconds for real-time updates
                'trending_refresh_interval' => 30000, // 30 seconds for trending data
                'query_timeout' => $this->defaultTimeout * 1000, // milliseconds
            ],
        ]);
    }

    // Instant vector/scalar query with enhanced error handling and caching
    public function query(Request $request, SoketiApp $app)
    {
        $query = $request->query('query');
        $time = $request->query('time');

        $this->guardQuery($request, $query);

        // Create cache key for query result
        $cacheKey = "metrics:query:" . md5($query . ($time ?? 'now'));
        
        // Try to get from cache first
        if ($cached = Cache::get($cacheKey)) {
            return Response::json($cached['data'], $cached['status']);
        }

        $params = ['query' => $query];
        if ($time) {
            $params['time'] = $time;
        }

        try {
            $resp = Http::timeout($this->defaultTimeout)
                ->retry(2, 1000) // Retry 2 times with 1 second delay
                ->get("{$this->baseUrl}/api/v1/query", $params);

            $responseData = $resp->json();
            $status = $resp->status();

            // Cache successful responses for a short time
            if ($status === 200 && isset($responseData['status']) && $responseData['status'] === 'success') {
                Cache::put($cacheKey, [
                    'data' => $responseData,
                    'status' => $status
                ], $this->cacheTimeout);
            }

            return Response::json($responseData, $status);
        } catch (\Exception $e) {
            Log::error('Prometheus query failed', [
                'query' => $query,
                'error' => $e->getMessage(),
                'app_id' => $app->id,
            ]);

            return Response::json([
                'status' => 'error',
                'error' => 'Query execution failed',
                'errorType' => 'timeout',
            ], 503);
        }
    }

    // Range query for time series with enhanced performance
    public function queryRange(Request $request, SoketiApp $app)
    {
        $query = $request->query('query');
        $start = $request->query('start');
        $end = $request->query('end');
        $step = $request->query('step', '30s');

        $this->guardQuery($request, $query);

        // Create cache key for range query
        $cacheKey = "metrics:range:" . md5($query . $start . $end . $step);
        
        // Try to get from cache first (shorter cache for range queries)
        if ($cached = Cache::get($cacheKey)) {
            return Response::json($cached['data'], $cached['status']);
        }

        $params = array_filter([
            'query' => $query,
            'start' => $start,
            'end' => $end,
            'step' => $step,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $resp = Http::timeout($this->defaultTimeout + 5) // Longer timeout for range queries
                ->retry(2, 1000)
                ->get("{$this->baseUrl}/api/v1/query_range", $params);

            $responseData = $resp->json();
            $status = $resp->status();

            // Cache successful responses for a shorter time (range queries change more frequently)
            if ($status === 200 && isset($responseData['status']) && $responseData['status'] === 'success') {
                Cache::put($cacheKey, [
                    'data' => $responseData,
                    'status' => $status
                ], min($this->cacheTimeout, 15)); // Max 15 seconds cache for range queries
            }

            return Response::json($responseData, $status);
        } catch (\Exception $e) {
            Log::error('Prometheus range query failed', [
                'query' => $query,
                'start' => $start,
                'end' => $end,
                'step' => $step,
                'error' => $e->getMessage(),
                'app_id' => $app->id,
            ]);

            return Response::json([
                'status' => 'error',
                'error' => 'Range query execution failed',
                'errorType' => 'timeout',
            ], 503);
        }
    }

    // Enhanced query validation with better maintainability and security
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

        // Validate query against allowed patterns
        if (!$this->isQueryAllowed($query)) {
            Log::warning('Unauthorized Prometheus query attempt', [
                'query' => $query,
                'user_id' => Auth::id(),
                'ip' => $request->ip(),
            ]);
            abort(403, 'Query not allowed');
        }
    }

    // Check if a query matches any of the allowed patterns
    private function isQueryAllowed(string $query): bool
    {
        $normalizedQuery = $this->normalizeQuery($query);
        
        foreach ($this->allowedMetricPatterns as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if ($normalizedQuery === $this->normalizeQuery($pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    // Normalize query by removing whitespace and standardizing format
    private function normalizeQuery(string $query): string
    {
        return preg_replace('/\s+/', '', strtolower($query));
    }

    // Get available metrics for the frontend (optional endpoint for dynamic UI)
    public function getAvailableMetrics()
    {
        return Response::json([
            'realtime' => [
                'connections' => [
                    'current' => 'soketi_connected',
                    'current_total' => 'soketi:total_connected', // pre-calculated
                    'new_rate' => 'soketi:new_connections_rate_1m', // pre-calculated (preferred)
                    'new_rate_raw' => 'irate(soketi_new_connections_total[1m])', // raw query
                    'disconnect_rate' => 'soketi:new_disconnections_rate_1m',
                    'disconnect_rate_raw' => 'irate(soketi_new_disconnections_total[1m])',
                    'net_rate' => 'soketi:net_connection_rate_1m', // pre-calculated health metric
                    'churn_rate' => 'soketi:connection_churn_rate_1m', // pre-calculated health metric
                ],
                'bandwidth' => [
                    'rx_rate' => 'soketi:rx_bytes_rate_1m', // pre-calculated (preferred)
                    'rx_rate_raw' => 'irate(soketi_socket_received_bytes[1m])',
                    'tx_rate' => 'soketi:tx_bytes_rate_1m',
                    'tx_rate_raw' => 'irate(soketi_socket_transmitted_bytes[1m])',
                    'total_bandwidth' => 'soketi:total_bandwidth_rate_1m', // pre-calculated
                ],
            ],
            'trending' => [
                'connections' => [
                    'new_rate' => 'soketi:new_connections_rate_5m', // pre-calculated (preferred)
                    'new_rate_raw' => 'rate(soketi_new_connections_total[5m])',
                    'disconnect_rate' => 'soketi:new_disconnections_rate_5m',
                    'disconnect_rate_raw' => 'rate(soketi_new_disconnections_total[5m])',
                    'new_increase' => 'soketi:new_connections_increase_5m', // pre-calculated
                    'new_increase_raw' => 'increase(soketi_new_connections_total[5m])',
                    'disconnect_increase' => 'soketi:new_disconnections_increase_5m',
                    'disconnect_increase_raw' => 'increase(soketi_new_disconnections_total[5m])',
                    'avg_rate' => 'soketi:avg_connection_rate_5m', // pre-calculated aggregate
                ],
                'bandwidth' => [
                    'rx_rate' => 'soketi:rx_bytes_rate_5m', // pre-calculated (preferred)
                    'rx_rate_raw' => 'rate(soketi_socket_received_bytes[5m])',
                    'tx_rate' => 'soketi:tx_bytes_rate_5m',
                    'tx_rate_raw' => 'rate(soketi_socket_transmitted_bytes[5m])',
                    'total_rx' => 'soketi:total_rx_rate_5m', // pre-calculated aggregate
                    'total_tx' => 'soketi:total_tx_rate_5m',
                ],
            ],
            'performance_tips' => [
                'use_recording_rules' => 'Metrics with "soketi:" prefix are pre-calculated and faster',
                'realtime_refresh' => '5s recommended for real-time metrics',
                'trending_refresh' => '30s recommended for trending metrics',
                'prefer_irate' => 'Use irate() for fast-changing real-time metrics',
                'prefer_rate' => 'Use rate() for stable trending analysis',
            ]
        ]);
    }
}