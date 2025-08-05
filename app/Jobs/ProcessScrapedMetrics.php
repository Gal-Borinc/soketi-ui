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
use App\Services\UploadMetricsService;

class ProcessScrapedMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $cacheTtl = 600; // 10 minutes
    private UploadMetricsService $uploadMetricsService;
    
    public function __construct()
    {
        // Job configuration
        $this->onQueue('metrics');
        $this->uploadMetricsService = app(UploadMetricsService::class);
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
            
            // Derive upload-specific metrics from connection patterns
            $uploadMetrics = $this->deriveUploadMetrics($processedMetrics, $timestamp);
            
            // Store enhanced metrics for API consumption
            $enhancedMetrics = array_merge($processedMetrics, [
                'upload_metrics' => $uploadMetrics,
                'processed_at' => $timestamp->toISOString(),
                'processed_timestamp' => $timestamp->timestamp
            ]);
            
            Cache::put('soketi:enhanced_metrics', $enhancedMetrics, $this->cacheTtl);
            
            // Get upload metrics from tracking service
            $uploadMetrics = $this->uploadMetricsService->getUploadMetrics();
            $enhancedMetrics['upload_metrics'] = $uploadMetrics;
            
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
     * Derive upload-specific metrics from connection and data transfer patterns
     */
    private function deriveUploadMetrics(array $processedMetrics, Carbon $timestamp): array
    {
        // Get historical data for trend analysis
        $previousMetrics = Cache::get('soketi:previous_enhanced_metrics', []);
        
        $connections = $processedMetrics['connections'] ?? [];
        $dataTransfer = $processedMetrics['data_transfer'] ?? [];
        
        // Calculate active upload sessions based on connection patterns
        $currentConnections = $connections['current'] ?? 0;
        $totalConnections = $connections['total_new'] ?? 0;
        $totalDisconnections = $connections['total_disconnections'] ?? 0;
        
        // Calculate data transfer rates
        $bytesReceived = $dataTransfer['bytes_received'] ?? 0;
        $bytesSent = $dataTransfer['bytes_sent'] ?? 0;
        $totalBytes = $bytesReceived + $bytesSent;
        
        // Derive upload metrics from patterns
        $uploadMetrics = [
            'active_sessions' => $currentConnections,
            'total_bytes_transferred' => $totalBytes,
            'upload_ratio' => $bytesReceived > 0 ? round(($bytesReceived / max($totalBytes, 1)) * 100, 2) : 0,
            'avg_session_duration' => $this->calculateAverageSessionDuration($processedMetrics),
            'data_transfer_rate' => $this->calculateDataTransferRate($processedMetrics, $previousMetrics),
            'connection_events' => [
                'connections_per_minute' => $this->calculateConnectionRate($processedMetrics, $previousMetrics, 'connections'),
                'disconnections_per_minute' => $this->calculateConnectionRate($processedMetrics, $previousMetrics, 'disconnections'),
            ],
            'upload_patterns' => $this->analyzeUploadPatterns($processedMetrics, $previousMetrics),
        ];
        
        // Store current metrics as previous for next iteration
        Cache::put('soketi:previous_enhanced_metrics', $processedMetrics, $this->cacheTtl);
        
        return $uploadMetrics;
    }
    
    /**
     * Calculate average session duration based on connection patterns
     */
    private function calculateAverageSessionDuration(array $metrics): float
    {
        $connections = $metrics['connections'] ?? [];
        $totalConnections = $connections['total_new'] ?? 0;
        $totalDisconnections = $connections['total_disconnections'] ?? 0;
        
        if ($totalDisconnections === 0) {
            return 0.0;
        }
        
        // Simple estimation based on connection/disconnection ratio
        // In a real implementation, you'd track actual session durations
        $avgSessions = max($totalConnections, $totalDisconnections);
        return $avgSessions > 0 ? round(300 / max($avgSessions / 100, 1), 2) : 0.0; // Rough estimate
    }
    
    /**
     * Calculate data transfer rate (bytes per second)
     */
    private function calculateDataTransferRate(array $current, array $previous): float
    {
        if (empty($previous)) {
            return 0.0;
        }
        
        $currentBytes = ($current['data_transfer']['bytes_received'] ?? 0) + ($current['data_transfer']['bytes_sent'] ?? 0);
        $previousBytes = ($previous['data_transfer']['bytes_received'] ?? 0) + ($previous['data_transfer']['bytes_sent'] ?? 0);
        
        $bytesDiff = $currentBytes - $previousBytes;
        
        // Assuming 10-second intervals between scrapes
        $timeDiff = 10; 
        
        return $timeDiff > 0 ? round($bytesDiff / $timeDiff, 2) : 0.0;
    }
    
    /**
     * Calculate connection rate (connections per minute)
     */
    private function calculateConnectionRate(array $current, array $previous, string $type): float
    {
        if (empty($previous)) {
            return 0.0;
        }
        
        $field = $type === 'connections' ? 'total_new' : 'total_disconnections';
        $currentCount = $current['connections'][$field] ?? 0;
        $previousCount = $previous['connections'][$field] ?? 0;
        
        $diff = $currentCount - $previousCount;
        
        // Convert to per-minute rate (assuming 10-second intervals)
        return round($diff * 6, 2); // 6 * 10 seconds = 60 seconds
    }
    
    /**
     * Analyze upload patterns from connection and data transfer trends
     */
    private function analyzeUploadPatterns(array $current, array $previous): array
    {
        $patterns = [
            'trend' => 'stable',
            'peak_detected' => false,
            'concurrent_uploads_estimate' => 0,
            'data_intensity' => 'low' // low, medium, high
        ];
        
        if (empty($previous)) {
            return $patterns;
        }
        
        // Analyze connection trend
        $currentConnections = $current['connections']['current'] ?? 0;
        $previousConnections = $previous['connections']['current'] ?? 0;
        
        if ($currentConnections > $previousConnections * 1.2) {
            $patterns['trend'] = 'increasing';
            $patterns['peak_detected'] = $currentConnections > 10; // Arbitrary threshold
        } elseif ($currentConnections < $previousConnections * 0.8) {
            $patterns['trend'] = 'decreasing';
        }
        
        // Estimate concurrent uploads (rough heuristic)
        $patterns['concurrent_uploads_estimate'] = max(0, floor($currentConnections * 0.7)); // Assume 70% are upload sessions
        
        // Analyze data intensity
        $dataRate = $this->calculateDataTransferRate($current, $previous);
        if ($dataRate > 1000000) { // > 1MB/s
            $patterns['data_intensity'] = 'high';
        } elseif ($dataRate > 100000) { // > 100KB/s
            $patterns['data_intensity'] = 'medium';
        }
        
        return $patterns;
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
            'client_events' => [
                'chunked-upload.prepared' => 0, // These will be populated when we add upload correlation
                'chunked-upload.completed' => 0,
                'chunked-upload.failed' => 0,
                'other' => 0,
            ],
            'upload_metrics' => [
                'active_uploads' => $uploadMetrics['active_sessions'] ?? 0,
                'total_prepared' => 0, // Will be populated with upload correlation
                'total_completed' => 0,
                'total_failed' => 0,
                'completion_rate' => 0,
                'average_duration_seconds' => $uploadMetrics['avg_session_duration'] ?? 0,
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