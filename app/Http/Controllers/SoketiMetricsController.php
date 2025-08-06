<?php

namespace App\Http\Controllers;

use App\Models\App as SoketiApp;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class SoketiMetricsController extends Controller
{
    private int $defaultCacheTtl = 600; // 10 minutes cache for scraped metrics
    private string $soketiHost;
    private int $metricsPort;

    public function __construct()
    {
        $host = env('SOKETI_HOST', 'soketi');
        // Add http:// prefix if not present
        $this->soketiHost = str_starts_with($host, 'http') ? $host : "http://{$host}";
        $this->metricsPort = env('SOKETI_METRICS_PORT', 9601);
    }

    /**
     * Render Soketi Metrics page for an app
     */
    public function page(Request $request, SoketiApp $app)
    {
        return Inertia::render('SoketiMetrics', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name ?? (string) $app->id,
            ],
            'config' => [
                'realtime_refresh_interval' => 5000, // 5 seconds for real-time updates
                'scraper_based' => true, // Indicate this is scraper-based metrics
                'soketi_endpoint' => $this->soketiHost . ':' . $this->metricsPort,
            ],
        ]);
    }

    /**
     * Get cached metrics from Soketi scraper
     */
    public function getCachedMetrics(Request $request, SoketiApp $app)
    {
        // Get enhanced metrics from scraper job
        $enhancedMetrics = Cache::get('soketi:enhanced_metrics');
        
        if (!$enhancedMetrics) {
            // Fallback to basic processed metrics if enhanced not available
            $enhancedMetrics = Cache::get('soketi:processed_metrics', []);
        }
        
        // Check if metrics are fresh (less than 30 seconds old)
        $scrapedAt = $enhancedMetrics['scraped_timestamp'] ?? 0;
        $isStale = (time() - $scrapedAt) > 30;
        
        if ($isStale) {
            Log::warning('Soketi metrics are stale', [
                'scraped_at' => $scrapedAt,
                'age_seconds' => time() - $scrapedAt
            ]);
        }
        
        // Get real-time metrics (backwards compatible format)
        $realtimeMetrics = Cache::get('soketi:realtime_metrics', $this->getDefaultMetrics());
        
        // Add scraper status info
        $realtimeMetrics['scraper_status'] = [
            'is_stale' => $isStale,
            'last_scraped' => $enhancedMetrics['scraped_at'] ?? null,
            'scraper_working' => !$isStale,
        ];
        
        // Add Soketi server health
        $realtimeMetrics['soketi_health'] = $this->checkSoketiHealth();
        
        return Response::json($realtimeMetrics);
    }

    /**
     * Get time series data for charts (from scraped data)
     */
    public function getTimeSeriesData(Request $request, SoketiApp $app)
    {
        $metric = $request->query('metric', 'connections');
        $timeRange = $request->query('range', '1h'); // 1h, 6h, 24h
        
        $data = [];
        
        switch ($timeRange) {
            case '1h':
                $data = $this->getHourlyTimeSeriesData($metric);
                break;
            case '6h':
                $data = $this->get6HourTimeSeriesData($metric);
                break;
            case '24h':
                $data = $this->getDailyTimeSeriesData($metric);
                break;
        }
        
        return Response::json([
            'metric' => $metric,
            'range' => $timeRange,
            'data' => $data,
            'timestamp' => Carbon::now()->toISOString()
        ]);
    }
    
    /**
     * Get Soketi server health status
     */
    public function getSoketiHealth(Request $request)
    {
        return Response::json($this->checkSoketiHealth());
    }
    
    /**
     * Force refresh metrics by triggering scraper manually
     */
    public function refreshMetrics(Request $request)
    {
        try {
            // Run the scraper command synchronously for immediate results
            $exitCode = \Artisan::call('soketi:scrape-metrics');
            
            if ($exitCode === 0) {
                return Response::json([
                    'success' => true,
                    'message' => 'Metrics refreshed successfully',
                    'timestamp' => Carbon::now()->toISOString()
                ]);
            } else {
                return Response::json([
                    'success' => false,
                    'message' => 'Metrics refresh failed',
                    'exit_code' => $exitCode
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Manual metrics refresh failed', [
                'error' => $e->getMessage()
            ]);
            
            return Response::json([
                'success' => false,
                'message' => 'Metrics refresh error: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Check Soketi server health
     */
    private function checkSoketiHealth(): array
    {
        try {
            // Check main WebSocket port
            $wsHealth = $this->checkEndpointHealth($this->soketiHost . ':6001', 'WebSocket API');
            
            // Check metrics port
            $metricsHealth = $this->checkEndpointHealth($this->soketiHost . ':' . $this->metricsPort, 'Metrics API');
            
            $overallHealth = $wsHealth['healthy'] && $metricsHealth['healthy'];
            
            return [
                'overall_healthy' => $overallHealth,
                'websocket_api' => $wsHealth,
                'metrics_api' => $metricsHealth,
                'checked_at' => Carbon::now()->toISOString()
            ];
            
        } catch (\Exception $e) {
            return [
                'overall_healthy' => false,
                'error' => $e->getMessage(),
                'checked_at' => Carbon::now()->toISOString()
            ];
        }
    }
    
    /**
     * Check if a specific endpoint is healthy
     */
    private function checkEndpointHealth(string $url, string $name): array
    {
        try {
            $startTime = microtime(true);
            $response = Http::timeout(5)->get($url);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            return [
                'name' => $name,
                'healthy' => $response->successful(),
                'status_code' => $response->status(),
                'response_time_ms' => $responseTime,
                'url' => $url
            ];
            
        } catch (\Exception $e) {
            return [
                'name' => $name,
                'healthy' => false,
                'error' => $e->getMessage(),
                'url' => $url
            ];
        }
    }
    
    /**
     * Get default metrics structure when no data is available
     */
    private function getDefaultMetrics(): array
    {
        return [
            'current_connections' => 0,
            'total_members' => 0,
            'bytes_transferred' => 0,
            'connection_events' => [
                'last_hour' => 0,
                'last_minute' => 0,
            ],
            'disconnection_events' => [
                'last_hour' => 0,
                'last_minute' => 0,
            ],
            'client_events' => [
                'chunked-upload.prepared' => 0,
                'chunked-upload.completed' => 0,
                'chunked-upload.failed' => 0,
                'other' => 0,
            ],
            'upload_metrics' => [
                'active_uploads' => 0,
                'total_prepared' => 0,
                'total_completed' => 0,
                'total_failed' => 0,
                'completion_rate' => 0,
                'average_duration_seconds' => 0,
                'duration_buckets' => [
                    '0-10s' => 0,
                    '10-30s' => 0,
                    '30-60s' => 0,
                    '1-2m' => 0,
                    '2-5m' => 0,
                    '5m+' => 0,
                ],
                'events' => [
                    'prepared_last_hour' => 0,
                    'completed_last_hour' => 0,
                    'failed_last_hour' => 0,
                ],
            ],
            'channels' => [
                'total_occupied' => 0,
            ],
            'last_updated' => null,
        ];
    }
    
    /**
     * Get hourly time series data from cache
     */
    private function getHourlyTimeSeriesData(string $metric): array
    {
        $data = [];
        $now = Carbon::now();
        
        // Get last 60 minutes of data
        for ($i = 59; $i >= 0; $i--) {
            $time = $now->copy()->subMinutes($i);
            $key = "soketi:timeseries:minute:" . $time->format('Y-m-d-H-i');
            $cachedData = Cache::get($key, []);
            
            $value = $this->extractMetricValue($cachedData, $metric);
            
            $data[] = [
                'timestamp' => $time->timestamp,
                'value' => $value
            ];
        }
        
        return $data;
    }
    
    /**
     * Get 6-hour time series data from cache
     */
    private function get6HourTimeSeriesData(string $metric): array
    {
        $data = [];
        $now = Carbon::now();
        
        // Get last 6 hours of data (hourly buckets)
        for ($i = 5; $i >= 0; $i--) {
            $time = $now->copy()->subHours($i);
            $key = "soketi:timeseries:hour:" . $time->format('Y-m-d-H');
            $cachedData = Cache::get($key, []);
            
            $value = $this->extractMetricValue($cachedData, $metric);
            
            $data[] = [
                'timestamp' => $time->timestamp,
                'value' => $value
            ];
        }
        
        return $data;
    }
    
    /**
     * Get 24-hour time series data from cache
     */
    private function getDailyTimeSeriesData(string $metric): array
    {
        $data = [];
        $now = Carbon::now();
        
        // Get last 24 hours of data (hourly buckets)
        for ($i = 23; $i >= 0; $i--) {
            $time = $now->copy()->subHours($i);
            $key = "soketi:timeseries:hour:" . $time->format('Y-m-d-H');
            $cachedData = Cache::get($key, []);
            
            $value = $this->extractMetricValue($cachedData, $metric);
            
            $data[] = [
                'timestamp' => $time->timestamp,
                'value' => $value
            ];
        }
        
        return $data;
    }
    
    /**
     * Extract specific metric value from cached time series data
     */
    private function extractMetricValue(array $data, string $metric): float
    {
        switch ($metric) {
            case 'connections':
                return $data['connections'] ?? 0;
            case 'disconnections':
                return $data['disconnections'] ?? 0;
            case 'bytes_transferred':
                return $data['bytes_transferred'] ?? 0;
            default:
                return 0;
        }
    }
}