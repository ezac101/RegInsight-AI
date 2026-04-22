import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    index,
    ndpcData,
} from '@/actions/App/Http/Controllers/GaidController';
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    LinearScale,
    Title,
    Tooltip,
    type ChartConfiguration,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Title, Tooltip);

type HeatmapItem = {
    sector: string;
    compliance_percent: number;
    organisations: number;
};

type DashboardPayload = {
    stats: {
        total_submissions: number;
        submitted_to_ndpc: number;
        cars_generated: number;
        deadline_at_risk: number;
        anomalies: number;
        sector_heatmap: HeatmapItem[];
    };
    submissions: Array<{
        reference_code: string;
        organisation_name: string;
        sector: string;
        dcpmi_tier: string | null;
        compliance_score: number | null;
        status: string;
        filing_deadline: string | null;
        updated_at: string;
    }>;
};

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function loadDashboardData(): Promise<DashboardPayload> {
    const route = ndpcData.get();

    const response = await fetch(route.url, {
        method: route.method.toUpperCase(),
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });

    if (!response.ok) {
        throw new Error(await response.text());
    }

    return response.json();
}

export default function NdpcDashboardPage() {
    const [payload, setPayload] = useState<DashboardPayload | null>(null);
    const [error, setError] = useState<string>('');
    const [loading, setLoading] = useState<boolean>(true);
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const chartRef = useRef<Chart | null>(null);

    useEffect(() => {
        let mounted = true;

        const fetchData = async () => {
            try {
                const next = await loadDashboardData();
                if (mounted) {
                    setPayload(next);
                    setError('');
                }
            } catch (e) {
                if (mounted) {
                    setError((e as Error).message || 'Failed to load dashboard data.');
                }
            } finally {
                if (mounted) {
                    setLoading(false);
                }
            }
        };

        fetchData();
        const timer = window.setInterval(fetchData, 5000);

        return () => {
            mounted = false;
            window.clearInterval(timer);
        };
    }, []);

    useEffect(() => {
        if (!payload || !canvasRef.current) {
            return;
        }

        const labels = payload.stats.sector_heatmap.map((item) => item.sector);
        const data = payload.stats.sector_heatmap.map((item) => Number(item.compliance_percent));

        const config: ChartConfiguration<'bar'> = {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Compliance %',
                        data,
                        backgroundColor: '#10b981',
                        borderRadius: 8,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                    },
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Sector Heatmap - Compliance Percentage',
                    },
                },
            },
        };

        chartRef.current?.destroy();
        chartRef.current = new Chart(canvasRef.current, config);

        return () => {
            chartRef.current?.destroy();
            chartRef.current = null;
        };
    }, [payload]);

    const anomalies = useMemo(() => {
        if (!payload) {
            return [];
        }

        return payload.submissions.filter((item) => {
            const score = Number(item.compliance_score ?? 0);
            return score < 40 && item.dcpmi_tier !== null;
        }).slice(0, 6);
    }, [payload]);

    return (
        <>
            <Head title="NDPC Dashboard" />

            <main className="min-h-screen bg-slate-950 text-slate-100">
                <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                    <header className="mb-6 rounded-2xl border border-emerald-700/40 bg-slate-900 p-6">
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-400">
                            NDPC Regulator View
                        </p>
                        <h1 className="mt-2 text-3xl font-bold">Live Compliance Dashboard</h1>
                        <p className="mt-2 text-sm text-slate-300">
                            Real-time monitoring of assessed organisations, risks, and filing deadlines.
                        </p>
                    </header>

                    {error !== '' && (
                        <div className="mb-4 rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                            {error}
                        </div>
                    )}

                    {loading && payload === null ? (
                        <div className="rounded-xl border border-slate-800 bg-slate-900 px-4 py-6 text-sm text-slate-300">
                            Loading dashboard data...
                        </div>
                    ) : (
                        <>
                            <section className="grid gap-4 md:grid-cols-5">
                                <StatCard label="Total Submissions" value={payload?.stats.total_submissions ?? 0} />
                                <StatCard label="Submitted to NDPC" value={payload?.stats.submitted_to_ndpc ?? 0} />
                                <StatCard label="CAR Generated" value={payload?.stats.cars_generated ?? 0} />
                                <StatCard label="Deadline at Risk" value={payload?.stats.deadline_at_risk ?? 0} />
                                <StatCard label="Anomaly Flags" value={payload?.stats.anomalies ?? 0} danger />
                            </section>

                            <section className="mt-6 grid gap-6 lg:grid-cols-3">
                                <div className="rounded-2xl border border-slate-800 bg-slate-900 p-4 lg:col-span-2">
                                    <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-300">Sector Heatmap</h2>
                                    <div className="h-72">
                                        <canvas ref={canvasRef} />
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                                    <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-300">Anomaly Flags & Early Warnings</h2>
                                    <div className="space-y-2">
                                        {anomalies.length === 0 ? (
                                            <p className="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-300">
                                                No active high-risk anomalies.
                                            </p>
                                        ) : (
                                            anomalies.map((item) => (
                                                <div key={item.reference_code} className="rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs text-red-100">
                                                    {item.organisation_name} ({item.reference_code}) is at
                                                    {' '}<strong>{Math.round(Number(item.compliance_score ?? 0))}%</strong> compliance.
                                                </div>
                                            ))
                                        )}
                                    </div>
                                </div>
                            </section>

                            <section className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 p-4">
                                <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-300">Assessed Organisations</h2>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-sm">
                                        <thead className="text-left text-slate-400">
                                            <tr>
                                                <th className="px-3 py-2">Organisation</th>
                                                <th className="px-3 py-2">Reference</th>
                                                <th className="px-3 py-2">Tier</th>
                                                <th className="px-3 py-2">Sector</th>
                                                <th className="px-3 py-2">Compliance</th>
                                                <th className="px-3 py-2">Deadline</th>
                                                <th className="px-3 py-2">Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(payload?.submissions ?? []).map((item) => (
                                                <tr key={item.reference_code} className="border-t border-slate-800">
                                                    <td className="px-3 py-2 text-slate-100">{item.organisation_name}</td>
                                                    <td className="px-3 py-2 text-emerald-300">{item.reference_code}</td>
                                                    <td className="px-3 py-2 text-slate-200">{item.dcpmi_tier ?? '-'}</td>
                                                    <td className="px-3 py-2 text-slate-200">{item.sector}</td>
                                                    <td className="px-3 py-2 text-slate-200">{Math.round(Number(item.compliance_score ?? 0))}%</td>
                                                    <td className="px-3 py-2 text-slate-200">{item.filing_deadline ?? '-'}</td>
                                                    <td className="px-3 py-2 text-slate-400">{new Date(item.updated_at).toLocaleString()}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <section className="mt-6 flex items-center justify-between rounded-xl border border-emerald-700/40 bg-emerald-900/20 px-4 py-3">
                                <p className="text-sm text-emerald-100">
                                    Live update is enabled. New submissions from organisation flow appear automatically.
                                </p>
                                <a href={index.url()} className="rounded-lg border border-emerald-400 px-4 py-2 text-xs font-semibold text-emerald-200 transition hover:bg-emerald-700/30">
                                    Back to Organisation Flow
                                </a>
                            </section>
                        </>
                    )}
                </div>
            </main>
        </>
    );
}

function StatCard({ label, value, danger = false }: { label: string; value: number; danger?: boolean }) {
    return (
        <article className={[
            'rounded-xl border px-4 py-4',
            danger ? 'border-red-600/50 bg-red-900/20' : 'border-slate-800 bg-slate-900',
        ].join(' ')}>
            <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
            <p className={['mt-2 text-2xl font-bold', danger ? 'text-red-300' : 'text-emerald-300'].join(' ')}>{value}</p>
        </article>
    );
}
