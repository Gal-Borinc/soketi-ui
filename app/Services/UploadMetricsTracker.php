<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class UploadMetricsTracker
{
    private const CACHE_TTL = 300; // 5 minutes
    private const REALTIME_CACHE_KEY = 'soketi:upload_metrics:realtime';
    private const HOURLY_CACHE_KEY = 'soketi:upload_metrics:hourly:';
    
    /**
     * Track a chunked upload prepared event
     */
    public function trackUploadPrepared(string $uploadId, int $userId, array $metadata): void
    {
        try {
            DB::table('upload_metrics')->insert([
                'upload_id' => $uploadId,
                'user_id' => $userId,
                'event_type' => 'prepared',
                'status' => 'ready',
                'file_size' => $metadata['fileSize'] ?? null,
                'file_name' => $metadata['fileName'] ?? null,
                'chunk_count' => $metadata['chunkCount'] ?? null,
                'chunk_size' => $metadata['chunkSize'] ?? null,
                'estimated_duration' => $metadata['estimatedDuration'] ?? null,
                'prepared_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            
            $this->updateRealtimeMetrics('prepared');
            $this->incrementHourlyMetric('total_uploads');
            
        } catch (\Exception $e) {
            Log::error('Failed to track upload prepared', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Track a chunked upload completed event
     */
    public function trackUploadCompleted(string $uploadId, int $userId, int $videoId, array $metadata): void
    {
        try {
            // Update existing record or create new
            $existing = DB::table('upload_metrics')
                ->where('upload_id', $uploadId)
                ->where('event_type', 'prepared')
                ->first();
            
            if ($existing) {
                // Calculate duration
                $duration = null;
                if ($existing->prepared_at) {
                    $preparedAt = Carbon::parse($existing->prepared_at);
                    $duration = Carbon::now()->diffInSeconds($preparedAt);
                }
                
                // Calculate upload speed
                $uploadSpeed = null;
                if ($duration > 0 && $metadata['finalFileSize'] ?? 0) {
                    $uploadSpeed = $metadata['finalFileSize'] / $duration;
                }
                
                DB::table('upload_metrics')
                    ->where('id', $existing->id)
                    ->update([
                        'video_id' => $videoId,
                        'event_type' => 'completed',
                        'status' => 'completed',
                        'completed_at' => Carbon::now(),
                        'upload_duration' => $duration,
                        'processing_time' => $metadata['processingTime'] ?? null,
                        'bytes_uploaded' => $metadata['finalFileSize'] ?? $metadata['fileSize'] ?? null,
                        'percentage_completed' => 100,
                        'upload_speed' => $uploadSpeed,
                        'updated_at' => Carbon::now(),
                    ]);
            } else {
                // Create new record if prepared event was missed
                DB::table('upload_metrics')->insert([
                    'upload_id' => $uploadId,
                    'user_id' => $userId,
                    'video_id' => $videoId,
                    'event_type' => 'completed',
                    'status' => 'completed',
                    'file_size' => $metadata['fileSize'] ?? null,
                    'file_name' => $metadata['fileName'] ?? null,
                    'chunk_count' => $metadata['chunkCount'] ?? null,
                    'upload_duration' => $metadata['uploadDuration'] ?? null,
                    'processing_time' => $metadata['processingTime'] ?? null,
                    'bytes_uploaded' => $metadata['finalFileSize'] ?? $metadata['fileSize'] ?? null,
                    'percentage_completed' => 100,
                    'completed_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
            
            $this->updateRealtimeMetrics('completed');
            $this->incrementHourlyMetric('completed_uploads');
            $this->updateCompletionRate();
            
        } catch (\Exception $e) {
            Log::error('Failed to track upload completed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Track a chunked upload failed event
     */
    public function trackUploadFailed(string $uploadId, int $userId, array $failureData): void
    {
        try {
            // Update existing record or create new
            $existing = DB::table('upload_metrics')
                ->where('upload_id', $uploadId)
                ->whereIn('event_type', ['prepared', 'in_progress'])
                ->first();
            
            if ($existing) {
                // Calculate duration until failure
                $duration = null;
                if ($existing->prepared_at) {
                    $preparedAt = Carbon::parse($existing->prepared_at);
                    $duration = Carbon::now()->diffInSeconds($preparedAt);
                }
                
                DB::table('upload_metrics')
                    ->where('id', $existing->id)
                    ->update([
                        'event_type' => 'failed',
                        'status' => 'failed',
                        'failed_at' => Carbon::now(),
                        'upload_duration' => $duration ?? $failureData['duration'] ?? null,
                        'percentage_completed' => $failureData['percentageCompleted'] ?? 0,
                        'chunks_completed' => $failureData['chunksCompleted'] ?? 0,
                        'bytes_uploaded' => $failureData['bytesUploaded'] ?? 0,
                        'error_message' => $failureData['message'] ?? 'Upload failed',
                        'error_code' => $failureData['code'] ?? 'UPLOAD_ERROR',
                        'error_stage' => $failureData['stage'] ?? 'unknown',
                        'retryable' => $failureData['retryable'] ?? false,
                        'attempt_number' => $failureData['attemptNumber'] ?? 1,
                        'updated_at' => Carbon::now(),
                    ]);
            } else {
                // Create new record if prepared event was missed
                DB::table('upload_metrics')->insert([
                    'upload_id' => $uploadId,
                    'user_id' => $userId,
                    'event_type' => 'failed',
                    'status' => 'failed',
                    'file_size' => $failureData['fileSize'] ?? null,
                    'file_name' => $failureData['fileName'] ?? null,
                    'chunk_count' => $failureData['chunkCount'] ?? null,
                    'chunks_completed' => $failureData['chunksCompleted'] ?? 0,
                    'percentage_completed' => $failureData['percentageCompleted'] ?? 0,
                    'bytes_uploaded' => $failureData['bytesUploaded'] ?? 0,
                    'upload_duration' => $failureData['duration'] ?? null,
                    'error_message' => $failureData['message'] ?? 'Upload failed',
                    'error_code' => $failureData['code'] ?? 'UPLOAD_ERROR',
                    'error_stage' => $failureData['stage'] ?? 'unknown',
                    'retryable' => $failureData['retryable'] ?? false,
                    'attempt_number' => $failureData['attemptNumber'] ?? 1,
                    'failed_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
            
            $this->updateRealtimeMetrics('failed');
            $this->incrementHourlyMetric('failed_uploads');
            $this->updateCompletionRate();
            
        } catch (\Exception $e) {
            Log::error('Failed to track upload failed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get real-time upload metrics
     */
    public function getRealtimeMetrics(): array
    {
        $cached = Cache::get(self::REALTIME_CACHE_KEY);
        if ($cached) {
            return $cached;
        }
        
        $metrics = $this->calculateRealtimeMetrics();
        Cache::put(self::REALTIME_CACHE_KEY, $metrics, self::CACHE_TTL);
        
        return $metrics;
    }
    
    /**
     * Calculate real-time metrics from database
     */
    private function calculateRealtimeMetrics(): array
    {
        $now = Carbon::now();
        $hourAgo = $now->copy()->subHour();
        $dayAgo = $now->copy()->subDay();
        
        // Active uploads (prepared but not completed/failed in last hour)
        $activeUploads = DB::table('upload_metrics')
            ->where('event_type', 'prepared')
            ->where('created_at', '>=', $hourAgo)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('upload_metrics as um2')
                    ->whereColumn('um2.upload_id', 'upload_metrics.upload_id')
                    ->whereIn('um2.event_type', ['completed', 'failed']);
            })
            ->count();
        
        // Last hour stats
        $lastHourStats = DB::table('upload_metrics')
            ->where('created_at', '>=', $hourAgo)
            ->selectRaw("
                COUNT(CASE WHEN event_type = 'prepared' THEN 1 END) as prepared_count,
                COUNT(CASE WHEN event_type = 'completed' THEN 1 END) as completed_count,
                COUNT(CASE WHEN event_type = 'failed' THEN 1 END) as failed_count,
                AVG(CASE WHEN event_type = 'completed' THEN upload_duration END) as avg_duration,
                SUM(CASE WHEN event_type = 'completed' THEN bytes_uploaded END) as total_bytes,
                AVG(CASE WHEN event_type = 'completed' THEN upload_speed END) as avg_speed
            ")
            ->first();
        
        // Last 24 hours stats
        $dayStats = DB::table('upload_metrics')
            ->where('created_at', '>=', $dayAgo)
            ->selectRaw("
                COUNT(CASE WHEN event_type = 'prepared' THEN 1 END) as prepared_count,
                COUNT(CASE WHEN event_type = 'completed' THEN 1 END) as completed_count,
                COUNT(CASE WHEN event_type = 'failed' THEN 1 END) as failed_count
            ")
            ->first();
        
        // Duration distribution
        $durationDistribution = DB::table('upload_metrics')
            ->where('event_type', 'completed')
            ->where('created_at', '>=', $dayAgo)
            ->whereNotNull('upload_duration')
            ->selectRaw("
                COUNT(CASE WHEN upload_duration <= 10 THEN 1 END) as '0-10s',
                COUNT(CASE WHEN upload_duration > 10 AND upload_duration <= 30 THEN 1 END) as '10-30s',
                COUNT(CASE WHEN upload_duration > 30 AND upload_duration <= 60 THEN 1 END) as '30-60s',
                COUNT(CASE WHEN upload_duration > 60 AND upload_duration <= 120 THEN 1 END) as '1-2m',
                COUNT(CASE WHEN upload_duration > 120 AND upload_duration <= 300 THEN 1 END) as '2-5m',
                COUNT(CASE WHEN upload_duration > 300 THEN 1 END) as '5m+'
            ")
            ->first();
        
        // Error distribution
        $errorDistribution = DB::table('upload_metrics')
            ->where('event_type', 'failed')
            ->where('created_at', '>=', $dayAgo)
            ->groupBy('error_stage')
            ->selectRaw('error_stage, COUNT(*) as count')
            ->pluck('count', 'error_stage')
            ->toArray();
        
        // Completion rate
        $completionRate = 0;
        if ($dayStats->prepared_count > 0) {
            $completionRate = ($dayStats->completed_count / $dayStats->prepared_count) * 100;
        }
        
        return [
            'active_uploads' => $activeUploads,
            'last_hour' => [
                'prepared' => $lastHourStats->prepared_count ?? 0,
                'completed' => $lastHourStats->completed_count ?? 0,
                'failed' => $lastHourStats->failed_count ?? 0,
                'avg_duration' => $lastHourStats->avg_duration ?? 0,
                'total_bytes' => $lastHourStats->total_bytes ?? 0,
                'avg_speed' => $lastHourStats->avg_speed ?? 0,
            ],
            'last_24_hours' => [
                'prepared' => $dayStats->prepared_count ?? 0,
                'completed' => $dayStats->completed_count ?? 0,
                'failed' => $dayStats->failed_count ?? 0,
                'completion_rate' => round($completionRate, 2),
            ],
            'duration_distribution' => (array) $durationDistribution,
            'error_distribution' => $errorDistribution,
            'updated_at' => Carbon::now()->toISOString(),
        ];
    }
    
    /**
     * Get hourly aggregated metrics
     */
    public function getHourlyMetrics(int $hours = 24): array
    {
        $startTime = Carbon::now()->subHours($hours)->startOfHour();
        
        return DB::table('upload_metrics_hourly')
            ->where('hour', '>=', $startTime)
            ->orderBy('hour')
            ->get()
            ->map(function ($row) {
                $row->duration_distribution = json_decode($row->duration_distribution, true);
                $row->size_distribution = json_decode($row->size_distribution, true);
                $row->error_distribution = json_decode($row->error_distribution, true);
                return $row;
            })
            ->toArray();
    }
    
    /**
     * Update real-time metrics cache
     */
    private function updateRealtimeMetrics(string $eventType): void
    {
        $metrics = Cache::get(self::REALTIME_CACHE_KEY, []);
        
        if (!isset($metrics['events'])) {
            $metrics['events'] = [];
        }
        
        // Add event to recent events list
        $metrics['events'][] = [
            'type' => $eventType,
            'timestamp' => Carbon::now()->toISOString(),
        ];
        
        // Keep only last 100 events
        $metrics['events'] = array_slice($metrics['events'], -100);
        
        Cache::put(self::REALTIME_CACHE_KEY, $metrics, self::CACHE_TTL);
    }
    
    /**
     * Increment hourly metric counter
     */
    private function incrementHourlyMetric(string $metric): void
    {
        $hour = Carbon::now()->startOfHour();
        $cacheKey = self::HOURLY_CACHE_KEY . $hour->format('Y-m-d-H');
        
        $data = Cache::get($cacheKey, []);
        $data[$metric] = ($data[$metric] ?? 0) + 1;
        $data['hour'] = $hour->toISOString();
        
        Cache::put($cacheKey, $data, 7200); // 2 hours
    }
    
    /**
     * Update completion rate in hourly metrics
     */
    private function updateCompletionRate(): void
    {
        $hour = Carbon::now()->startOfHour();
        $cacheKey = self::HOURLY_CACHE_KEY . $hour->format('Y-m-d-H');
        
        $data = Cache::get($cacheKey, []);
        $total = ($data['total_uploads'] ?? 0);
        $completed = ($data['completed_uploads'] ?? 0);
        
        if ($total > 0) {
            $data['completion_rate'] = round(($completed / $total) * 100, 2);
        }
        
        Cache::put($cacheKey, $data, 7200);
    }
    
    /**
     * Aggregate hourly metrics (called by scheduled task)
     */
    public function aggregateHourlyMetrics(): void
    {
        $hour = Carbon::now()->subHour()->startOfHour();
        $nextHour = $hour->copy()->addHour();
        
        $stats = DB::table('upload_metrics')
            ->whereBetween('created_at', [$hour, $nextHour])
            ->selectRaw("
                COUNT(CASE WHEN event_type = 'prepared' THEN 1 END) as total_uploads,
                COUNT(CASE WHEN event_type = 'completed' THEN 1 END) as completed_uploads,
                COUNT(CASE WHEN event_type = 'failed' THEN 1 END) as failed_uploads,
                SUM(CASE WHEN event_type = 'completed' THEN bytes_uploaded END) as total_bytes,
                AVG(CASE WHEN event_type = 'completed' THEN upload_duration END) as avg_duration,
                AVG(CASE WHEN event_type = 'completed' THEN upload_speed END) as avg_speed
            ")
            ->first();
        
        $completionRate = 0;
        if ($stats->total_uploads > 0) {
            $completionRate = ($stats->completed_uploads / $stats->total_uploads) * 100;
        }
        
        // Duration distribution
        $durationDist = DB::table('upload_metrics')
            ->where('event_type', 'completed')
            ->whereBetween('created_at', [$hour, $nextHour])
            ->whereNotNull('upload_duration')
            ->selectRaw("
                COUNT(CASE WHEN upload_duration <= 10 THEN 1 END) as '0-10s',
                COUNT(CASE WHEN upload_duration > 10 AND upload_duration <= 30 THEN 1 END) as '10-30s',
                COUNT(CASE WHEN upload_duration > 30 AND upload_duration <= 60 THEN 1 END) as '30-60s',
                COUNT(CASE WHEN upload_duration > 60 AND upload_duration <= 120 THEN 1 END) as '1-2m',
                COUNT(CASE WHEN upload_duration > 120 AND upload_duration <= 300 THEN 1 END) as '2-5m',
                COUNT(CASE WHEN upload_duration > 300 THEN 1 END) as '5m+'
            ")
            ->first();
        
        // Size distribution
        $sizeDist = DB::table('upload_metrics')
            ->where('event_type', 'completed')
            ->whereBetween('created_at', [$hour, $nextHour])
            ->whereNotNull('file_size')
            ->selectRaw("
                COUNT(CASE WHEN file_size <= 10485760 THEN 1 END) as '0-10MB',
                COUNT(CASE WHEN file_size > 10485760 AND file_size <= 52428800 THEN 1 END) as '10-50MB',
                COUNT(CASE WHEN file_size > 52428800 AND file_size <= 104857600 THEN 1 END) as '50-100MB',
                COUNT(CASE WHEN file_size > 104857600 AND file_size <= 524288000 THEN 1 END) as '100-500MB',
                COUNT(CASE WHEN file_size > 524288000 AND file_size <= 1073741824 THEN 1 END) as '500MB-1GB',
                COUNT(CASE WHEN file_size > 1073741824 THEN 1 END) as '1GB+'
            ")
            ->first();
        
        // Error distribution
        $errorDist = DB::table('upload_metrics')
            ->where('event_type', 'failed')
            ->whereBetween('created_at', [$hour, $nextHour])
            ->groupBy('error_stage')
            ->selectRaw('error_stage, COUNT(*) as count')
            ->pluck('count', 'error_stage')
            ->toArray();
        
        DB::table('upload_metrics_hourly')->updateOrInsert(
            ['hour' => $hour],
            [
                'total_uploads' => $stats->total_uploads ?? 0,
                'completed_uploads' => $stats->completed_uploads ?? 0,
                'failed_uploads' => $stats->failed_uploads ?? 0,
                'total_bytes' => $stats->total_bytes ?? 0,
                'avg_duration' => $stats->avg_duration,
                'avg_speed' => $stats->avg_speed,
                'completion_rate' => round($completionRate, 2),
                'duration_distribution' => json_encode((array) $durationDist),
                'size_distribution' => json_encode((array) $sizeDist),
                'error_distribution' => json_encode($errorDist),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
}