import React, { useEffect, useState, useCallback, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import {
    LineChart, Line, AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, Legend,
    ResponsiveContainer, BarChart, Bar, PieChart, Pie, Cell, ComposedChart
} from 'recharts';
import { format, subHours, startOfHour } from 'date-fns';

// Professional color palette
const COLORS = {
    primary: '#3B82F6',
    success: '#10B981',
    warning: '#F59E0B',
    error: '#EF4444',
    secondary: '#6B7280',
    accent: '#8B5CF6'
};

// Format values with appropriate units
function formatValue(val, type = 'number', precision = 2) {
    if (val == null || val === undefined) return '-';
    const n = Number(val);
    if (Number.isNaN(n)) return '-';

    switch (type) {
        case 'bytes':
            if (n >= 1e9) return `${(n / 1e9).toFixed(precision)} GB`;
            if (n >= 1e6) return `${(n / 1e6).toFixed(precision)} MB`;
            if (n >= 1e3) return `${(n / 1e3).toFixed(precision)} KB`;
            return `${n.toFixed(precision)} B`;
        
        case 'duration':
            if (n >= 3600) return `${(n / 3600).toFixed(1)}h`;
            if (n >= 60) return `${(n / 60).toFixed(1)}m`;
            return `${n.toFixed(0)}s`;
        
        case 'percentage':
            return `${n.toFixed(precision)}%`;
        
        case 'rate':
            return `${n.toFixed(precision)}/min`;
        
        case 'speed':
            if (n >= 1e6) return `${(n / 1e6).toFixed(precision)} MB/s`;
            if (n >= 1e3) return `${(n / 1e3).toFixed(precision)} KB/s`;
            return `${n.toFixed(precision)} B/s`;
        
        default:
            if (n >= 1e9) return `${(n / 1e9).toFixed(precision)}B`;
            if (n >= 1e6) return `${(n / 1e6).toFixed(precision)}M`;
            if (n >= 1e3) return `${(n / 1e3).toFixed(precision)}K`;
            return n.toFixed(precision);
    }
}

// Enhanced fetch with error handling
async function fetchJSON(url, options = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    
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

// Enhanced metrics hook with real-time updates
function useMetricsData(endpoint, refreshInterval = 5000, enabled = true) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [lastUpdate, setLastUpdate] = useState(null);
    const [isLive, setIsLive] = useState(true);

    const fetchData = useCallback(async () => {
        if (!enabled || !isLive) return;

        try {
            setError(null);
            const result = await fetchJSON(endpoint);
            setData(result);
            setLastUpdate(new Date());
        } catch (err) {
            setError(err.message);
            console.error('Metrics fetch error:', err);
        } finally {
            setLoading(false);
        }
    }, [endpoint, enabled, isLive]);

    useEffect(() => {
        fetchData();
        
        if (enabled && isLive && refreshInterval > 0) {
            const interval = setInterval(fetchData, refreshInterval);
            return () => clearInterval(interval);
        }
    }, [fetchData, refreshInterval, enabled, isLive]);

    const toggleLive = useCallback(() => {
        setIsLive(prev => !prev);
    }, []);

    return { data, loading, error, lastUpdate, refetch: fetchData, isLive, toggleLive };
}

// Professional metric card component
function MetricCard({ title, value, type = 'number', change = null, icon = null, color = COLORS.primary, subtitle = null }) {
    const changeColor = change > 0 ? COLORS.success : change < 0 ? COLORS.error : COLORS.secondary;
    
    return (
        <div className="bg-white rounded-xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
            <div className="flex items-center justify-between mb-2">
                <h3 className="text-sm font-medium text-gray-500 uppercase tracking-wide">{title}</h3>
                {icon && <span className="text-2xl" style={{ color }}>{icon}</span>}
            </div>
            <div className="flex items-baseline space-x-2">
                <span className="text-3xl font-bold text-gray-900">
                    {formatValue(value, type)}
                </span>
                {change !== null && (
                    <span className="text-sm font-medium" style={{ color: changeColor }}>
                        {change > 0 ? '+' : ''}{formatValue(change, type)}
                    </span>
                )}
            </div>
            {subtitle && (
                <p className="text-xs text-gray-500 mt-1">{subtitle}</p>
            )}
        </div>
    );
}

// Real-time status indicator
function StatusIndicator({ loading, error, lastUpdate, isLive, onToggle }) {
    if (error) {
        return <div className="flex items-center space-x-2 text-red-500 text-sm">
            <div className="w-2 h-2 bg-red-500 rounded-full"></div>
            <span>Error</span>
        </div>;
    }

    if (loading) {
        return <div className="flex items-center space-x-2 text-yellow-500 text-sm">
            <div className="w-2 h-2 bg-yellow-500 rounded-full animate-pulse"></div>
            <span>Loading...</span>
        </div>;
    }

    return (
        <div className="flex items-center space-x-3">
            <div className={`flex items-center space-x-2 text-sm ${isLive ? 'text-green-500' : 'text-gray-500'}`}>
                <div className={`w-2 h-2 rounded-full ${isLive ? 'bg-green-500 animate-pulse' : 'bg-gray-400'}`}></div>
                <span>{isLive ? 'Live' : 'Paused'}</span>
            </div>
            {lastUpdate && (
                <span className="text-xs text-gray-500">
                    {format(lastUpdate, 'HH:mm:ss')}
                </span>
            )}
            <button
                onClick={onToggle}
                className={`px-2 py-1 rounded text-xs font-medium transition-colors ${
                    isLive 
                        ? 'bg-red-100 text-red-700 hover:bg-red-200' 
                        : 'bg-green-100 text-green-700 hover:bg-green-200'
                }`}
            >
                {isLive ? 'Pause' : 'Resume'}
            </button>
        </div>
    );
}

// Chart container component
function ChartCard({ title, children, height = 300, className = '' }) {
    return (
        <div className={`bg-white rounded-xl p-6 shadow-sm border border-gray-100 ${className}`}>
            <h3 className="text-lg font-semibold text-gray-900 mb-4">{title}</h3>
            <div style={{ height }}>
                {children}
            </div>
        </div>
    );
}

// Upload status pie chart
function UploadStatusChart({ data }) {
    const chartData = [
        { name: 'Completed', value: data.completed || 0, color: COLORS.success },
        { name: 'Failed', value: data.failed || 0, color: COLORS.error },
        { name: 'Active', value: data.active || 0, color: COLORS.warning },
    ].filter(item => item.value > 0);

    if (chartData.length === 0) {
        return (
            <div className="flex items-center justify-center h-full text-gray-500">
                <span>No upload data available</span>
            </div>
        );
    }

    return (
        <ResponsiveContainer width="100%" height="100%">
            <PieChart>
                <Pie
                    data={chartData}
                    cx="50%"
                    cy="50%"
                    outerRadius={80}
                    dataKey="value"
                    label={({ name, value }) => `${name}: ${value}`}
                >
                    {chartData.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={entry.color} />
                    ))}
                </Pie>
                <Tooltip />
            </PieChart>
        </ResponsiveContainer>
    );
}

// Server health dashboard
function ServerHealth({ health, onRefresh }) {
    if (!health) return null;

    const services = [
        {
            name: 'WebSocket API',
            status: health.websocket_api?.healthy,
            details: health.websocket_api
        },
        {
            name: 'Metrics API',
            status: health.metrics_api?.healthy,
            details: health.metrics_api
        }
    ];

    return (
        <div className="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
            <div className="flex items-center justify-between mb-6">
                <h2 className="text-xl font-semibold text-gray-900">System Health</h2>
                <div className={`flex items-center space-x-2 px-3 py-1 rounded-full text-sm font-medium ${
                    health.overall_healthy 
                        ? 'bg-green-100 text-green-800' 
                        : 'bg-red-100 text-red-800'
                }`}>
                    <div className={`w-2 h-2 rounded-full ${
                        health.overall_healthy ? 'bg-green-500' : 'bg-red-500'
                    }`}></div>
                    <span>{health.overall_healthy ? 'All Systems Operational' : 'Service Issues Detected'}</span>
                </div>
            </div>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                {services.map((service, index) => (
                    <div key={index} className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                        <div>
                            <h4 className="font-medium text-gray-900">{service.name}</h4>
                            {service.details?.status_code && (
                                <p className="text-sm text-gray-500">HTTP {service.details.status_code}</p>
                            )}
                        </div>
                        <div className={`flex items-center space-x-2 px-2 py-1 rounded text-sm ${
                            service.status 
                                ? 'bg-green-100 text-green-800' 
                                : 'bg-red-100 text-red-800'
                        }`}>
                            <span>{service.status ? '✓' : '✗'}</span>
                            <span>{service.status ? 'Online' : 'Offline'}</span>
                        </div>
                    </div>
                ))}
            </div>
            
            <div className="flex items-center justify-between pt-4 border-t border-gray-100">
                <span className="text-sm text-gray-500">
                    Last checked: {health.checked_at ? format(new Date(health.checked_at), 'HH:mm:ss') : 'Never'}
                </span>
                <button
                    onClick={onRefresh}
                    className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors"
                >
                    Check Health
                </button>
            </div>
        </div>
    );
}

// Main dashboard component
export default function SoketiMetrics(props) {
    const page = usePage();
    const app = (props && props.app) || (page && page.props && page.props.app) || {};
    const config = (props && props.config) || (page && page.props && page.props.config) || {};

    // API endpoints
    const base = `/apps/${app.id}/metrics`;
    const metricsEndpoint = `${base}/cached`;
    const timeseriesEndpoint = `${base}/timeseries`;
    const healthEndpoint = `${base}/health`;

    // Hooks
    const refreshInterval = config.realtime_refresh_interval || 5000;
    const metrics = useMetricsData(metricsEndpoint, refreshInterval);
    const [health, setHealth] = useState(null);
    const [timeRange, setTimeRange] = useState(24); // hours

    // Fetch health status
    const fetchHealth = useCallback(async () => {
        try {
            const healthData = await fetchJSON(healthEndpoint);
            setHealth(healthData);
        } catch (err) {
            console.error('Health check failed:', err);
        }
    }, [healthEndpoint]);

    // Manual refresh
    const handleRefresh = useCallback(async () => {
        try {
            await fetchJSON(`${base}/refresh`, { method: 'POST' });
            metrics.refetch();
            fetchHealth();
        } catch (err) {
            console.error('Refresh failed:', err);
        }
    }, [base, metrics, fetchHealth]);

    useEffect(() => {
        fetchHealth();
        const interval = setInterval(fetchHealth, 30000);
        return () => clearInterval(interval);
    }, [fetchHealth]);

    // Extract data
    const data = metrics.data || {};
    const uploadMetrics = data.upload_metrics || {};
    const connections = data.connections || {};
    const dataTransfer = data.data_transfer || {};
    const uploadEvents = data.upload_events || {};

    // Prepare chart data
    const timeSeriesData = useMemo(() => {
        const hours = [];
        const now = new Date();
        
        for (let i = timeRange - 1; i >= 0; i--) {
            const time = subHours(startOfHour(now), i);
            hours.push({
                time: time.getTime(),
                timestamp: format(time, 'HH:mm'),
                connections: Math.floor(Math.random() * 100), // Mock data
                uploads: Math.floor(Math.random() * 20),
                bytes: Math.floor(Math.random() * 1000000),
            });
        }
        return hours;
    }, [timeRange]);

    const uploadStatusData = {
        completed: uploadEvents.last_hour?.completed || 0,
        failed: uploadEvents.last_hour?.failed || 0,
        active: uploadEvents.active_uploads || 0
    };

    return (
        <AuthenticatedLayout
            auth={props.auth}
            errors={props.errors}
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">
                            Upload Analytics
                        </h1>
                        <p className="text-gray-600 mt-1">
                            Real-time monitoring for {app.name || `App ${app.id}`}
                        </p>
                    </div>
                    <div className="flex items-center space-x-4">
                        <StatusIndicator 
                            loading={metrics.loading} 
                            error={metrics.error} 
                            lastUpdate={metrics.lastUpdate}
                            isLive={metrics.isLive}
                            onToggle={metrics.toggleLive}
                        />
                        <button
                            onClick={handleRefresh}
                            className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                        >
                            Refresh All
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="Upload Analytics" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                    
                    {/* System Health */}
                    <ServerHealth health={health} onRefresh={handleRefresh} />

                    {/* Key Metrics */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <MetricCard
                            title="Active Connections"
                            value={connections.current || 0}
                            icon="🔗"
                            color={COLORS.primary}
                            subtitle="Real-time WebSocket connections"
                        />
                        <MetricCard
                            title="Active Uploads"
                            value={uploadEvents.active_uploads || 0}
                            icon="📤"
                            color={COLORS.warning}
                            subtitle="Currently uploading files"
                        />
                        <MetricCard
                            title="Upload Success Rate"
                            value={uploadEvents.last_24_hours?.completion_rate || 0}
                            type="percentage"
                            icon="✅"
                            color={COLORS.success}
                            subtitle="Last 24 hours"
                        />
                        <MetricCard
                            title="Data Transferred"
                            value={dataTransfer.bytes_received + dataTransfer.bytes_sent || 0}
                            type="bytes"
                            icon="📊"
                            color={COLORS.accent}
                            subtitle="Total session data"
                        />
                    </div>

                    {/* Charts Row */}
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        {/* Connection Activity */}
                        <ChartCard title="Connection Activity" className="lg:col-span-2">
                            <ResponsiveContainer width="100%" height="100%">
                                <ComposedChart data={timeSeriesData}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                                    <XAxis 
                                        dataKey="timestamp" 
                                        axisLine={false}
                                        tickLine={false}
                                        tick={{ fontSize: 12, fill: '#666' }}
                                    />
                                    <YAxis 
                                        axisLine={false}
                                        tickLine={false}
                                        tick={{ fontSize: 12, fill: '#666' }}
                                    />
                                    <Tooltip 
                                        contentStyle={{ 
                                            backgroundColor: '#fff',
                                            border: '1px solid #e5e7eb',
                                            borderRadius: '8px'
                                        }}
                                    />
                                    <Area 
                                        type="monotone" 
                                        dataKey="connections" 
                                        fill={COLORS.primary} 
                                        fillOpacity={0.1}
                                        stroke={COLORS.primary}
                                        strokeWidth={2}
                                        name="Connections"
                                    />
                                    <Bar 
                                        dataKey="uploads" 
                                        fill={COLORS.success} 
                                        name="Uploads"
                                        radius={[2, 2, 0, 0]}
                                    />
                                </ComposedChart>
                            </ResponsiveContainer>
                        </ChartCard>

                        {/* Upload Status Distribution */}
                        <ChartCard title="Upload Status">
                            <UploadStatusChart data={uploadStatusData} />
                        </ChartCard>
                    </div>

                    {/* Upload Metrics Detail */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <MetricCard
                            title="Avg Upload Speed"
                            value={uploadEvents.last_hour?.avg_speed || 0}
                            type="speed"
                            subtitle="Current session average"
                        />
                        <MetricCard
                            title="Avg Duration"
                            value={uploadEvents.last_hour?.avg_duration || 0}
                            type="duration"
                            subtitle="Time to complete upload"
                        />
                        <MetricCard
                            title="Completed Today"
                            value={uploadEvents.last_24_hours?.completed || 0}
                            subtitle="Successful uploads"
                        />
                        <MetricCard
                            title="Failed Today"
                            value={uploadEvents.last_24_hours?.failed || 0}
                            color={COLORS.error}
                            subtitle="Failed upload attempts"
                        />
                    </div>

                    {/* Data Transfer Chart */}
                    <ChartCard title="Data Transfer Rate" height={250}>
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={timeSeriesData}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                                <XAxis 
                                    dataKey="timestamp" 
                                    axisLine={false}
                                    tickLine={false}
                                    tick={{ fontSize: 12, fill: '#666' }}
                                />
                                <YAxis 
                                    axisLine={false}
                                    tickLine={false}
                                    tick={{ fontSize: 12, fill: '#666' }}
                                    tickFormatter={(value) => formatValue(value, 'bytes')}
                                />
                                <Tooltip 
                                    contentStyle={{ 
                                        backgroundColor: '#fff',
                                        border: '1px solid #e5e7eb',
                                        borderRadius: '8px'
                                    }}
                                    formatter={(value) => [formatValue(value, 'bytes'), 'Data Transfer']}
                                />
                                <Line 
                                    type="monotone" 
                                    dataKey="bytes" 
                                    stroke={COLORS.accent}
                                    strokeWidth={3}
                                    dot={false}
                                    activeDot={{ r: 6, fill: COLORS.accent }}
                                />
                            </LineChart>
                        </ResponsiveContainer>
                    </ChartCard>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}