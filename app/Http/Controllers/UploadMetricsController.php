<?php

namespace App\Http\Controllers;

use App\Services\UploadMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

class UploadMetricsController extends Controller
{
    private UploadMetricsService $uploadMetricsService;

    public function __construct(UploadMetricsService $uploadMetricsService)
    {
        $this->uploadMetricsService = $uploadMetricsService;
    }

    /**
     * Record when an upload is prepared
     */
    public function uploadPrepared(Request $request)
    {
        try {
            $this->uploadMetricsService->recordUploadPrepared(
                $request->input('upload_id'),
                $request->input('user_id'),
                $request->input('metadata', [])
            );

            return Response::json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to record upload prepared', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Record when an upload is completed
     */
    public function uploadCompleted(Request $request)
    {
        try {
            $this->uploadMetricsService->recordUploadCompleted(
                $request->input('upload_id'),
                $request->input('video_id')
            );

            return Response::json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to record upload completed', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Record when an upload fails
     */
    public function uploadFailed(Request $request)
    {
        try {
            $this->uploadMetricsService->recordUploadFailed(
                $request->input('upload_id'),
                $request->input('error')
            );

            return Response::json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to record upload failed', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get upload metrics
     */
    public function getMetrics(Request $request)
    {
        try {
            $metrics = $this->uploadMetricsService->getUploadMetrics();
            return Response::json($metrics);
        } catch (\Exception $e) {
            Log::error('Failed to get upload metrics', [
                'error' => $e->getMessage()
            ]);

            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get active upload sessions
     */
    public function getActiveSessions(Request $request)
    {
        try {
            $sessions = $this->uploadMetricsService->getActiveUploadSessions();
            return Response::json($sessions);
        } catch (\Exception $e) {
            Log::error('Failed to get active upload sessions', [
                'error' => $e->getMessage()
            ]);

            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clean up old tracking data
     */
    public function cleanup(Request $request)
    {
        try {
            $this->uploadMetricsService->cleanupOldTrackingData();
            return Response::json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to cleanup upload tracking data', [
                'error' => $e->getMessage()
            ]);

            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}