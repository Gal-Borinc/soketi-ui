<?php

namespace App\Http\Controllers;

use App\Models\App as SoketiApp;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use function abort;

class MetricsController extends Controller
{
    private int $defaultCacheTtl = 300; // 5 minutes cache for webhook metrics

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
                'webhook_based' => true, // Indicate this is webhook-based metrics
            ],
        ]);
    }

    // Webhook endpoint for receiving Soketi events 
    public function webhook(Request $request, SoketiApp $app)
    {
        // Validate the webhook signature (basic security)
        $this->validateWebhookSignature($request, $app);
        
        $payload = $request->json()->all();
        $timestamp = Carbon::now();
        
        // Process each event for real-time metrics
        foreach ($payload['events'] ?? [] as $event) {
            $this->processWebhookEvent($event, $app->id, $timestamp);
        }
        
        Log::info('Processed webhook metrics', [
            'app_id' => $app->id,
            'event_count' => count($payload['events'] ?? []),
            'timestamp' => $timestamp->toISOString()
        ]);
        
        return Response::json(['status' => 'ok']);
    }
    
    // Get real-time metrics from cache (webhook-based)
    public function getCachedMetrics(Request $request, SoketiApp $app)
    {
        $cachePrefix = "metrics:realtime:app:{$app->id}";
        
        return Response::json([
            'current_connections' => (int) Cache::get("{$cachePrefix}:channels_occupied", 0),
            'total_members' => (int) Cache::get("{$cachePrefix}:total_members", 0),
            'bytes_transferred' => (int) Cache::get("{$cachePrefix}:bytes_transferred", 0),
            'connection_events' => [
                'last_hour' => (int) Cache::get("{$cachePrefix}:events:connections:hour:" . Carbon::now()->format('Y-m-d-H'), 0),
                'last_minute' => (int) Cache::get("{$cachePrefix}:events:connections:minute:" . Carbon::now()->format('Y-m-d-H-i'), 0),
            ],
            'disconnection_events' => [
                'last_hour' => (int) Cache::get("{$cachePrefix}:events:disconnections:hour:" . Carbon::now()->format('Y-m-d-H'), 0),
                'last_minute' => (int) Cache::get("{$cachePrefix}:events:disconnections:minute:" . Carbon::now()->format('Y-m-d-H-i'), 0),
            ],
            'client_events' => $this->getClientEventsCounts($app->id),
            'channels' => $this->getChannelMetrics($app->id),
            'upload_metrics' => $this->getUploadMetrics($app->id),
            'last_updated' => Cache::get("{$cachePrefix}:last_updated"),
        ]);
    }

    // Get time series data for charts (webhook-based)
    public function getTimeSeriesData(Request $request, SoketiApp $app)
    {
        $metric = $request->query('metric', 'connections');
        $timeRange = $request->query('range', '1h'); // 1h, 6h, 24h
        
        $cachePrefix = "metrics:realtime:app:{$app->id}";
        $data = [];
        
        switch ($timeRange) {
            case '1h':
                $data = $this->getHourlyData($cachePrefix, $metric);
                break;
            case '6h':
                $data = $this->get6HourData($cachePrefix, $metric);
                break;
            case '24h':
                $data = $this->getDailyData($cachePrefix, $metric);
                break;
        }
        
        return Response::json([
            'metric' => $metric,
            'range' => $timeRange,
            'data' => $data,
            'timestamp' => Carbon::now()->toISOString()
        ]);
    }
    
    private function validateWebhookSignature(Request $request, SoketiApp $app): void
    {
        // Basic webhook validation - implement signature verification here
        $signature = $request->header('X-Soketi-Signature');
        $appKey = $request->header('X-Soketi-Key');
        
        if (!$signature || !$appKey) {
            abort(401, 'Missing webhook signature or key');
        }
        
        // Implement proper HMAC verification based on your app secret
        // $expectedSignature = hash_hmac('sha256', $request->getContent(), $app->secret);
        // if (!hash_equals($expectedSignature, $signature)) {
        //     abort(401, 'Invalid webhook signature');
        // }
    }
    
    private function processWebhookEvent(array $event, string $appId, Carbon $timestamp): void
    {
        $eventName = $event['name'];
        $cachePrefix = "metrics:realtime:app:{$appId}";
        $ttl = $this->defaultCacheTtl;
        
        switch ($eventName) {
            case 'channel_occupied':
                $this->incrementCacheMetric("{$cachePrefix}:channels_occupied", $ttl);
                $this->recordCacheEvent("{$cachePrefix}:events:connections", $timestamp);
                break;
                
            case 'channel_vacated':
                $this->decrementCacheMetric("{$cachePrefix}:channels_occupied", $ttl);
                $this->recordCacheEvent("{$cachePrefix}:events:disconnections", $timestamp);
                break;
                
            case 'member_added':
                $this->incrementCacheMetric("{$cachePrefix}:total_members", $ttl);
                break;
                
            case 'member_removed':
                $this->decrementCacheMetric("{$cachePrefix}:total_members", $ttl);
                break;
                
            case 'client_event':
                $dataSize = strlen(json_encode($event['data'] ?? ''));
                $this->addToCacheMetric("{$cachePrefix}:bytes_transferred", $dataSize, $ttl);
                $this->recordClientEvent($event, $appId, $timestamp);
                $this->trackUploadEvents($event, $appId, $timestamp);
                break;
                
            case 'subscription_count':
                $this->updateSubscriptionCount($event, $appId, $timestamp);
                break;
        }
        
        // Update last activity timestamp
        Cache::put("{$cachePrefix}:last_updated", $timestamp->toISOString(), $ttl);
    }
    
    private function incrementCacheMetric(string $key, int $ttl): void
    {
        $current = (int) Cache::get($key, 0);
        Cache::put($key, $current + 1, $ttl);
    }
    
    private function decrementCacheMetric(string $key, int $ttl): void
    {
        $current = (int) Cache::get($key, 0);
        Cache::put($key, max(0, $current - 1), $ttl);
    }
    
    private function addToCacheMetric(string $key, int $value, int $ttl): void
    {
        $current = (int) Cache::get($key, 0);
        Cache::put($key, $current + $value, $ttl);
    }
    
    private function recordCacheEvent(string $baseKey, Carbon $timestamp): void
    {
        $minuteKey = "{$baseKey}:minute:" . $timestamp->format('Y-m-d-H-i');
        $hourKey = "{$baseKey}:hour:" . $timestamp->format('Y-m-d-H');
        
        // Increment counters
        $minuteCount = (int) Cache::get($minuteKey, 0) + 1;
        $hourCount = (int) Cache::get($hourKey, 0) + 1;
        
        Cache::put($minuteKey, $minuteCount, 3600); // 1 hour TTL
        Cache::put($hourKey, $hourCount, 86400); // 24 hour TTL
    }
    
    private function recordClientEvent(array $event, string $appId, Carbon $timestamp): void
    {
        $eventName = $event['event'] ?? 'unknown';
        $cachePrefix = "metrics:realtime:app:{$appId}";
        
        // Record event counts by type
        $eventKey = "{$cachePrefix}:client_events:{$eventName}";
        $this->incrementCacheMetric($eventKey, $this->defaultCacheTtl);
    }
    
    private function updateSubscriptionCount(array $event, string $appId, Carbon $timestamp): void
    {
        $channel = $event['channel'];
        $count = $event['subscription_count'];
        
        $cachePrefix = "metrics:realtime:app:{$appId}";
        $key = "{$cachePrefix}:channel:{$channel}:subscription_count";
        
        Cache::put($key, $count, $this->defaultCacheTtl);
    }
    
    private function getClientEventsCounts(string $appId): array
    {
        $cachePrefix = "metrics:realtime:app:{$appId}";
        
        return [
            'chunked-upload.prepared' => (int) Cache::get("{$cachePrefix}:client_events:.chunked-upload.prepared", 0),
            'chunked-upload.completed' => (int) Cache::get("{$cachePrefix}:client_events:.chunked-upload.completed", 0),
            'chunked-upload.failed' => (int) Cache::get("{$cachePrefix}:client_events:.chunked-upload.failed", 0),
            'other' => (int) Cache::get("{$cachePrefix}:client_events:other", 0),
        ];
    }
    
    private function getChannelMetrics(string $appId): array
    {
        $cachePrefix = "metrics:realtime:app:{$appId}";
        
        return [
            'total_occupied' => (int) Cache::get("{$cachePrefix}:channels_occupied", 0),
            // Add more channel-specific metrics as needed
        ];
    }
    
    private function getHourlyData(string $cachePrefix, string $metric): array
    {
        $data = [];
        $now = Carbon::now();
        
        // Get last 60 minutes of data
        for ($i = 59; $i >= 0; $i--) {
            $time = $now->copy()->subMinutes($i);
            $key = "{$cachePrefix}:events:{$metric}:minute:" . $time->format('Y-m-d-H-i');
            $value = (int) Cache::get($key, 0);
            
            $data[] = [
                'timestamp' => $time->timestamp,
                'value' => $value
            ];
        }
        
        return $data;
    }
    
    private function get6HourData(string $cachePrefix, string $metric): array
    {
        $data = [];
        $now = Carbon::now();
        
        // Get last 6 hours of data (hourly buckets)
        for ($i = 5; $i >= 0; $i--) {
            $time = $now->copy()->subHours($i);
            $key = "{$cachePrefix}:events:{$metric}:hour:" . $time->format('Y-m-d-H');
            $value = (int) Cache::get($key, 0);
            
            $data[] = [
                'timestamp' => $time->timestamp,
                'value' => $value
            ];
        }
        
        return $data;
    }
    
    private function getDailyData(string $cachePrefix, string $metric): array
    {
        $data = [];
        $now = Carbon::now();
        
        // Get last 24 hours of data (hourly buckets)
        for ($i = 23; $i >= 0; $i--) {
            $time = $now->copy()->subHours($i);
            $key = "{$cachePrefix}:events:{$metric}:hour:" . $time->format('Y-m-d-H');
            $value = (int) Cache::get($key, 0);
            
            $data[] = [
                'timestamp' => $time->timestamp,
                'value' => $value
            ];
        }
        
        return $data;
    }
    
    private function trackUploadEvents(array $event, string $appId, Carbon $timestamp): void
    {
        $eventName = $event['event'] ?? '';
        $cachePrefix = "metrics:realtime:app:{$appId}";
        
        // Track upload-specific events
        if (str_contains($eventName, 'chunked-upload')) {
            $uploadId = null;
            
            // Try to extract upload ID from event data
            if (isset($event['data'])) {
                $data = is_string($event['data']) ? json_decode($event['data'], true) : $event['data'];
                $uploadId = $data['uploadId'] ?? null;
            }
            
            if ($eventName === '.chunked-upload.prepared') {
                // Track upload preparation
                $this->incrementCacheMetric("{$cachePrefix}:uploads:prepared", $this->defaultCacheTtl);
                $this->recordCacheEvent("{$cachePrefix}:uploads:events:prepared", $timestamp);
                
                if ($uploadId) {
                    // Store preparation time for this upload
                    Cache::put("{$cachePrefix}:upload:{$uploadId}:prepared_at", $timestamp->timestamp, 7200); // 2 hours TTL
                }
            } elseif ($eventName === '.chunked-upload.completed') {
                // Track upload completion
                $this->incrementCacheMetric("{$cachePrefix}:uploads:completed", $this->defaultCacheTtl);
                $this->recordCacheEvent("{$cachePrefix}:uploads:events:completed", $timestamp);
                
                if ($uploadId) {
                    // Calculate upload duration if we have the preparation time
                    $preparedAt = Cache::get("{$cachePrefix}:upload:{$uploadId}:prepared_at");
                    if ($preparedAt) {
                        $duration = $timestamp->timestamp - $preparedAt;
                        $this->recordUploadDuration($appId, $duration);
                        Cache::forget("{$cachePrefix}:upload:{$uploadId}:prepared_at");
                    }
                }
            } elseif ($eventName === '.chunked-upload.failed') {
                // Track upload failures
                $this->incrementCacheMetric("{$cachePrefix}:uploads:failed", $this->defaultCacheTtl);
                $this->recordCacheEvent("{$cachePrefix}:uploads:events:failed", $timestamp);
                
                if ($uploadId) {
                    Cache::forget("{$cachePrefix}:upload:{$uploadId}:prepared_at");
                }
            }
        }
    }
    
    private function recordUploadDuration(string $appId, int $durationSeconds): void
    {
        $cachePrefix = "metrics:realtime:app:{$appId}";
        
        // Update average upload duration
        $totalDuration = (int) Cache::get("{$cachePrefix}:uploads:total_duration", 0);
        $uploadCount = (int) Cache::get("{$cachePrefix}:uploads:duration_count", 0);
        
        Cache::put("{$cachePrefix}:uploads:total_duration", $totalDuration + $durationSeconds, $this->defaultCacheTtl);
        Cache::put("{$cachePrefix}:uploads:duration_count", $uploadCount + 1, $this->defaultCacheTtl);
        
        // Track duration buckets for histogram
        $bucket = $this->getDurationBucket($durationSeconds);
        $bucketKey = "{$cachePrefix}:uploads:duration_bucket:{$bucket}";
        $this->incrementCacheMetric($bucketKey, $this->defaultCacheTtl);
    }
    
    private function getDurationBucket(int $seconds): string
    {
        if ($seconds < 10) return '0-10s';
        if ($seconds < 30) return '10-30s';
        if ($seconds < 60) return '30-60s';
        if ($seconds < 120) return '1-2m';
        if ($seconds < 300) return '2-5m';
        return '5m+';
    }
    
    private function getUploadMetrics(string $appId): array
    {
        $cachePrefix = "metrics:realtime:app:{$appId}";
        
        $prepared = (int) Cache::get("{$cachePrefix}:uploads:prepared", 0);
        $completed = (int) Cache::get("{$cachePrefix}:uploads:completed", 0);
        $failed = (int) Cache::get("{$cachePrefix}:uploads:failed", 0);
        
        $totalDuration = (int) Cache::get("{$cachePrefix}:uploads:total_duration", 0);
        $durationCount = (int) Cache::get("{$cachePrefix}:uploads:duration_count", 0);
        $avgDuration = $durationCount > 0 ? round($totalDuration / $durationCount) : 0;
        
        // Calculate active uploads (prepared but not yet completed/failed)
        $activeUploads = max(0, $prepared - $completed - $failed);
        
        return [
            'active_uploads' => $activeUploads,
            'total_prepared' => $prepared,
            'total_completed' => $completed,
            'total_failed' => $failed,
            'completion_rate' => $prepared > 0 ? round(($completed / $prepared) * 100, 2) : 0,
            'average_duration_seconds' => $avgDuration,
            'duration_buckets' => [
                '0-10s' => (int) Cache::get("{$cachePrefix}:uploads:duration_bucket:0-10s", 0),
                '10-30s' => (int) Cache::get("{$cachePrefix}:uploads:duration_bucket:10-30s", 0),
                '30-60s' => (int) Cache::get("{$cachePrefix}:uploads:duration_bucket:30-60s", 0),
                '1-2m' => (int) Cache::get("{$cachePrefix}:uploads:duration_bucket:1-2m", 0),
                '2-5m' => (int) Cache::get("{$cachePrefix}:uploads:duration_bucket:2-5m", 0),
                '5m+' => (int) Cache::get("{$cachePrefix}:uploads:duration_bucket:5m+", 0),
            ],
            'events' => [
                'prepared_last_hour' => (int) Cache::get("{$cachePrefix}:uploads:events:prepared:hour:" . Carbon::now()->format('Y-m-d-H'), 0),
                'completed_last_hour' => (int) Cache::get("{$cachePrefix}:uploads:events:completed:hour:" . Carbon::now()->format('Y-m-d-H'), 0),
                'failed_last_hour' => (int) Cache::get("{$cachePrefix}:uploads:events:failed:hour:" . Carbon::now()->format('Y-m-d-H'), 0),
            ],
        ];
    }
}