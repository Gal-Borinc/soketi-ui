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
        $pattern = "{$cachePrefix}:client_events:*";
        
        // Get all client event keys (this is simplified - in production you'd want to track event types)
        return [
            'upload_progress' => (int) Cache::get("{$cachePrefix}:client_events:upload_progress", 0),
            'upload_complete' => (int) Cache::get("{$cachePrefix}:client_events:upload_complete", 0),
            'upload_error' => (int) Cache::get("{$cachePrefix}:client_events:upload_error", 0),
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
}