<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\UploadMetricsTracker;
use Illuminate\Support\Facades\Log;

class AggregateHourlyMetrics extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'metrics:aggregate-hourly {--force : Force aggregation even if already done}';

    /**
     * The console command description.
     */
    protected $description = 'Aggregate upload metrics into hourly buckets for performance';

    private UploadMetricsTracker $tracker;

    public function __construct(UploadMetricsTracker $tracker)
    {
        parent::__construct();
        $this->tracker = $tracker;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);
        
        try {
            $this->info('Starting hourly metrics aggregation...');
            
            // Run aggregation
            $this->tracker->aggregateHourlyMetrics();
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("Hourly metrics aggregation completed in {$duration}ms");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Hourly aggregation failed: " . $e->getMessage());
            Log::error('AggregateHourlyMetrics command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return Command::FAILURE;
        }
    }
}