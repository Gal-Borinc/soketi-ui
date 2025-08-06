import React, { useEffect, useMemo, useState, useCallback, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';

// Enhanced formatting for different metric types
function fmt(val, type = 'number', digits = 2) {
    if (val == null || val === undefined) return '-';
    const n = Number(val);
    if (Number.isNaN(n)) return '-';

    // Format bytes
    if (type === 'bytes') {
        if (n >= 1073741824) return (n / 1073741824).toFixed(digits) + ' GB';
        if (n >= 1048576) return (n / 1048576).toFixed(digits) + ' MB';
        if (n >= 1024) return (n / 1024).toFixed(digits) + ' KB';
        return n.toFixed(digits) + ' B';
    }

    // Format duration
    if (type === 'duration') {
        if (n >= 3600) return (n / 3600).toFixed(1) + 'h';
        if (n >= 60) return (n / 60).toFixed(1) + 'm';
        return n.toFixed(0) + 's';
    }

    // Format percentage
    if (type === 'percentage') {
        return n.toFixed(digits) + '%';
    }

    // Format rate
    if (type === 'rate') {
        return n.toFixed(digits) + '/min';
    }

    // Format general numbers
    if (n >= 1e9) return (n / 1e9).toFixed(digits) + 'G';
    if (n >= 1e6) return (n / 1e6).toFixed(digits) + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(digits) + 'k';
    return n.toFixed(digits);
}

// Enhanced fetch with better error handling and CSRF token
async function fetchJSON(url, options = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    
    if (!csrfToken) {
        console.error('CSRF token not found in meta tag');
    }
    
    const res = await fetch(url, {
        credentials: 'include',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken || '',
            'X-Requested-With': 'XMLHttpRequest',
            ...options.headers
        },
        ...options
    });

    if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }

    return res.json();
}

// Hook for cached metrics from Soketi scraper
function useCachedMetrics(endpoint, refreshInterval = 5000, enabled = true) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [lastUpdate, setLastUpdate] = useState(null);

    const fetchData = useCallback(async () => {
        if (!enabled) return;

        try {
            setError(null);
            const result = await fetchJSON(endpoint);
            setData(result);
            setLastUpdate(new Date().toISOString());
        } catch (err) {
            setError(err.message);
            console.error('Cached metrics fetch error:', err);
        } finally {
            setLoading(false);
        }
    }, [endpoint, enabled]);

    useEffect(() => {
        fetchData();

        if (enabled && refreshInterval > 0) {
            const interval = setInterval(fetchData, refreshInterval);
            return () => clearInterval(interval);
        }
    }, [fetchData, refreshInterval, enabled]);

    return { data, loading, error, lastUpdate, refetch: fetchData };
}

// Simple Line Chart component
function LineChart({ data, title, color = "#6366F1", error = null }) {
    const width = 500;
    const height = 120;
    const padding = 12;

    const pathD = useMemo(() => {
        if (!data || data.length === 0 || error) return '';

        const maxVal = Math.max(...data.map(d => d.value), 1);
        const minVal = Math.min(...data.map(d => d.value), 0);
        const range = maxVal - minVal || 1;

        return data
            .map((point, i) => {
                const x = padding + (i * (width - 2 * padding)) / (data.length - 1 || 1);
                const y = height - padding - ((point.value - minVal) * (height - 2 * padding)) / range;
                return `${i === 0 ? 'M' : 'L'}${x} ${y}`;
            })
            .join(' ');
    }, [data, error]);

    if (error) {
        return (
            <div className="flex items-center justify-center h-[120px] text-red-500">
                <span className="text-sm">Error loading chart</span>
            </div>
        );
    }

    if (!data || data.length === 0) {
        return (
            <div className="flex items-center justify-center h-[120px] text-gray-400">
                <span className="text-sm">No data available</span>
            </div>
        );
    }

    return (
        <div className="relative">
            <svg width={width} height={height} style={{ display: 'block', width: '100%' }}>
                <defs>
                    <linearGradient id={`gradient-${title}`} x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stopColor={color} stopOpacity="0.3" />
                        <stop offset="100%" stopColor={color} stopOpacity="0.1" />
                    </linearGradient>
                </defs>

                {/* Grid lines */}
                <g stroke="#f1f5f9" strokeWidth="1">
                    {[...Array(5)].map((_, i) => (
                        <line
                            key={i}
                            x1={padding}
                            y1={padding + (i * (height - 2 * padding)) / 4}
                            x2={width - padding}
                            y2={padding + (i * (height - 2 * padding)) / 4}
                        />
                    ))}
                </g>

                {/* Fill area */}
                <path
                    d={pathD + ` L${width - padding} ${height - padding} L${padding} ${height - padding} Z`}
                    fill={`url(#gradient-${title})`}
                />

                {/* Line */}
                <path d={pathD} fill="none" stroke={color} strokeWidth="2" />
            </svg>
        </div>
    );
}

// Status indicator component
function StatusIndicator({ loading, error, lastUpdate, scraperStatus = null }) {
    if (error) {
        return <div className="text-xs text-red-500">● Error</div>;
    }

    if (loading) {
        return <div className="text-xs text-yellow-500">● Loading</div>;
    }

    if (scraperStatus?.is_stale) {
        return <div className="text-xs text-orange-500">● Stale Data</div>;
    }

    return (
        <div className="text-xs text-green-500">
            ● Live
            {lastUpdate && (
                <span className="text-gray-400 ml-2">
                    {new Date(lastUpdate).toLocaleTimeString()}
                </span>
            )}
        </div>
    );
}

// Stat Card component
function StatCard({ title, value, loading, error, type = 'number', lastUpdate = null, scraperStatus = null }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex items-center justify-between">
                <div className="text-sm text-gray-500">{title}</div>
                <StatusIndicator 
                    loading={loading} 
                    error={error} 
                    lastUpdate={lastUpdate}
                    scraperStatus={scraperStatus}
                />
            </div>
            <div className="mt-1 text-2xl font-semibold text-gray-900">
                {error ? 'Error' : loading ? '…' : fmt(value, type)}
            </div>
            {error && (
                <div className="mt-1 text-xs text-red-500">{error}</div>
            )}
        </div>
    );
}

// Chart Card component
function ChartCard({ title, data, loading, error, color = "#6366F1" }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex items-center justify-between mb-2">
                <div className="text-sm font-medium text-gray-700">{title}</div>
                <StatusIndicator loading={loading} error={error} />
            </div>
            <LineChart data={data} title={title} color={color} error={error} />
        </div>
    );
}

// Soketi Health Component
function SoketiHealth({ health, onRefresh }) {
    if (!health) return null;

    const overallStatus = health.overall_healthy ? 'Healthy' : 'Unhealthy';
    const statusColor = health.overall_healthy ? 'text-green-600' : 'text-red-600';

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-lg font-medium">Soketi Server Health</h3>
                <div className={`text-sm font-medium ${statusColor}`}>
                    ● {overallStatus}
                </div>
            </div>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                {health.websocket_api && (
                    <div>
                        <span className="font-medium">WebSocket API:</span>
                        <div className="ml-2">
                            <span className={health.websocket_api.healthy ? 'text-green-600' : 'text-red-600'}>
                                {health.websocket_api.healthy ? '✓' : '✗'} 
                                {health.websocket_api.status_code ? ` HTTP ${health.websocket_api.status_code}` : ''}
                            </span>
                            {health.websocket_api.response_time_ms && (
                                <span className="text-gray-500 ml-2">
                                    ({health.websocket_api.response_time_ms}ms)
                                </span>
                            )}
                        </div>
                    </div>
                )}
                
                {health.metrics_api && (
                    <div>
                        <span className="font-medium">Metrics API:</span>
                        <div className="ml-2">
                            <span className={health.metrics_api.healthy ? 'text-green-600' : 'text-red-600'}>
                                {health.metrics_api.healthy ? '✓' : '✗'} 
                                {health.metrics_api.status_code ? ` HTTP ${health.metrics_api.status_code}` : ''}
                            </span>
                            {health.metrics_api.response_time_ms && (
                                <span className="text-gray-500 ml-2">
                                    ({health.metrics_api.response_time_ms}ms)
                                </span>
                            )}
                        </div>
                    </div>
                )}
            </div>
            
            <div className="flex items-center justify-between mt-4">
                <div className="text-xs text-gray-500">
                    Last checked: {health.checked_at ? new Date(health.checked_at).toLocaleTimeString() : 'Never'}
                </div>
                <button
                    onClick={onRefresh}
                    className="px-3 py-1 text-xs bg-blue-100 text-blue-700 rounded hover:bg-blue-200"
                >
                    Refresh
                </button>
            </div>
        </div>
    );
}

export default function SoketiMetrics(props) {
    const page = usePage();
    const app = (props && props.app) || (page && page.props && page.props.app) || {};
    const config = (props && props.config) || (page && page.props && page.props.config) || {};

    // Soketi metrics endpoints
    const base = `/apps/${app.id}/metrics`;
    const cachedEndpoint = `${base}/cached`;
    const healthEndpoint = `${base}/health`;

    // Real-time settings
    const realtimeRefresh = config.realtime_refresh_interval || 5000; // 5 seconds

    // Get cached metrics (real-time data from scraper)
    const cachedMetrics = useCachedMetrics(cachedEndpoint, realtimeRefresh);

    // Auto-refresh status
    const [isPaused, setIsPaused] = useState(false);
    const [health, setHealth] = useState(null);

    const togglePause = () => setIsPaused(!isPaused);

    // Fetch Soketi health
    const fetchHealth = useCallback(async () => {
        try {
            const healthData = await fetchJSON(healthEndpoint);
            setHealth(healthData);
        } catch (err) {
            console.error('Failed to fetch Soketi health:', err);
        }
    }, [healthEndpoint]);

    // Manual refresh
    const handleRefresh = useCallback(async () => {
        try {
            await fetchJSON(`${base}/refresh`, { method: 'POST' });
            cachedMetrics.refetch();
            fetchHealth();
        } catch (err) {
            console.error('Failed to refresh metrics:', err);
        }
    }, [base, cachedMetrics, fetchHealth]);

    useEffect(() => {
        fetchHealth();
        const interval = setInterval(fetchHealth, 30000); // Check health every 30 seconds
        return () => clearInterval(interval);
    }, [fetchHealth]);

    // Extract values from cached metrics
    const metrics = cachedMetrics.data || {};
    const connectionEvents = metrics.connection_events || {};
    const disconnectionEvents = metrics.disconnection_events || {};
    const uploadMetrics = metrics.upload_metrics || {};
    const scraperStatus = metrics.scraper_status || {};

    return (
        <AuthenticatedLayout
            auth={props.auth}
            errors={props.errors}
            header={
                <div className="flex items-center justify-between">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        Soketi Metrics - {app.name || `App ${app.id}`}
                    </h2>
                    <div className="flex items-center space-x-4">
                        <button
                            onClick={handleRefresh}
                            className="px-3 py-1 rounded text-sm bg-blue-100 text-blue-700 hover:bg-blue-200"
                        >
                            ↻ Refresh
                        </button>
                        <button
                            onClick={togglePause}
                            className={`px-3 py-1 rounded text-sm ${isPaused
                                ? 'bg-green-100 text-green-700 hover:bg-green-200'
                                : 'bg-red-100 text-red-700 hover:bg-red-200'
                                }`}
                        >
                            {isPaused ? '▶ Resume' : '⏸ Pause'}
                        </button>
                        <div className="text-sm text-gray-500">
                            Auto-refresh: {realtimeRefresh / 1000}s
                        </div>
                    </div>
                </div>
            }
        >
            <Head title="Soketi Metrics" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                    {/* Soketi Health Status */}
                    <SoketiHealth health={health} onRefresh={handleRefresh} />

                    {/* Real-time Connection Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <StatCard
                            title="Active Connections"
                            value={metrics.current_connections}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                            lastUpdate={cachedMetrics.lastUpdate}
                            scraperStatus={scraperStatus}
                        />
                        <StatCard
                            title="Active Upload Sessions"
                            value={uploadMetrics.active_uploads || 0}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                            scraperStatus={scraperStatus}
                        />
                        <StatCard
                            title="Data Transferred"
                            value={metrics.bytes_transferred}
                            type="bytes"
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                            scraperStatus={scraperStatus}
                        />
                        <StatCard
                            title="Avg Session Duration"
                            value={uploadMetrics.average_duration_seconds || 0}
                            type="duration"
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                            scraperStatus={scraperStatus}
                        />
                    </div>

                    {/* Connection Activity */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <StatCard
                            title="Connections (Last Hour)"
                            value={connectionEvents.last_hour}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                        />
                        <StatCard
                            title="Connections (Last Minute)"
                            value={connectionEvents.last_minute}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                        />
                        <StatCard
                            title="Disconnections (Last Hour)"
                            value={disconnectionEvents.last_hour}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                        />
                        <StatCard
                            title="Disconnections (Last Minute)"
                            value={disconnectionEvents.last_minute}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                        />
                    </div>

                    {/* Scraper Status Info */}
                    <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <h3 className="text-lg font-medium mb-4">Metrics Status</h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <span className="font-medium">Data Source:</span> Direct Soketi scraping
                            </div>
                            <div>
                                <span className="font-medium">Scraper Status:</span> 
                                <span className={scraperStatus.scraper_working ? 'text-green-600 ml-1' : 'text-red-600 ml-1'}>
                                    {scraperStatus.scraper_working ? '✓ Working' : '✗ Issues detected'}
                                </span>
                            </div>
                            <div>
                                <span className="font-medium">Last Scraped:</span> {scraperStatus.last_scraped || 'Never'}
                            </div>
                            <div>
                                <span className="font-medium">Soketi Endpoint:</span> {config.soketi_endpoint || 'Not configured'}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}