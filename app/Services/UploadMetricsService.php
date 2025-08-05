<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class UploadMetricsService
{
    private int $cacheTtl = 3600; // 1 hour
    
    /**
     * Record when an upload starts (when URLs are prepared)
     */
    public function recordUploadPrepared(string $uploadId, int $userId, array $metadata = []): void
    {
        $timestamp = Carbon::now();
        
        $uploadData = [
            'upload_id' => $uploadId,
            'user_id' => $userId,
            'status' => 'prepared',
            'prepared_at' => $timestamp->timestamp,
            'metadata' => $metadata,
        ];
        
        // Store individual upload tracking
        Cache::put("upload_tracking:{$uploadId}", $uploadData, $this->cacheTtl);
        
        // Update aggregate metrics
        $this->incrementCounter('uploads:prepared:total');
        $this->incrementCounter('uploads:prepared:hourly:' . $timestamp->format('Y-m-d-H'));
        $this->incrementCounter('uploads:prepared:daily:' . $timestamp->format('Y-m-d'));
        
        Log::debug('Upload prepared recorded', [
            'upload_id' => $uploadId,
            'user_id' => $userId,
            'timestamp' => $timestamp->toISOString()
        ]);
    }
    
    /**
     * Record when an upload completes successfully
     */
    public function recordUploadCompleted(string $uploadId, int $videoId = null): void
    {
        $timestamp = Carbon::now();
        $uploadData = Cache::get("upload_tracking:{$uploadId}");
        
        if (!$uploadData) {
            Log::warning('Upload completion recorded but no tracking data found', [
                'upload_id' => $uploadId
            ]);
            return;
        }
        
        // Calculate duration
        $preparedAt = $uploadData['prepared_at'];
        $duration = $timestamp->timestamp - $preparedAt;
        
        // Update tracking data
        $uploadData['status'] = 'completed';
        $uploadData['completed_at'] = $timestamp->timestamp;
        $uploadData['duration_seconds'] = $duration;
        $uploadData['video_id'] = $videoId;
        
        Cache::put("upload_tracking:{$uploadId}", $uploadData, $this->cacheTtl);
        
        // Update aggregate metrics
        $this->incrementCounter('uploads:completed:total');
        $this->incrementCounter('uploads:completed:hourly:' . $timestamp->format('Y-m-d-H'));
        $this->incrementCounter('uploads:completed:daily:' . $timestamp->format('Y-m-d'));
        
        // Track duration buckets
        $bucket = $this->getDurationBucket($duration);
        $this->incrementCounter("uploads:duration_bucket:{$bucket}");
        
        // Update running averages
        $this->updateRunningAverage('uploads:avg_duration', $duration);
        
        Log::debug('Upload completed recorded', [
            'upload_id' => $uploadId,
            'duration_seconds' => $duration,
            'video_id' => $videoId
        ]);
    }
    
    /**
     * Record when an upload fails
     */
    public function recordUploadFailed(string $uploadId, string $error = null): void
    {
        $timestamp = Carbon::now();
        $uploadData = Cache::get("upload_tracking:{$uploadId}");
        
        if ($uploadData) {
            $uploadData['status'] = 'failed';
            $uploadData['failed_at'] = $timestamp->timestamp;
            $uploadData['error'] = $error;
            
            Cache::put("upload_tracking:{$uploadId}", $uploadData, $this->cacheTtl);
        }
        
        // Update aggregate metrics
        $this->incrementCounter('uploads:failed:total');
        $this->incrementCounter('uploads:failed:hourly:' . $timestamp->format('Y-m-d-H'));
        $this->incrementCounter('uploads:failed:daily:' . $timestamp->format('Y-m-d'));
        
        Log::debug('Upload failure recorded', [
            'upload_id' => $uploadId,
            'error' => $error
        ]);
    }
    
    /**
     * Get comprehensive upload metrics
     */
    public function getUploadMetrics(): array
    {
        $now = Carbon::now();
        
        // Get current totals
        $totalPrepared = (int) Cache::get('uploads:prepared:total', 0);
        $totalCompleted = (int) Cache::get('uploads:completed:total', 0);
        $totalFailed = (int) Cache::get('uploads:failed:total', 0);
        
        // Get hourly metrics
        $hourKey = $now->format('Y-m-d-H');
        $preparedThisHour = (int) Cache::get("uploads:prepared:hourly:{$hourKey}", 0);
        $completedThisHour = (int) Cache::get("uploads:completed:hourly:{$hourKey}", 0);
        $failedThisHour = (int) Cache::get("uploads:failed:hourly:{$hourKey}", 0);
        
        // Calculate active uploads (prepared but not completed/failed)
        $activeUploads = max(0, $totalPrepared - $totalCompleted - $totalFailed);
        
        // Get completion rate
        $completionRate = $totalPrepared > 0 ? round(($totalCompleted / $totalPrepared) * 100, 2) : 0;
        
        // Get average duration
        $avgDuration = $this->getRunningAverage('uploads:avg_duration');
        
        // Get duration distribution
        $durationBuckets = [
            '0-10s' => (int) Cache::get('uploads:duration_bucket:0-10s', 0),
            '10-30s' => (int) Cache::get('uploads:duration_bucket:10-30s', 0),
            '30-60s' => (int) Cache::get('uploads:duration_bucket:30-60s', 0),
            '1-2m' => (int) Cache::get('uploads:duration_bucket:1-2m', 0),
            '2-5m' => (int) Cache::get('uploads:duration_bucket:2-5m', 0),
            '5m+' => (int) Cache::get('uploads:duration_bucket:5m+', 0),
        ];
        
        return [
            'active_uploads' => $activeUploads,
            'total_prepared' => $totalPrepared,
            'total_completed' => $totalCompleted,
            'total_failed' => $totalFailed,
            'completion_rate' => $completionRate,
            'average_duration_seconds' => $avgDuration,
            'duration_buckets' => $durationBuckets,
            'events' => [
                'prepared_last_hour' => $preparedThisHour,
                'completed_last_hour' => $completedThisHour,
                'failed_last_hour' => $failedThisHour,
            ],
        ];
    }
    
    /**
     * Get active upload sessions
     */
    public function getActiveUploadSessions(): array
    {
        $pattern = 'upload_tracking:*';
        $keys = Cache::getRedis()->keys($pattern);
        
        $activeSessions = [];
        foreach ($keys as $key) {
            $data = Cache::get($key);
            if ($data && $data['status'] === 'prepared') {
                $activeSessions[] = [
                    'upload_id' => $data['upload_id'],
                    'user_id' => $data['user_id'],
                    'prepared_at' => $data['prepared_at'],
                    'duration_so_far' => time() - $data['prepared_at'],
                    'metadata' => $data['metadata'] ?? [],
                ];
            }
        }
        
        return $activeSessions;
    }
    
    /**
     * Clean up old upload tracking data
     */
    public function cleanupOldTrackingData(): void
    {
        $cutoff = Carbon::now()->subHours(4)->timestamp;
        $pattern = 'upload_tracking:*';
        $keys = Cache::getRedis()->keys($pattern);
        
        $cleaned = 0;
        foreach ($keys as $key) {
            $data = Cache::get($key);
            if ($data && isset($data['prepared_at']) && $data['prepared_at'] < $cutoff) {
                Cache::forget($key);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            Log::info("Cleaned up {$cleaned} old upload tracking records");
        }
    }
    
    /**
     * Increment a counter in cache
     */
    private function incrementCounter(string $key): void
    {
        $current = (int) Cache::get($key, 0);
        Cache::put($key, $current + 1, $this->cacheTtl);
    }
    
    /**
     * Update running average
     */
    private function updateRunningAverage(string $key, float $newValue): void
    {
        $data = Cache::get($key, ['sum' => 0, 'count' => 0]);
        
        $data['sum'] += $newValue;
        $data['count'] += 1;
        
        Cache::put($key, $data, $this->cacheTtl);
    }
    
    /**
     * Get running average
     */
    private function getRunningAverage(string $key): float
    {
        $data = Cache::get($key, ['sum' => 0, 'count' => 0]);
        
        return $data['count'] > 0 ? round($data['sum'] / $data['count'], 2) : 0.0;
    }
    
    /**
     * Get duration bucket for a given number of seconds
     */
    private function getDurationBucket(int $seconds): string
    {
        if ($seconds < 10) return '0-10s';
        if ($seconds < 30) return '10-30s';
        if ($seconds < 60) return '30-60s';
        if ($seconds < 120) return '1-2m';
        if ($seconds < 300) return '2-5m';
        return '5m+';
    }
}