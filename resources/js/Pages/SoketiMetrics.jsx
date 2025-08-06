import React, { useEffect, useState, useCallback } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import {
    LineChart, Line, AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, Legend,
    ResponsiveContainer, PieChart, Pie, Cell, ComposedChart
} from 'recharts';
import { format } from 'date-fns';

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
            // Display as hours/minutes without trailing seconds "s"
            if (n >= 3600) return `${(n / 3600).toFixed(1)}h`;
            if (n >= 60) return `${(n / 60).toFixed(1)}m`;
            return `${n.toFixed(0)}`; // remove 's'
        
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

// Message distribution pie chart
function MessageChart({ data }) {
    if (!data || data.length === 0) {
        return (
            <div className="flex items-center justify-center h-full text-gray-500">
                <span>No message data available</span>
            </div>
        );
    }

    return (
        <ResponsiveContainer width="100%" height="100%">
            <PieChart margin={{ top: 10, right: 10, bottom: 10, left: 10 }}>
                <Pie
                    data={data}
                    cx="50%"
                    cy="50%"
                    innerRadius={50}
                    outerRadius={100}
                    paddingAngle={2}
                    dataKey="value"
                    label={({ name, value }) => `${name}: ${value}`}
                >
                    {data.map((entry, index) => (
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
    const healthEndpoint = `${base}/health`;

    // Hooks
    const refreshInterval = config.realtime_refresh_interval || 5000;
    const metrics = useMetricsData(metricsEndpoint, refreshInterval);
    const [health, setHealth] = useState(null);

    // Fetch health status
    const fetchHealth = useCallback(async () => {
        try {
            const healthData = await fetchJSON(healthEndpoint);
            setHealth(healthData);
        } catch (err) {
            console.error('Health check failed:', err);
        }
    }, [healthEndpoint]);

    useEffect(() => {
        fetchHealth();
        const interval = setInterval(fetchHealth, 30000);
        return () => clearInterval(interval);
    }, [fetchHealth]);

    // Extract data
    const data = metrics.data || {};
    const connections = data.connections || {};
    const dataTransfer = data.data_transfer || {};
    const websockets = data.websockets || {};
    const system = data.system || {};
    const performance = data.performance || {};

    const messageTypeData = [
        { name: 'WebSocket Messages', value: websockets.messages_sent || 0, color: COLORS.primary },
        { name: 'HTTP Requests', value: Math.floor((websockets.messages_sent || 0) * 0.1), color: COLORS.secondary },
        { name: 'System Messages', value: Math.floor((websockets.messages_sent || 0) * 0.05), color: COLORS.accent },
    ].filter(item => item.value > 0);

    return (
        <AuthenticatedLayout
            auth={props.auth}
            errors={props.errors}
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">
                            Soketi WebSocket Metrics
                        </h1>
                        <p className="text-gray-600 mt-1">
                            Real-time WebSocket server monitoring for {app.name || `App ${app.id}`}
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
                    </div>
                </div>
            }
        >
            <Head title="Soketi WebSocket Metrics" />

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
                            title="Messages Sent"
                            value={websockets.messages_sent || 0}
                            icon="💬"
                            color={COLORS.success}
                            subtitle="Total WebSocket messages"
                        />
                        <MetricCard
                            title="Data Transferred"
                            value={(dataTransfer.bytes_received || 0) + (dataTransfer.bytes_sent || 0)}
                            type="bytes"
                            icon="📊"
                            color={COLORS.accent}
                            subtitle="Total session data"
                        />
                        <MetricCard
                            title="Memory Usage"
                            value={system.memory_usage || 0}
                            type="bytes"
                            icon="🧠"
                            color={COLORS.warning}
                            subtitle="Server memory consumption"
                        />
                    </div>

                    {/* Message Types only, full width and taller to avoid clipping */}
                    <ChartCard title="Message Types" height={360}>
                        <MessageChart data={messageTypeData} />
                    </ChartCard>

                    {/* Performance Metrics */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <MetricCard
                            title="Avg Message Size"
                            value={performance.avg_message_size || 0}
                            type="bytes"
                            subtitle="Per WebSocket message"
                        />
                        <MetricCard
                            title="Memory per Connection"
                            value={performance.memory_per_connection || 0}
                            type="bytes"
                            subtitle="Resource efficiency"
                        />
                        <MetricCard
                            title="Server Uptime"
                            value={performance.uptime_hours || 0}
                            type="duration"
                            subtitle="Hours online"
                        />
                        <MetricCard
                            title="Connection Stability"
                            value={data.websocket_events?.connection_stability || 0}
                            type="percentage"
                            color={COLORS.success}
                            subtitle="Connection success rate"
                        />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}