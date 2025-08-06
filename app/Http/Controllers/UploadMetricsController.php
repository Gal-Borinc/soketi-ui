<?php

namespace App\Http\Controllers;

use App\Services\UploadMetricsTracker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class UploadMetricsController extends Controller
{
    private UploadMetricsTracker $tracker;
    
    public function __construct(UploadMetricsTracker $tracker)
    {
        $this->tracker = $tracker;
    }
    
    /**
     * Handle upload prepared webhook from main app
     */
    public function uploadPrepared(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'upload_id' => 'required|string',
            'user_id' => 'required|integer',
            'metadata' => 'required|array',
            'metadata.fileSize' => 'nullable|integer',
            'metadata.fileName' => 'nullable|string',
            'metadata.chunkCount' => 'nullable|integer',
            'metadata.chunkSize' => 'nullable|integer',
            'metadata.estimatedDuration' => 'nullable|integer',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $this->tracker->trackUploadPrepared(
            $request->input('upload_id'),
            $request->input('user_id'),
            $request->input('metadata', [])
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Upload prepared event tracked'
        ]);
    }
    
    /**
     * Handle upload completed webhook from main app
     */
    public function uploadCompleted(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'upload_id' => 'required|string',
            'user_id' => 'required|integer',
            'video_id' => 'required|integer',
            'metadata' => 'required|array',
            'metadata.fileSize' => 'nullable|integer',
            'metadata.fileName' => 'nullable|string',
            'metadata.chunkCount' => 'nullable|integer',
            'metadata.uploadDuration' => 'nullable|integer',
            'metadata.processingTime' => 'nullable|integer',
            'metadata.finalFileSize' => 'nullable|integer',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $this->tracker->trackUploadCompleted(
            $request->input('upload_id'),
            $request->input('user_id'),
            $request->input('video_id'),
            $request->input('metadata', [])
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Upload completed event tracked'
        ]);
    }
    
    /**
     * Handle upload failed webhook from main app
     */
    public function uploadFailed(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'upload_id' => 'required|string',
            'user_id' => 'required|integer',
            'failure_data' => 'required|array',
            'failure_data.message' => 'nullable|string',
            'failure_data.code' => 'nullable|string',
            'failure_data.stage' => 'nullable|string',
            'failure_data.retryable' => 'nullable|boolean',
            'failure_data.percentageCompleted' => 'nullable|numeric',
            'failure_data.chunksCompleted' => 'nullable|integer',
            'failure_data.bytesUploaded' => 'nullable|integer',
            'failure_data.fileSize' => 'nullable|integer',
            'failure_data.fileName' => 'nullable|string',
            'failure_data.chunkCount' => 'nullable|integer',
            'failure_data.duration' => 'nullable|integer',
            'failure_data.attemptNumber' => 'nullable|integer',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $this->tracker->trackUploadFailed(
            $request->input('upload_id'),
            $request->input('user_id'),
            $request->input('failure_data', [])
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Upload failed event tracked'
        ]);
    }
    
    /**
     * Get upload metrics
     */
    public function getMetrics(Request $request): JsonResponse
    {
        $hours = $request->input('hours', 24);
        
        return response()->json([
            'success' => true,
            'data' => [
                'realtime' => $this->tracker->getRealtimeMetrics(),
                'hourly' => $this->tracker->getHourlyMetrics($hours)
            ]
        ]);
    }
    
    /**
     * Get active upload sessions
     */
    public function getActiveSessions(Request $request): JsonResponse
    {
        $metrics = $this->tracker->getRealtimeMetrics();
        
        return response()->json([
            'success' => true,
            'data' => [
                'active_count' => $metrics['active_uploads'] ?? 0,
                'last_hour' => $metrics['last_hour'] ?? [],
                'updated_at' => $metrics['updated_at'] ?? null
            ]
        ]);
    }
    
    /**
     * Clean up old metrics data
     */
    public function cleanup(Request $request): JsonResponse
    {
        $daysToKeep = $request->input('days', 7);
        
        // Clean up old upload metrics
        $deleted = \DB::table('upload_metrics')
            ->where('created_at', '<', \Carbon\Carbon::now()->subDays($daysToKeep))
            ->delete();
        
        // Clean up old hourly aggregates
        $deletedHourly = \DB::table('upload_metrics_hourly')
            ->where('hour', '<', \Carbon\Carbon::now()->subDays($daysToKeep))
            ->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Cleanup completed',
            'deleted_records' => $deleted,
            'deleted_hourly' => $deletedHourly
        ]);
    }
}