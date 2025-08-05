<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Jobs\ProcessScrapedMetrics;

class ScrapeMetrics extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'soketi:scrape-metrics {--app-id=* : Specific app IDs to scrape metrics for}';

    /**
     * The console command description.
     */
    protected $description = 'Scrape metrics from Soketi server and store in cache';

    /**
     * Soketi metrics endpoint configuration
     */
    private string $soketiHost;
    private int $metricsPort;
    private int $cacheTimeout = 600; // 10 minutes

    public function __construct()
    {
        parent::__construct();
        
        // Get Soketi configuration from environment or defaults
        $this->soketiHost = env('SOKETI_HOST', 'http://soketi');
        $this->metricsPort = env('SOKETI_METRICS_PORT', 9601);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);
        $timestamp = Carbon::now();
        
        try {
            $this->info("Starting Soketi metrics scraping at {$timestamp->toISOString()}");
            
            // Scrape Prometheus metrics
            $prometheusMetrics = $this->scrapePrometheusMetrics();
            if (!$prometheusMetrics) {
                $this->error('Failed to scrape Prometheus metrics');
                return Command::FAILURE;
            }
            
            // Scrape usage endpoint
            $usageMetrics = $this->scrapeUsageMetrics();
            if (!$usageMetrics) {
                $this->warn('Failed to scrape usage metrics (non-critical)');
            }
            
            // Process and store metrics
            $this->processMetrics($prometheusMetrics, $usageMetrics, $timestamp);
            
            // Dispatch job to process metrics and derive upload insights
            ProcessScrapedMetrics::dispatch();
            $this->line("Dispatched ProcessScrapedMetrics job");
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("Metrics scraping completed in {$duration}ms");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Metrics scraping failed: " . $e->getMessage());
            Log::error('ScrapeMetrics command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return Command::FAILURE;
        }
    }
    
    /**
     * Scrape Prometheus format metrics from /metrics endpoint
     */
    private function scrapePrometheusMetrics(): ?array
    {
        try {
            $url = "{$this->soketiHost}:{$this->metricsPort}/metrics";
            $this->line("Scraping Prometheus metrics from: {$url}");
            
            $response = Http::timeout(10)->get($url);
            
            if (!$response->successful()) {
                $this->error("HTTP error {$response->status()} from metrics endpoint");
                return null;
            }
            
            $content = $response->body();
            return $this->parsePrometheusMetrics($content);
            
        } catch (\Exception $e) {
            $this->error("Failed to fetch Prometheus metrics: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Scrape JSON usage metrics from /usage endpoint
     */
    private function scrapeUsageMetrics(): ?array
    {
        try {
            $url = "{$this->soketiHost}:{$this->metricsPort}/usage";
            $this->line("Scraping usage metrics from: {$url}");
            
            $response = Http::timeout(10)->get($url);
            
            if (!$response->successful()) {
                $this->warn("HTTP error {$response->status()} from usage endpoint");
                return null;
            }
            
            return $response->json();
            
        } catch (\Exception $e) {
            $this->warn("Failed to fetch usage metrics: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Parse Prometheus format metrics into structured array
     */
    private function parsePrometheusMetrics(string $content): array
    {
        $metrics = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            
            // Parse metric line: metric_name{labels} value timestamp
            if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*(?:\{[^}]*\})?) ([0-9.-]+)(?:\s+([0-9]+))?$/', $line, $matches)) {
                $metricName = $matches[1];
                $value = (float) $matches[2];
                $timestamp = isset($matches[3]) ? (int) $matches[3] : null;
                
                // Extract labels if present
                $labels = [];
                if (preg_match('/^([^{]+)\{([^}]*)\}$/', $metricName, $labelMatches)) {
                    $metricName = $labelMatches[1];
                    $labelString = $labelMatches[2];
                    
                    // Parse labels: key1="value1",key2="value2"
                    if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)="([^"]*)"/', $labelString, $allLabels)) {
                        $labels = array_combine($allLabels[1], $allLabels[2]);
                    }
                }
                
                $metrics[] = [
                    'name' => $metricName,
                    'value' => $value,
                    'labels' => $labels,
                    'timestamp' => $timestamp
                ];
            }
        }
        
        $this->line("Parsed " . count($metrics) . " Prometheus metrics");
        return $metrics;
    }
    
    /**
     * Process and store metrics in cache
     */
    private function processMetrics(array $prometheusMetrics, ?array $usageMetrics, Carbon $timestamp): void
    {
        // Store raw metrics
        Cache::put('soketi:raw_prometheus_metrics', $prometheusMetrics, $this->cacheTimeout);
        if ($usageMetrics) {
            Cache::put('soketi:raw_usage_metrics', $usageMetrics, $this->cacheTimeout);
        }
        
        // Process key metrics for easy access
        $processedMetrics = $this->extractKeyMetrics($prometheusMetrics, $usageMetrics);
        
        // Store processed metrics with timestamp
        $processedMetrics['scraped_at'] = $timestamp->toISOString();
        $processedMetrics['scraped_timestamp'] = $timestamp->timestamp;
        
        Cache::put('soketi:processed_metrics', $processedMetrics, $this->cacheTimeout);
        
        // Store time-series data for charts
        $this->storeTimeSeriesData($processedMetrics, $timestamp);
        
        $this->line("Stored processed metrics in cache");
    }
    
    /**
     * Extract key metrics from raw Prometheus data
     */
    private function extractKeyMetrics(array $prometheusMetrics, ?array $usageMetrics): array
    {
        $processed = [
            'connections' => [
                'current' => 0,
                'total_new' => 0,
                'total_disconnections' => 0,
            ],
            'data_transfer' => [
                'bytes_received' => 0,
                'bytes_sent' => 0,
            ],
            'websockets' => [
                'current_connections' => 0,
                'messages_sent' => 0,
            ],
            'system' => [
                'memory_usage' => 0,
                'cpu_usage' => 0,
                'uptime' => 0,
            ],
            'apps' => []
        ];
        
        // Process Prometheus metrics
        foreach ($prometheusMetrics as $metric) {
            $name = $metric['name'];
            $value = $metric['value'];
            $labels = $metric['labels'];
            
            switch ($name) {
                case 'soketi_6001_connected':
                    $processed['connections']['current'] = (int) $value;
                    break;
                    
                case 'soketi_6001_new_connections_total':
                    $processed['connections']['total_new'] = (int) $value;
                    break;
                    
                case 'soketi_6001_new_disconnections_total':
                    $processed['connections']['total_disconnections'] = (int) $value;
                    break;
                    
                case 'soketi_6001_socket_received_bytes':
                    $processed['data_transfer']['bytes_received'] = (int) $value;
                    break;
                    
                case 'soketi_6001_socket_sent_bytes':
                    $processed['data_transfer']['bytes_sent'] = (int) $value;
                    break;
                    
                case 'soketi_ws_messages_sent_total':
                    $processed['websockets']['messages_sent'] = (int) $value;
                    break;
                    
                // Node.js process metrics
                case 'nodejs_heap_size_used_bytes':
                    $processed['system']['memory_usage'] = (int) $value;
                    break;
                    
                case 'process_cpu_usage_percentage':
                    $processed['system']['cpu_usage'] = $value;
                    break;
                    
                case 'nodejs_version_info':
                    // Store Node.js version info if needed
                    break;
            }
        }
        
        // Include usage metrics if available
        if ($usageMetrics) {
            $processed['usage'] = $usageMetrics;
        }
        
        return $processed;
    }
    
    /**
     * Store time-series data for charts
     */
    private function storeTimeSeriesData(array $metrics, Carbon $timestamp): void
    {
        $timeKey = $timestamp->format('Y-m-d-H-i'); // minute precision
        $hourKey = $timestamp->format('Y-m-d-H'); // hour precision
        
        // Store minute-level data (for real-time charts)
        $minuteData = [
            'connections' => $metrics['connections']['current'],
            'new_connections' => $metrics['connections']['total_new'],
            'disconnections' => $metrics['connections']['total_disconnections'],
            'bytes_transferred' => $metrics['data_transfer']['bytes_received'] + $metrics['data_transfer']['bytes_sent'],
            'timestamp' => $timestamp->timestamp
        ];
        
        Cache::put("soketi:timeseries:minute:{$timeKey}", $minuteData, 3600); // 1 hour TTL
        
        // Store hourly aggregated data
        $hourlyData = Cache::get("soketi:timeseries:hour:{$hourKey}", [
            'connections_sum' => 0,
            'connections_count' => 0,
            'bytes_sum' => 0,
            'samples' => 0
        ]);
        
        $hourlyData['connections_sum'] += $metrics['connections']['current'];
        $hourlyData['connections_count']++;
        $hourlyData['bytes_sum'] += $metrics['data_transfer']['bytes_received'] + $metrics['data_transfer']['bytes_sent'];
        $hourlyData['samples']++;
        $hourlyData['last_updated'] = $timestamp->timestamp;
        
        Cache::put("soketi:timeseries:hour:{$hourKey}", $hourlyData, 86400); // 24 hour TTL
    }
}