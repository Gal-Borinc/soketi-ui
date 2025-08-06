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
        $host = env('SOKETI_HOST', 'soketi');
        // Add http:// prefix if not present
        $this->soketiHost = str_starts_with($host, 'http') ? $host : "http://{$host}";
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
        
        $this->line("Stored processed Soketi metrics in cache");
    }
    
    /**
     * Extract key metrics from raw Prometheus data
     */
    private function extractKeyMetrics(array $prometheusMetrics, ?array $usageMetrics): array
    {
        // Get previous metrics to calculate deltas for counters
        $previousMetrics = Cache::get('soketi:previous_raw_metrics', []);
        
        $processed = [
            'connections' => [
                'current' => 0,
                'total_new' => 0,
                'total_disconnections' => 0,
                'new_since_last_scrape' => 0,
                'disconnections_since_last_scrape' => 0,
            ],
            'data_transfer' => [
                'bytes_received' => 0,
                'bytes_sent' => 0,
                'bytes_received_since_last_scrape' => 0,
                'bytes_sent_since_last_scrape' => 0,
            ],
            'websockets' => [
                'current_connections' => 0,
                'messages_sent' => 0,
                'messages_sent_since_last_scrape' => 0,
            ],
            'system' => [
                'memory_usage' => 0,
                'cpu_usage' => 0,
                'uptime' => 0,
            ],
            'apps' => []
        ];
        
        $currentRawMetrics = [];
        
        // Process Prometheus metrics
        foreach ($prometheusMetrics as $metric) {
            $name = $metric['name'];
            $value = $metric['value'];
            $labels = $metric['labels'];
            
            // Store raw values for next comparison
            $currentRawMetrics[$name] = $value;
            
            switch ($name) {
                case 'soketi_connected':
                    // This is a gauge (current value), not a counter
                    if (isset($labels['port']) && $labels['port'] === '6001') {
                        $processed['connections']['current'] = (int) $value;
                    }
                    break;
                    
                case 'soketi_new_connections_total':
                    // This is a counter - store total and calculate delta
                    if (isset($labels['port']) && $labels['port'] === '6001') {
                        $processed['connections']['total_new'] = (int) $value;
                        $previousValue = $previousMetrics[$name] ?? 0;
                        $processed['connections']['new_since_last_scrape'] = max(0, (int) $value - $previousValue);
                    }
                    break;
                    
                case 'soketi_new_disconnections_total':
                    // This is a counter - store total and calculate delta
                    if (isset($labels['port']) && $labels['port'] === '6001') {
                        $processed['connections']['total_disconnections'] = (int) $value;
                        $previousValue = $previousMetrics[$name] ?? 0;
                        $processed['connections']['disconnections_since_last_scrape'] = max(0, (int) $value - $previousValue);
                    }
                    break;
                    
                case 'soketi_socket_received_bytes':
                    // This is a counter - store total and calculate delta
                    if (isset($labels['port']) && $labels['port'] === '6001') {
                        $processed['data_transfer']['bytes_received'] = (int) $value;
                        $previousValue = $previousMetrics[$name] ?? 0;
                        $processed['data_transfer']['bytes_received_since_last_scrape'] = max(0, (int) $value - $previousValue);
                    }
                    break;
                    
                case 'soketi_socket_transmitted_bytes':
                    // This is a counter - store total and calculate delta
                    if (isset($labels['port']) && $labels['port'] === '6001') {
                        $processed['data_transfer']['bytes_sent'] = (int) $value;
                        $previousValue = $previousMetrics[$name] ?? 0;
                        $processed['data_transfer']['bytes_sent_since_last_scrape'] = max(0, (int) $value - $previousValue);
                    }
                    break;
                    
                case 'soketi_ws_messages_sent_total':
                    // This is a counter - store total and calculate delta
                    if (isset($labels['port']) && $labels['port'] === '6001') {
                        $processed['websockets']['messages_sent'] = (int) $value;
                        $previousValue = $previousMetrics[$name] ?? 0;
                        $processed['websockets']['messages_sent_since_last_scrape'] = max(0, (int) $value - $previousValue);
                    }
                    break;
                    
                // Node.js process metrics
                case 'soketi_nodejs_heap_size_used_bytes':
                    if (isset($labels['port']) && $labels['port'] === '6001') {
                        $processed['system']['memory_usage'] = (int) $value;
                    }
                    break;
                    
                case 'soketi_process_cpu_seconds_total':
                    if (isset($labels['port']) && $labels['port'] === '6001') {
                        // Convert total CPU seconds to a percentage (rough approximation)
                        $processed['system']['cpu_usage'] = $value;
                    }
                    break;
                    
                case 'soketi_process_start_time_seconds':
                    if (isset($labels['port']) && $labels['port'] === '6001') {
                        // Calculate uptime
                        $processed['system']['uptime'] = time() - (int) $value;
                    }
                    break;
            }
        }
        
        // Store current raw metrics for next comparison
        Cache::put('soketi:previous_raw_metrics', $currentRawMetrics, $this->cacheTimeout);
        
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
            'messages_sent' => $metrics['websockets']['messages_sent'],
            'bytes_transferred' => $metrics['data_transfer']['bytes_received'] + $metrics['data_transfer']['bytes_sent'],
            'memory_usage' => $metrics['system']['memory_usage'],
            'timestamp' => $timestamp->timestamp,
            'time_label' => $timestamp->format('H:i')
        ];
        
        Cache::put("soketi:timeseries:minute:{$timeKey}", $minuteData, 7200); // 2 hour TTL
        
        // Store hourly aggregated data  
        $hourlyData = Cache::get("soketi:timeseries:hour:{$hourKey}", [
            'avg_connections' => 0,
            'total_messages' => 0,
            'total_bytes' => 0,
            'avg_memory' => 0,
            'samples' => 0,
            'peak_connections' => 0
        ]);
        
        $currentConnections = $metrics['connections']['current'];
        $hourlyData['avg_connections'] = (($hourlyData['avg_connections'] * $hourlyData['samples']) + $currentConnections) / ($hourlyData['samples'] + 1);
        $hourlyData['peak_connections'] = max($hourlyData['peak_connections'], $currentConnections);
        $hourlyData['total_messages'] = $metrics['websockets']['messages_sent'];
        $hourlyData['total_bytes'] = $metrics['data_transfer']['bytes_received'] + $metrics['data_transfer']['bytes_sent'];
        $hourlyData['avg_memory'] = (($hourlyData['avg_memory'] * $hourlyData['samples']) + $metrics['system']['memory_usage']) / ($hourlyData['samples'] + 1);
        $hourlyData['samples']++;
        $hourlyData['last_updated'] = $timestamp->timestamp;
        
        Cache::put("soketi:timeseries:hour:{$hourKey}", $hourlyData, 86400); // 24 hour TTL
    }
}