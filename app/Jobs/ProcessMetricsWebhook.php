<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessMetricsWebhook extends ProcessWebhookJob
{
    /**
     * Process incoming metrics webhook for real-time dashboard updates.
     *
     * @return void
     */
    public function handle()
    {
        $appId = $this->webhookCall->headers()->get('x-app-id');
        $payload = $this->webhookCall->payload;
        
        $timestamp = Carbon::parse($payload['time_ms'] / 1000);
        
        foreach ($payload['events'] as $event) {
            $this->processMetricEvent($event, $appId, $timestamp);
        }
        
        Log::info('Processed webhook metrics', [
            'app_id' => $appId,
            'event_count' => count($payload['events']),
            'timestamp' => $timestamp->toISOString()
        ]);
    }
    
    private function processMetricEvent(array $event, string $appId, Carbon $timestamp): void
    {
        $channel = $event['channel'] ?? null;
        $eventName = $event['name'];
        
        // Cache keys for real-time metrics
        $cachePrefix = "metrics:realtime:app:{$appId}";
        $ttl = 300; // 5 minutes cache
        
        switch ($eventName) {
            case 'channel_occupied':
                $this->incrementMetric("{$cachePrefix}:channels_occupied", $ttl);
                $this->recordEvent("{$cachePrefix}:events:connections", $timestamp);
                break;
                
            case 'channel_vacated':
                $this->decrementMetric("{$cachePrefix}:channels_occupied", $ttl);
                $this->recordEvent("{$cachePrefix}:events:disconnections", $timestamp);
                break;
                
            case 'member_added':
                $this->incrementMetric("{$cachePrefix}:total_members", $ttl);
                $this->recordEvent("{$cachePrefix}:events:member_joins", $timestamp);
                break;
                
            case 'member_removed':
                $this->decrementMetric("{$cachePrefix}:total_members", $ttl);
                $this->recordEvent("{$cachePrefix}:events:member_leaves", $timestamp);
                break;
                
            case 'client_event':
                $this->recordClientEvent($event, $appId, $timestamp);
                break;
                
            case 'subscription_count':
                $this->updateSubscriptionCount($event, $appId, $timestamp);
                break;
        }
    }
    
    private function incrementMetric(string $key, int $ttl): void
    {
        $current = (int) Cache::get($key, 0);
        Cache::put($key, $current + 1, $ttl);
    }
    
    private function decrementMetric(string $key, int $ttl): void
    {
        $current = (int) Cache::get($key, 0);
        Cache::put($key, max(0, $current - 1), $ttl);
    }
    
    private function recordEvent(string $baseKey, Carbon $timestamp): void
    {
        // Store time-series data for rate calculations
        $minuteKey = "{$baseKey}:minute:" . $timestamp->format('Y-m-d-H-i');
        $hourKey = "{$baseKey}:hour:" . $timestamp->format('Y-m-d-H');
        
        Cache::increment($minuteKey);
        Cache::increment($hourKey);
        
        // Set expiration (Laravel Cache doesn't have expire, use put with TTL)
        Cache::put($minuteKey, Cache::get($minuteKey, 0), 3600); // 1 hour
        Cache::put($hourKey, Cache::get($hourKey, 0), 86400); // 24 hours
    }
    
    private function recordClientEvent(array $event, string $appId, Carbon $timestamp): void
    {
        $eventName = $event['event'] ?? 'unknown';
        $dataSize = strlen(json_encode($event['data'] ?? ''));
        
        $cachePrefix = "metrics:realtime:app:{$appId}";
        
        // Record data transfer
        $this->addToMetric("{$cachePrefix}:bytes_transferred", $dataSize, 300);
        
        // Record event counts by type
        $eventKey = "{$cachePrefix}:client_events:{$eventName}";
        $this->incrementMetric($eventKey, 300);
    }
    
    private function updateSubscriptionCount(array $event, string $appId, Carbon $timestamp): void
    {
        $channel = $event['channel'];
        $count = $event['subscription_count'];
        
        $cachePrefix = "metrics:realtime:app:{$appId}";
        $key = "{$cachePrefix}:channel:{$channel}:subscription_count";
        
        Cache::put($key, $count, 300);
    }
    
    private function addToMetric(string $key, int $value, int $ttl): void
    {
        $current = (int) Cache::get($key, 0);
        Cache::put($key, $current + $value, $ttl);
    }
}
