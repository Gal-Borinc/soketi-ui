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

    // Format general numbers
    if (n >= 1e9) return (n / 1e9).toFixed(digits) + 'G';
    if (n >= 1e6) return (n / 1e6).toFixed(digits) + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(digits) + 'k';
    return n.toFixed(digits);
}

// Enhanced fetch with better error handling
async function fetchJSON(url, retries = 2) {
    for (let attempt = 0; attempt <= retries; attempt++) {
        try {
            const res = await fetch(url, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
            });

            if (!res.ok) {
                if (res.status >= 500 && attempt < retries) {
                    await new Promise(resolve => setTimeout(resolve, 1000 * (attempt + 1)));
                    continue;
                }
                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            }

            return res.json();
        } catch (error) {
            if (attempt === retries) {
                throw error;
            }
        }
    }
}

// Hook for webhook-based cached metrics
function useCachedMetrics(endpoint, refreshInterval = 5000, enabled = true) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [lastUpdate, setLastUpdate] = useState(null);
    const abortControllerRef = useRef(null);

    const fetchData = useCallback(async () => {
        if (!enabled) return;

        try {
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
            abortControllerRef.current = new AbortController();

            setError(null);
            const result = await fetchJSON(endpoint);
            setData(result);
            setLastUpdate(new Date().toISOString());
        } catch (err) {
            if (err.name !== 'AbortError') {
                setError(err.message);
                console.error('Cached metrics fetch error:', err);
            }
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

// Hook for time series data
function useTimeSeriesData(endpoint, metric, range = '1h', refreshInterval = 10000, enabled = true) {
    const [series, setSeries] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [lastUpdate, setLastUpdate] = useState(null);

    const fetchData = useCallback(async () => {
        if (!enabled) return;

        try {
            setError(null);
            const url = `${endpoint}?metric=${metric}&range=${range}`;
            const result = await fetchJSON(url);
            setSeries(result.data || []);
            setLastUpdate(new Date().toISOString());
        } catch (err) {
            setError(err.message);
            console.error('Time series fetch error:', err);
        } finally {
            setLoading(false);
        }
    }, [endpoint, metric, range, enabled]);

    useEffect(() => {
        fetchData();

        if (enabled && refreshInterval > 0) {
            const interval = setInterval(fetchData, refreshInterval);
            return () => clearInterval(interval);
        }
    }, [fetchData, refreshInterval, enabled]);

    return { series, loading, error, lastUpdate, refetch: fetchData };
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
function StatusIndicator({ loading, error, lastUpdate }) {
    if (error) {
        return <div className="text-xs text-red-500">● Error</div>;
    }

    if (loading) {
        return <div className="text-xs text-yellow-500">● Loading</div>;
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
function StatCard({ title, value, loading, error, type = 'number', lastUpdate = null }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex items-center justify-between">
                <div className="text-sm text-gray-500">{title}</div>
                <StatusIndicator loading={loading} error={error} lastUpdate={lastUpdate} />
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

export default function WebhookMetrics(props) {
    const page = usePage();
    const app = (props && props.app) || (page && page.props && page.props.app) || {};
    const config = (props && props.config) || (page && page.props && page.props.config) || {};

    // Webhook-based metrics endpoints
    const base = `/apps/${app.id}/metrics`;
    const cachedEndpoint = `${base}/cached`;
    const timeSeriesEndpoint = `${base}/timeseries`;

    // Real-time settings
    const realtimeRefresh = config.realtime_refresh_interval || 5000; // 5 seconds

    // Get cached metrics (real-time data from webhooks)
    const cachedMetrics = useCachedMetrics(cachedEndpoint, realtimeRefresh);

    // Get time series data for charts
    const connectionsChart = useTimeSeriesData(timeSeriesEndpoint, 'connections', '1h', 10000);
    const disconnectionsChart = useTimeSeriesData(timeSeriesEndpoint, 'disconnections', '1h', 10000);

    // Auto-refresh status
    const [isPaused, setIsPaused] = useState(false);
    const togglePause = () => setIsPaused(!isPaused);

    // Extract values from cached metrics
    const metrics = cachedMetrics.data || {};
    const connectionEvents = metrics.connection_events || {};
    const disconnectionEvents = metrics.disconnection_events || {};
    const clientEvents = metrics.client_events || {};

    return (
        <AuthenticatedLayout
            auth={props.auth}
            errors={props.errors}
            header={
                <div className="flex items-center justify-between">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        Webhook Metrics - {app.name || `App ${app.id}`}
                    </h2>
                    <div className="flex items-center space-x-4">
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
                            Refreshes every {realtimeRefresh / 1000}s
                        </div>
                    </div>
                </div>
            }
        >
            <Head title="Webhook Metrics" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                    {/* Real-time Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <StatCard
                            title="Active Connections"
                            value={metrics.current_connections}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                            lastUpdate={cachedMetrics.lastUpdate}
                        />
                        <StatCard
                            title="Total Members"
                            value={metrics.total_members}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                            lastUpdate={cachedMetrics.lastUpdate}
                        />
                        <StatCard
                            title="Data Transferred"
                            value={metrics.bytes_transferred}
                            type="bytes"
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                            lastUpdate={cachedMetrics.lastUpdate}
                        />
                        <StatCard
                            title="Upload Events"
                            value={clientEvents.upload_complete || 0}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                            lastUpdate={cachedMetrics.lastUpdate}
                        />
                    </div>

                    {/* Event Activity */}
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

                    {/* Upload-specific Events */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <StatCard
                            title="Upload Progress Events"
                            value={clientEvents.upload_progress || 0}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                        />
                        <StatCard
                            title="Upload Completions"
                            value={clientEvents.upload_complete || 0}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                        />
                        <StatCard
                            title="Upload Errors"
                            value={clientEvents.upload_error || 0}
                            loading={cachedMetrics.loading}
                            error={cachedMetrics.error}
                        />
                    </div>

                    {/* Charts */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <ChartCard
                            title="Connection Activity (Last Hour)"
                            data={connectionsChart.series}
                            loading={connectionsChart.loading}
                            error={connectionsChart.error}
                            color="#10B981"
                        />
                        <ChartCard
                            title="Disconnection Activity (Last Hour)"
                            data={disconnectionsChart.series}
                            loading={disconnectionsChart.loading}
                            error={disconnectionsChart.error}
                            color="#EF4444"
                        />
                    </div>

                    {/* Status Info */}
                    <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <h3 className="text-lg font-medium mb-4">Webhook Status</h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <span className="font-medium">Data Source:</span> Real-time webhooks from Soketi
                            </div>
                            <div>
                                <span className="font-medium">Last Updated:</span> {metrics.last_updated || 'Never'}
                            </div>
                            <div>
                                <span className="font-medium">Cache TTL:</span> 5 minutes
                            </div>
                            <div>
                                <span className="font-medium">Refresh Rate:</span> Every {realtimeRefresh / 1000} seconds
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
