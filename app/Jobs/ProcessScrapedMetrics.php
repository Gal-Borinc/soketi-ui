<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProcessScrapedMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $cacheTtl = 600; // 10 minutes
    
    public function __construct()
    {
        // Job configuration
        $this->onQueue('metrics');
    }

    /**
     * Execute the job to process scraped Soketi metrics
     */
    public function handle(): void
    {
        try {
            $timestamp = Carbon::now();
            
            // Get raw scraped metrics
            $processedMetrics = Cache::get('soketi:processed_metrics');
            if (!$processedMetrics) {
                Log::warning('No processed metrics found in cache');
                return;
            }
            
            // Enhance metrics with additional calculated values
            $enhancedMetrics = array_merge($processedMetrics, [
                'processed_at' => $timestamp->toISOString(),
                'processed_timestamp' => $timestamp->timestamp,
                'performance' => $this->calculatePerformanceMetrics($processedMetrics)
            ]);
            
            Cache::put('soketi:enhanced_metrics', $enhancedMetrics, $this->cacheTtl);
            
            // Update real-time metrics cache (for backwards compatibility with existing API)
            $this->updateRealtimeMetricsCache($enhancedMetrics, $timestamp);
            
            Log::debug('ProcessScrapedMetrics completed', [
                'timestamp' => $timestamp->toISOString(),
                'metrics_keys' => array_keys($enhancedMetrics)
            ]);
            
        } catch (\Exception $e) {
            Log::error('ProcessScrapedMetrics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * Calculate performance metrics for WebSocket server
     */
    private function calculatePerformanceMetrics(array $processedMetrics): array
    {
        $connections = $processedMetrics['connections'] ?? [];
        $dataTransfer = $processedMetrics['data_transfer'] ?? [];
        $websockets = $processedMetrics['websockets'] ?? [];
        $system = $processedMetrics['system'] ?? [];
        
        return [
            'connections_per_hour' => $connections['total_new'] ?? 0,
            'avg_message_size' => $this->calculateAverageMessageSize($dataTransfer, $websockets),
            'memory_per_connection' => $this->calculateMemoryPerConnection($system, $connections),
            'uptime_hours' => round(($system['uptime'] ?? 0) / 3600, 1),
            'server_health' => $this->assessServerHealth($system, $connections),
        ];
    }
    
    /**
     * Calculate average WebSocket message size
     */
    private function calculateAverageMessageSize(array $dataTransfer, array $websockets): float
    {
        $totalMessages = $websockets['messages_sent'] ?? 0;
        $totalBytes = ($dataTransfer['bytes_sent'] ?? 0);
        
        return $totalMessages > 0 ? round($totalBytes / $totalMessages, 2) : 0;
    }
    
    /**
     * Calculate memory usage per connection
     */
    private function calculateMemoryPerConnection(array $system, array $connections): float
    {
        $currentConnections = $connections['current'] ?? 1;
        $memoryUsage = $system['memory_usage'] ?? 0;
        
        return $currentConnections > 0 ? round($memoryUsage / $currentConnections, 0) : 0;
    }
    
    /**
     * Assess overall server health
     */
    private function assessServerHealth(array $system, array $connections): string
    {
        $memoryMB = ($system['memory_usage'] ?? 0) / (1024 * 1024);
        $connections = $connections['current'] ?? 0;
        
        if ($memoryMB > 500 || $connections > 1000) {
            return 'high_load';
        } elseif ($memoryMB > 200 || $connections > 500) {
            return 'moderate_load';  
        } else {
            return 'healthy';
        }
    }
    
    /**
     * Calculate WebSocket message rate
     */
    private function calculateMessageRate(array $metrics): float
    {
        $websockets = $metrics['websockets'] ?? [];
        return round(($websockets['messages_sent_since_last_scrape'] ?? 0) * 6, 2); // per minute
    }
    
    /**
     * Calculate connection stability score
     */
    private function calculateConnectionStability(array $connections): float
    {
        $current = $connections['current'] ?? 0;
        $total = $connections['total_new'] ?? 1;
        $disconnections = $connections['total_disconnections'] ?? 0;
        
        if ($total == 0) return 100;
        
        $stability = (($total - $disconnections) / $total) * 100;
        return round(max(0, min(100, $stability)), 1);
    }
    
    
    /**
     * Update real-time metrics cache for backwards compatibility
     */
    private function updateRealtimeMetricsCache(array $enhancedMetrics, Carbon $timestamp): void
    {
        // Transform enhanced metrics to the format expected by existing API
        $connections = $enhancedMetrics['connections'] ?? [];
        $dataTransfer = $enhancedMetrics['data_transfer'] ?? [];
        $uploadMetrics = $enhancedMetrics['upload_metrics'] ?? [];
        
        $realtimeMetrics = [
            'current_connections' => $connections['current'] ?? 0,
            'total_members' => $connections['current'] ?? 0, // Approximate
            'bytes_transferred' => $dataTransfer['bytes_received'] + $dataTransfer['bytes_sent'],
            'connection_events' => [
                'last_hour' => $this->getHourlyCount('connections', $timestamp),
                'last_minute' => $this->getMinuteCount('connections', $timestamp),
            ],
            'disconnection_events' => [
                'last_hour' => $this->getHourlyCount('disconnections', $timestamp),
                'last_minute' => $this->getMinuteCount('disconnections', $timestamp),
            ],
            'websocket_events' => [
                'messages_sent_rate' => $this->calculateMessageRate($enhancedMetrics),
                'connection_stability' => $this->calculateConnectionStability($connections),
                'data_throughput' => ($dataTransfer['bytes_received'] ?? 0) + ($dataTransfer['bytes_sent'] ?? 0),
            ],
            'channels' => [
                'total_occupied' => $connections['current'] ?? 0,
            ],
            'last_updated' => $timestamp->toISOString(),
        ];
        
        // Store in the cache key expected by MetricsController
        Cache::put('soketi:realtime_metrics', $realtimeMetrics, $this->cacheTtl);
    }
    
    /**
     * Get hourly event count from time-series data
     */
    private function getHourlyCount(string $eventType, Carbon $timestamp): int
    {
        $hourKey = $timestamp->format('Y-m-d-H');
        $data = Cache::get("soketi:timeseries:hour:{$hourKey}", []);
        
        if ($eventType === 'connections') {
            return $data['connections_count'] ?? 0;
        } elseif ($eventType === 'disconnections') {
            return $data['disconnections_count'] ?? 0;
        }
        
        return 0;
    }
    
    /**
     * Get minute event count from time-series data
     */
    private function getMinuteCount(string $eventType, Carbon $timestamp): int
    {
        $minuteKey = $timestamp->format('Y-m-d-H-i');
        $data = Cache::get("soketi:timeseries:minute:{$minuteKey}", []);
        
        if ($eventType === 'connections') {
            return isset($data['new_connections']) ? 1 : 0; // Simple approximation
        } elseif ($eventType === 'disconnections') {
            return isset($data['disconnections']) ? 1 : 0;
        }
        
        return 0;
    }
}