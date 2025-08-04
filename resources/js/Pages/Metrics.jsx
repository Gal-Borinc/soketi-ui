import React, { useEffect, useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';

function fmt(val, digits = 2) {
    if (val == null) return '-';
    const n = Number(val);
    if (Number.isNaN(n)) return '-';
    if (n >= 1e9) return (n / 1e9).toFixed(digits) + 'G';
    if (n >= 1e6) return (n / 1e6).toFixed(digits) + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(digits) + 'k';
    return n.toFixed(digits);
}

async function fetchJSON(url) {
    const res = await fetch(url, { credentials: 'include' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

function useInstant(endpoint) {
    const [value, setValue] = useState(null);
    const [loading, setLoading] = useState(true);
    const [err, setErr] = useState(null);

    useEffect(() => {
        let mounted = true;
        setLoading(true);
        fetchJSON(endpoint)
            .then((j) => {
                if (!mounted) return;
                const data = j.data || {};
                if (data.resultType === 'vector' && Array.isArray(data.result) && data.result.length > 0) {
                    const sample = data.result[0];
                    const val = Array.isArray(sample.value) ? Number(sample.value[1]) : null;
                    setValue(val);
                } else if (data.resultType === 'scalar' && Array.isArray(data.result)) {
                    setValue(Number(data.result[1]));
                } else {
                    setValue(0);
                }
            })
            .catch((e) => mounted && setErr(e))
            .finally(() => mounted && setLoading(false));
        return () => (mounted = false);
    }, [endpoint]);

    return { value, loading, err };
}

function useRange(endpoint) {
    const [series, setSeries] = useState([]); // array of [timestamp, value]
    const [loading, setLoading] = useState(true);
    const [err, setErr] = useState(null);

    useEffect(() => {
        let mounted = true;
        setLoading(true);
        fetchJSON(endpoint)
            .then((j) => {
                if (!mounted) return;
                const data = j.data || {};
                if (data.resultType === 'matrix' && Array.isArray(data.result) && data.result.length > 0) {
                    const samples = data.result[0].values || [];
                    setSeries(samples.map((v) => [Number(v[0]) * 1000, Number(v[1])]));
                } else {
                    setSeries([]);
                }
            })
            .catch((e) => mounted && setErr(e))
            .finally(() => mounted && setLoading(false));
        return () => (mounted = false);
    }, [endpoint]);

    return { series, loading, err };
}

function Line({ data }) {
    // Minimal inline SVG sparkline without adding a chart dependency
    const width = 500;
    const height = 120;
    const padding = 8;

    const pathD = useMemo(() => {
        if (!data || data.length === 0) return '';
        const xs = data.map((d) => d[0]);
        const ys = data.map((d) => d[1]);
        const xmin = Math.min(...xs);
        const xmax = Math.max(...xs);
        const ymin = Math.min(...ys);
        const ymax = Math.max(...ys);
        const xr = xmax - xmin || 1;
        const yr = ymax - ymin || 1;

        const scaleX = (x) =>
            padding + ((x - xmin) / xr) * (width - 2 * padding);
        const scaleY = (y) =>
            height - padding - ((y - ymin) / yr) * (height - 2 * padding);

        let d = '';
        data.forEach(([x, y], i) => {
            const X = scaleX(x);
            const Y = scaleY(y);
            d += (i === 0 ? 'M' : 'L') + X + ' ' + Y + ' ';
        });
        return d;
    }, [data]);

    return (
        <svg width={width} height={height} style={{ display: 'block', width: '100%' }}>
            <path d={pathD} fill="none" stroke="#6366F1" strokeWidth="2" />
        </svg>
    );
}

function StatCard({ title, value, loading }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div className="text-sm text-gray-500">{title}</div>
            <div className="mt-1 text-2xl font-semibold text-gray-900">
                {loading ? '…' : fmt(value)}
            </div>
        </div>
    );
}

export default function Metrics(props) {
    // Safely obtain app from props or from Inertia page props to avoid undefined auth/user errors
    const page = usePage();
    const app = (props && props.app) || (page && page.props && page.props.app) || {};

    // Time range: last 30 minutes with 30s step
    const end = Math.floor(Date.now() / 1000);
    const start = end - 5 * 60; // last 5 minutes
    const step = 5; // seconds

    // Endpoints
    const base = `/apps/${app.id}/metrics`;

    // Instant metrics (updated metric name)
    const connInstant = useInstant(`${base}/query?query=${encodeURIComponent('soketi_connected')}`);

    // Ranges (reverted to 5m window to match backend allowlist and give smoother graphs)
    const newConn = useRange(
        `${base}/query_range?query=${encodeURIComponent('increase(soketi_new_connections_total[5m])')}&start=${start}&end=${end}&step=${step}s`
    );
    const disconn = useRange(
        `${base}/query_range?query=${encodeURIComponent('increase(soketi_new_disconnections_total[5m])')}&start=${start}&end=${end}&step=${step}s`
    );
    const rx = useRange(
        `${base}/query_range?query=${encodeURIComponent('rate(soketi_socket_received_bytes[5m])')}&start=${start}&end=${end}&step=${step}s`
    );
    const tx = useRange(
        `${base}/query_range?query=${encodeURIComponent('rate(soketi_socket_transmitted_bytes[5m])')}&start=${start}&end=${end}&step=${step}s`
    );

    // For stat value of range series, show last point
    const lastOf = (s) => (s.series.length ? s.series[s.series.length - 1][1] : null);

    return (
        <AuthenticatedLayout
            auth={page && page.props ? page.props.auth : undefined}
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        {(app && (app.name || app.id)) ? (app.name || app.id) : 'App'} • Metrics
                    </h2>
                </div>
            }
        >
            <Head title="Metrics" />
            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <StatCard title="Current Connections" value={connInstant.value} loading={connInstant.loading} />
                        <StatCard title="New Connections (5m)" value={lastOf(newConn)} loading={newConn.loading} />
                        <StatCard title="Disconnections (5m)" value={lastOf(disconn)} loading={disconn.loading} />
                        <StatCard title="RX Bytes/s" value={lastOf(rx)} loading={rx.loading} />
                        <StatCard title="TX Bytes/s" value={lastOf(tx)} loading={tx.loading} />
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div className="mb-2 text-sm font-medium text-gray-700">New Connections (5m increase)</div>
                        <Line data={newConn.series} />
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div className="mb-2 text-sm font-medium text-gray-700">Disconnections (5m increase)</div>
                        <Line data={disconn.series} />
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div className="mb-2 text-sm font-medium text-gray-700">RX Bytes/s</div>
                        <Line data={rx.series} />
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div className="mb-2 text-sm font-medium text-gray-700">TX Bytes/s</div>
                        <Line data={tx.series} />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}