import React from 'react';
import WebhookMetrics from './WebhookMetrics';

// This component now redirects to WebhookMetrics for the new webhook-based approach

// Enhanced hook for instant queries with automatic refresh and error handling
function useInstant(endpoint, refreshInterval = 5000, enabled = true) {
    const [value, setValue] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [lastUpdate, setLastUpdate] = useState(null);
    const abortControllerRef = useRef(null);

    const fetchData = useCallback(async () => {
        if (!enabled) return;

        // Cancel previous request if still pending
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }

        abortControllerRef.current = new AbortController();

        try {
            setError(null);
            const data = await fetchJSON(endpoint);

            if (data.status === 'success') {
                const resultData = data.data || {};
                if (resultData.resultType === 'vector' && Array.isArray(resultData.result) && resultData.result.length > 0) {
                    const sample = resultData.result[0];
                    const val = Array.isArray(sample.value) ? Number(sample.value[1]) : null;
                    setValue(val);
                } else if (resultData.resultType === 'scalar' && Array.isArray(resultData.result)) {
                    setValue(Number(resultData.result[1]));
                } else {
                    setValue(0);
                }
                setLastUpdate(new Date());
            } else {
                throw new Error(data.error || 'Query failed');
            }
        } catch (err) {
            if (err.name !== 'AbortError') {
                setError(err.message);
                console.error('Instant query error:', err);
            }
        } finally {
            setLoading(false);
        }
    }, [endpoint, enabled]);

    useEffect(() => {
        fetchData();

        if (refreshInterval > 0 && enabled) {
            const interval = setInterval(fetchData, refreshInterval);
            return () => clearInterval(interval);
        }

        return () => {
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
        };
    }, [fetchData, refreshInterval, enabled]);

    return { value, loading, error, lastUpdate, refetch: fetchData };
}

// Enhanced hook for range queries with better performance
function useRange(endpoint, refreshInterval = 30000, enabled = true) {
    const [series, setSeries] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [lastUpdate, setLastUpdate] = useState(null);
    const abortControllerRef = useRef(null);

    const fetchData = useCallback(async () => {
        if (!enabled) return;

        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }

        abortControllerRef.current = new AbortController();

        try {
            setError(null);
            const data = await fetchJSON(endpoint);

            if (data.status === 'success') {
                const resultData = data.data || {};
                if (resultData.resultType === 'matrix' && Array.isArray(resultData.result) && resultData.result.length > 0) {
                    const samples = resultData.result[0].values || [];
                    setSeries(samples.map((v) => [Number(v[0]) * 1000, Number(v[1])]));
                } else {
                    setSeries([]);
                }
                setLastUpdate(new Date());
            } else {
                throw new Error(data.error || 'Range query failed');
            }
        } catch (err) {
            if (err.name !== 'AbortError') {
                setError(err.message);
                console.error('Range query error:', err);
            }
        } finally {
            setLoading(false);
        }
    }, [endpoint, enabled]);

    useEffect(() => {
        fetchData();

        if (refreshInterval > 0 && enabled) {
            const interval = setInterval(fetchData, refreshInterval);
            return () => clearInterval(interval);
        }

        return () => {
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
        };
    }, [fetchData, refreshInterval, enabled]);

    return { series, loading, error, lastUpdate, refetch: fetchData };
}

// Enhanced Line component with better styling and error handling
function Line({ data, title, color = "#6366F1", error = null }) {
    const width = 500;
    const height = 120;
    const padding = 12;

    const pathD = useMemo(() => {
        if (error || !data || data.length === 0) return '';

        try {
            const xs = data.map((d) => d[0]);
            const ys = data.map((d) => d[1]);
            const xmin = Math.min(...xs);
            const xmax = Math.max(...xs);
            const ymin = Math.min(0, Math.min(...ys)); // Include 0 in range
            const ymax = Math.max(...ys);
            const xr = xmax - xmin || 1;
            const yr = ymax - ymin || 1;

            const scaleX = (x) => padding + ((x - xmin) / xr) * (width - 2 * padding);
            const scaleY = (y) => height - padding - ((y - ymin) / yr) * (height - 2 * padding);

            let d = '';
            data.forEach(([x, y], i) => {
                const X = scaleX(x);
                const Y = scaleY(y);
                d += (i === 0 ? 'M' : 'L') + X + ' ' + Y + ' ';
            });
            return d;
        } catch (err) {
            console.error('Error generating chart path:', err);
            return '';
        }
    }, [data, error]);

    if (error) {
        return (
            <div className="flex items-center justify-center h-[120px] text-red-500 text-sm">
                Error: {error}
            </div>
        );
    }

    if (!data || data.length === 0) {
        return (
            <div className="flex items-center justify-center h-[120px] text-gray-400 text-sm">
                No data available
            </div>
        );
    }

    return (
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
            {/* Area fill */}
            <path
                d={pathD + `L${width - padding} ${height - padding}L${padding} ${height - padding}Z`}
                fill={`url(#gradient-${title})`}
            />
            {/* Line */}
            <path d={pathD} fill="none" stroke={color} strokeWidth="2" />
        </svg>
    );
}

// Enhanced StatCard with status indicators and trend information
function StatCard({ title, value, loading, error, type = 'number', trend = null, lastUpdate = null }) {
    const statusColor = error ? 'text-red-500' : loading ? 'text-yellow-500' : 'text-green-500';
    const statusText = error ? 'Error' : loading ? 'Loading' : 'Live';

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex items-center justify-between">
                <div className="text-sm text-gray-500">{title}</div>
                <div className={`text-xs ${statusColor}`}>● {statusText}</div>
            </div>
            <div className="mt-1 text-2xl font-semibold text-gray-900">
                {error ? 'Error' : loading ? '…' : fmt(value, type)}
            </div>
            {lastUpdate && !loading && !error && (
                <div className="mt-1 text-xs text-gray-400">
                    Updated {new Date(lastUpdate).toLocaleTimeString()}
                </div>
            )}
            {error && (
                <div className="mt-1 text-xs text-red-500">
                    {error}
                </div>
            )}
        </div>
    );
}

// Enhanced Chart Card with better layout and error handling
function ChartCard({ title, data, loading, error, color = "#6366F1" }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex items-center justify-between mb-2">
                <div className="text-sm font-medium text-gray-700">{title}</div>
                <div className="flex items-center space-x-2">
                    {loading && <div className="w-4 h-4 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>}
                    <div className={`text-xs ${error ? 'text-red-500' : 'text-green-500'}`}>
                        {error ? '● Error' : '● Live'}
                    </div>
                </div>
            </div>
            <Line data={data} title={title} color={color} error={error} />
        </div>
    );
}

export default function Metrics(props) {
    // Safely obtain app and config from props or from Inertia page props
    const page = usePage();
    const app = (props && props.app) || (page && page.props && page.props.app) || {};
    const config = (props && props.config) || (page && page.props && page.props.config) || {};

    // Real-time settings with improved performance
    const realtimeRefresh = config.realtime_refresh_interval || 5000; // 5 seconds
    const trendingRefresh = config.trending_refresh_interval || 30000; // 30 seconds

    // Time range: optimize for real-time monitoring
    const end = Math.floor(Date.now() / 1000);
    const start = end - 10 * 60; // last 10 minutes for better visualization
    const step = 15; // 15 seconds for better resolution

    // Endpoints
    const base = `/apps/${app.id}/metrics`;

    // Real-time instant metrics (high refresh rate)
    const connInstant = useInstant(`${base}/query?query=${encodeURIComponent('soketi_connected')}`, realtimeRefresh);

    // Real-time rate metrics using raw queries first (fallback from pre-calculated)
    const newConnRate = useRange(
        `${base}/query_range?query=${encodeURIComponent('irate(soketi_new_connections_total[1m])')}&start=${start}&end=${end}&step=${step}s`,
        realtimeRefresh
    );
    const disconnRate = useRange(
        `${base}/query_range?query=${encodeURIComponent('irate(soketi_new_disconnections_total[1m])')}&start=${start}&end=${end}&step=${step}s`,
        realtimeRefresh
    );

    // Bandwidth metrics with raw queries
    const rxRate = useRange(
        `${base}/query_range?query=${encodeURIComponent('irate(soketi_socket_received_bytes[1m])')}&start=${start}&end=${end}&step=${step}s`,
        realtimeRefresh
    );
    const txRate = useRange(
        `${base}/query_range?query=${encodeURIComponent('irate(soketi_socket_transmitted_bytes[1m])')}&start=${start}&end=${end}&step=${step}s`,
        realtimeRefresh
    );

    // Trending data using raw queries (lower refresh rate)
    const newConnTrend = useRange(
        `${base}/query_range?query=${encodeURIComponent('rate(soketi_new_connections_total[5m])')}&start=${start}&end=${end}&step=${step}s`,
        trendingRefresh
    );
    const disconnTrend = useRange(
        `${base}/query_range?query=${encodeURIComponent('rate(soketi_new_disconnections_total[5m])')}&start=${start}&end=${end}&step=${step}s`,
        trendingRefresh
    );

    // Counter increases using raw queries
    const newConnIncrease = useRange(
        `${base}/query_range?query=${encodeURIComponent('increase(soketi_new_connections_total[5m])')}&start=${start}&end=${end}&step=${step}s`,
        trendingRefresh
    );
    const disconnIncrease = useRange(
        `${base}/query_range?query=${encodeURIComponent('increase(soketi_new_disconnections_total[5m])')}&start=${start}&end=${end}&step=${step}s`,
        trendingRefresh
    );

    // Health metrics calculated from basic queries
    const netConnRate = useRange(
        `${base}/query_range?query=${encodeURIComponent('irate(soketi_new_connections_total[1m]) - irate(soketi_new_disconnections_total[1m])')}&start=${start}&end=${end}&step=${step}s`,
        realtimeRefresh
    );
    const churnRate = useRange(
        `${base}/query_range?query=${encodeURIComponent('irate(soketi_new_connections_total[1m]) + irate(soketi_new_disconnections_total[1m])')}&start=${start}&end=${end}&step=${step}s`,
        realtimeRefresh
    );    // Helper function to get last value from series
    const lastOf = (queryResult) => {
        if (queryResult.error) return null;
        return queryResult.series.length ? queryResult.series[queryResult.series.length - 1][1] : null;
    };

    // Auto-refresh status
    const [isPaused, setIsPaused] = useState(false);

    const togglePause = () => setIsPaused(!isPaused);

    // Override refresh intervals when paused
    const effectiveRealtimeRefresh = isPaused ? 0 : realtimeRefresh;
    const effectiveTrendingRefresh = isPaused ? 0 : trendingRefresh;

    return (
        <AuthenticatedLayout
            auth={page && page.props ? page.props.auth : undefined}
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        {(app && (app.name || app.id)) ? (app.name || app.id) : 'App'} • Real-time Metrics
                    </h2>
                    <div className="flex items-center space-x-4">
                        <button
                            onClick={togglePause}
                            className={`px-3 py-1 rounded text-sm font-medium ${isPaused
                                ? 'bg-red-100 text-red-800 hover:bg-red-200'
                                : 'bg-green-100 text-green-800 hover:bg-green-200'
                                }`}
                        >
                            {isPaused ? '▶ Resume' : '⏸ Pause'} Auto-refresh
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="Real-time Metrics" />
            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    {/* Real-time Overview Cards */}
                    <div>
                        <h3 className="text-lg font-medium text-gray-900 mb-4">Real-time Overview</h3>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-3 lg:grid-cols-6">
                            <StatCard
                                title="Current Connections"
                                value={connInstant.value}
                                loading={connInstant.loading}
                                error={connInstant.error}
                                lastUpdate={connInstant.lastUpdate}
                            />
                            <StatCard
                                title="New Connections/sec"
                                value={lastOf(newConnRate)}
                                loading={newConnRate.loading}
                                error={newConnRate.error}
                                lastUpdate={newConnRate.lastUpdate}
                            />
                            <StatCard
                                title="Disconnections/sec"
                                value={lastOf(disconnRate)}
                                loading={disconnRate.loading}
                                error={disconnRate.error}
                                lastUpdate={disconnRate.lastUpdate}
                            />
                            <StatCard
                                title="Net Rate/sec"
                                value={lastOf(netConnRate)}
                                loading={netConnRate.loading}
                                error={netConnRate.error}
                                lastUpdate={netConnRate.lastUpdate}
                            />
                            <StatCard
                                title="RX Rate"
                                value={lastOf(rxRate)}
                                loading={rxRate.loading}
                                error={rxRate.error}
                                type="bytes"
                                lastUpdate={rxRate.lastUpdate}
                            />
                            <StatCard
                                title="TX Rate"
                                value={lastOf(txRate)}
                                loading={txRate.loading}
                                error={txRate.error}
                                type="bytes"
                                lastUpdate={txRate.lastUpdate}
                            />
                        </div>
                    </div>

                    {/* Real-time Charts */}
                    <div>
                        <h3 className="text-lg font-medium text-gray-900 mb-4">Real-time Activity (1m windows, pre-calculated)</h3>
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <ChartCard
                                title="Connection Rate (per second)"
                                data={newConnRate.series}
                                loading={newConnRate.loading}
                                error={newConnRate.error}
                                color="#10b981"
                            />
                            <ChartCard
                                title="Disconnection Rate (per second)"
                                data={disconnRate.series}
                                loading={disconnRate.loading}
                                error={disconnRate.error}
                                color="#ef4444"
                            />
                            <ChartCard
                                title="Net Connection Rate (new - disconn)"
                                data={netConnRate.series}
                                loading={netConnRate.loading}
                                error={netConnRate.error}
                                color="#06b6d4"
                            />
                            <ChartCard
                                title="Connection Churn (new + disconn)"
                                data={churnRate.series}
                                loading={churnRate.loading}
                                error={churnRate.error}
                                color="#f59e0b"
                            />
                            <ChartCard
                                title="Received Bytes/sec"
                                data={rxRate.series}
                                loading={rxRate.loading}
                                error={rxRate.error}
                                color="#3b82f6"
                            />
                            <ChartCard
                                title="Transmitted Bytes/sec"
                                data={txRate.series}
                                loading={txRate.loading}
                                error={txRate.error}
                                color="#8b5cf6"
                            />
                        </div>
                    </div>

                    {/* Trending Charts */}
                    <div>
                        <h3 className="text-lg font-medium text-gray-900 mb-4">Trending Data (5m windows, pre-calculated)</h3>
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <ChartCard
                                title="Connection Trend (5m average rate)"
                                data={newConnTrend.series}
                                loading={newConnTrend.loading}
                                error={newConnTrend.error}
                                color="#10b981"
                            />
                            <ChartCard
                                title="Disconnection Trend (5m average rate)"
                                data={disconnTrend.series}
                                loading={disconnTrend.loading}
                                error={disconnTrend.error}
                                color="#ef4444"
                            />
                            <ChartCard
                                title="Total New Connections (5m increase)"
                                data={newConnIncrease.series}
                                loading={newConnIncrease.loading}
                                error={newConnIncrease.error}
                                color="#06b6d4"
                            />
                            <ChartCard
                                title="Total Disconnections (5m increase)"
                                data={disconnIncrease.series}
                                loading={disconnIncrease.loading}
                                error={disconnIncrease.error}
                                color="#f59e0b"
                            />
                        </div>
                    </div>

                    {/* Status Footer */}
                    <div className="text-center text-sm text-gray-500">
                        <p>
                            Real-time data refreshes every {realtimeRefresh / 1000}s •
                            Trending data refreshes every {trendingRefresh / 1000}s
                            {isPaused && ' • Auto-refresh is paused'}
                        </p>
                        <p className="mt-1 text-xs">
                            Using pre-calculated recording rules for optimal performance
                        </p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}