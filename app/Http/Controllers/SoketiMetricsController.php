<?php

namespace App\Http\Controllers;

use App\Services\UploadMetricsTracker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class SoketiMetricsController extends Controller
{
    private UploadMetricsTracker $uploadTracker;
    
    public function __construct(UploadMetricsTracker $uploadTracker)
    {
        $this->uploadTracker = $uploadTracker;
    }
    
    /**
     * Show the Soketi metrics page
     */
    public function page(Request $request): Response
    {
        $app = $request->route('app');
        
        return Inertia::render('SoketiMetrics', [
            'app' => $app,
            'config' => [
                'realtime_refresh_interval' => config('soketi.realtime_refresh_interval', 5000),
                'soketi_endpoint' => config('soketi.host', 'soketi:6001'),
            ]
        ]);
    }
    
    /**
     * Get cached metrics from scraper
     */
    public function getCachedMetrics(Request $request): JsonResponse
    {
        // Get the enhanced metrics that include both Soketi and upload data
        $metrics = Cache::get('soketi:enhanced_metrics', []);
        
        if (empty($metrics)) {
            // Fallback to processed metrics if enhanced not available
            $metrics = Cache::get('soketi:processed_metrics', []);
        }
        
        // Add scraper status
        $metrics['scraper_status'] = [
            'scraper_working' => !empty($metrics),
            'is_stale' => $this->isDataStale($metrics),
            'last_scraped' => $metrics['scraped_at'] ?? null
        ];
        
        return response()->json($metrics);
    }
    
    /**
     * Get time series data for charts
     */
    public function getTimeSeriesData(Request $request): JsonResponse
    {
        $hours = $request->input('hours', 24);
        $granularity = $request->input('granularity', 'hour'); // minute, hour
        
        $data = [];
        
        if ($granularity === 'hour') {
            // Get hourly data
            $startTime = \Carbon\Carbon::now()->subHours($hours)->startOfHour();
            
            for ($i = 0; $i < $hours; $i++) {
                $hour = $startTime->copy()->addHours($i);
                $hourKey = $hour->format('Y-m-d-H');
                
                $cached = Cache::get("soketi:timeseries:hour:{$hourKey}", []);
                
                $data[] = [
                    'timestamp' => $hour->timestamp,
                    'connections' => $cached['connections_sum'] ?? 0,
                    'bytes_transferred' => $cached['bytes_sum'] ?? 0,
                    'upload_events' => $this->getHourlyUploadEvents($hour)
                ];
            }
        } else {
            // Get minute data (last hour only for performance)
            $startTime = \Carbon\Carbon::now()->subHour()->startOfMinute();
            
            for ($i = 0; $i < 60; $i++) {
                $minute = $startTime->copy()->addMinutes($i);
                $minuteKey = $minute->format('Y-m-d-H-i');
                
                $cached = Cache::get("soketi:timeseries:minute:{$minuteKey}", []);
                
                $data[] = [
                    'timestamp' => $minute->timestamp,
                    'connections' => $cached['connections'] ?? 0,
                    'bytes_transferred' => $cached['bytes_transferred'] ?? 0
                ];
            }
        }
        
        return response()->json([
            'success' => true,
            'data' => $data,
            'granularity' => $granularity,
            'period_hours' => $hours
        ]);
    }
    
    /**
     * Get Soketi server health status
     */
    public function getSoketiHealth(Request $request): JsonResponse
    {
        $soketiHost = config('soketi.host', 'soketi');
        $metricsPort = config('soketi.metrics_port', 9601);
        
        $health = [
            'overall_healthy' => false,
            'websocket_api' => null,
            'metrics_api' => null,
            'checked_at' => now()->toISOString()
        ];
        
        try {
            // Check WebSocket API (port 6001)
            $wsResponse = Http::timeout(5)->get("http://{$soketiHost}:6001");
            $health['websocket_api'] = [
                'healthy' => $wsResponse->successful(),
                'status_code' => $wsResponse->status(),
                'response_time_ms' => 0 // Would need to measure this
            ];
        } catch (\Exception $e) {
            $health['websocket_api'] = [
                'healthy' => false,
                'error' => $e->getMessage()
            ];
        }
        
        try {
            // Check Metrics API (port 9601)
            $metricsResponse = Http::timeout(5)->get("http://{$soketiHost}:{$metricsPort}/metrics");
            $health['metrics_api'] = [
                'healthy' => $metricsResponse->successful(),
                'status_code' => $metricsResponse->status(),
                'response_time_ms' => 0 // Would need to measure this
            ];
        } catch (\Exception $e) {
            $health['metrics_api'] = [
                'healthy' => false,
                'error' => $e->getMessage()
            ];
        }
        
        // Overall health check
        $health['overall_healthy'] = 
            ($health['websocket_api']['healthy'] ?? false) && 
            ($health['metrics_api']['healthy'] ?? false);
        
        return response()->json($health);
    }
    
    /**
     * Manually refresh metrics
     */
    public function refreshMetrics(Request $request): JsonResponse
    {
        try {
            // Clear caches to force refresh
            Cache::forget('soketi:processed_metrics');
            Cache::forget('soketi:enhanced_metrics');
            
            // Trigger metrics scraping
            \Artisan::call('soketi:scrape-metrics');
            
            return response()->json([
                'success' => true,
                'message' => 'Metrics refresh triggered'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh metrics: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Check if data is stale
     */
    private function isDataStale(array $metrics): bool
    {
        if (empty($metrics['scraped_at'])) {
            return true;
        }
        
        $scrapedAt = \Carbon\Carbon::parse($metrics['scraped_at']);
        $staleThreshold = \Carbon\Carbon::now()->subMinutes(2); // Consider stale after 2 minutes
        
        return $scrapedAt->lt($staleThreshold);
    }
    
    /**
     * Get hourly upload events for time series
     */
    private function getHourlyUploadEvents(\Carbon\Carbon $hour): array
    {
        $hourData = \DB::table('upload_metrics_hourly')
            ->where('hour', $hour->startOfHour())
            ->first();
        
        if (!$hourData) {
            return [
                'prepared' => 0,
                'completed' => 0,
                'failed' => 0
            ];
        }
        
        return [
            'prepared' => $hourData->total_uploads ?? 0,
            'completed' => $hourData->completed_uploads ?? 0,
            'failed' => $hourData->failed_uploads ?? 0
        ];
    }
}