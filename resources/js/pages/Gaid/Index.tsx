import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    assess,
    generateCar,
    ndpc,
    submit as submitAssessment,
    uploadDocuments,
} from '@/actions/App/Http/Controllers/GaidController';

const sectors = [
    'Fintech',
    'Health',
    'E-commerce',
    'Banking',
    'Insurance',
    'Telecommunications',
    'Education',
    'Government',
    'Energy',
    'Logistics',
    'Agriculture',
    'Real Estate',
    'Hospitality',
    'Manufacturing',
    'Media',
    'Other',
] as const;

const annualRevenueBands = [
    'Below N50m',
    'N50m-N500m',
    'N500m-N5bn',
    'Above N5bn',
] as const;

type Sector = (typeof sectors)[number];

type IntakeForm = {
    organisation_name: string;
    organisation_email: string;
    sector: Sector | '';
    data_subjects: number | '';
    uses_ai: boolean;
    processes_sensitive_data: boolean;
    transfers_data_outside_nigeria: boolean;
    has_dpo: boolean;
    has_data_retention_policy: boolean;
    has_breach_policy: boolean;
    has_vendor_dp_agreements: boolean;
    annual_revenue_band: string;
};

type Classification = {
    tier: string;
    car_filing_fee: number;
    filing_deadline: string | null;
    dpo_required: boolean;
    dpia_required: boolean;
    reasons?: string[];
};

type Obligation = {
    id?: number;
    clause_reference: string;
    title: string;
    description: string;
    plain_language: string;
    deadline: string | null;
    risk: string;
};

type GapItem = {
    obligation_id?: number;
    clause_reference?: string;
    status: 'covered' | 'partial' | 'not_evidenced' | 'not_applicable';
    risk_level?: 'low' | 'medium' | 'high';
    recommendation?: string;
    detail?: string;
};

type WizardStep = 1 | 2 | 3 | 4 | 5 | 6 | 7;

const initialForm: IntakeForm = {
    organisation_name: '',
    organisation_email: '',
    sector: '',
    data_subjects: '',
    uses_ai: false,
    processes_sensitive_data: false,
    transfers_data_outside_nigeria: false,
    has_dpo: false,
    has_data_retention_policy: false,
    has_breach_policy: false,
    has_vendor_dp_agreements: false,
    annual_revenue_band: '',
};

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function requestJson<T>(url: string, method: string, body?: unknown): Promise<T> {
    const response = await fetch(url, {
        method: method.toUpperCase(),
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (!response.ok) {
        const text = await response.text();

        throw new Error(text || `${response.status} ${response.statusText}`);
    }

    return response.json();
}

async function requestBlob(url: string, method: string, body?: unknown): Promise<Blob> {
    const response = await fetch(url, {
        method: method.toUpperCase(),
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/pdf',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (!response.ok) {
        const text = await response.text();

        throw new Error(text || `${response.status} ${response.statusText}`);
    }

    return response.blob();
}

async function requestForm<T>(url: string, method: string, body: FormData): Promise<T> {
    const response = await fetch(url, {
        method: method.toUpperCase(),
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body,
    });

    if (!response.ok) {
        const text = await response.text();

        throw new Error(text || `${response.status} ${response.statusText}`);
    }

    return response.json();
}

export default function GaidIndexPage() {
    const [step, setStep] = useState<WizardStep>(1);
    const [form, setForm] = useState<IntakeForm>(initialForm);
    const [referenceCode, setReferenceCode] = useState<string>('');
    const [classification, setClassification] = useState<Classification | null>(null);
    const [obligations, setObligations] = useState<Obligation[]>([]);
    const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
    const [gaps, setGaps] = useState<GapItem[]>([]);
    const [complianceScore, setComplianceScore] = useState<number>(0);
    const [isBusy, setIsBusy] = useState(false);
    const [error, setError] = useState<string>('');
    const [success, setSuccess] = useState<string>('');

    const canClassify = useMemo(() => {
        return (
            form.organisation_name.trim() !== '' &&
            form.organisation_email.trim() !== '' &&
            form.sector !== '' &&
            form.data_subjects !== ''
        );
    }, [form]);

    async function runAssessment(currentForm: IntakeForm): Promise<void> {
        const payload = {
            ...currentForm,
            parastatal: 'NDPC',
            data_subjects: Number(currentForm.data_subjects),
        };

        const result = await requestJson<{
            reference_code: string;
            classification: Classification;
            obligations: Obligation[];
        }>(assess.post().url, assess.post().method, payload);

        setReferenceCode(result.reference_code);
        setClassification(result.classification);
        setObligations(result.obligations);
        setStep(2);
    }

    async function handleClassify(): Promise<void> {
        setIsBusy(true);
        setError('');
        setSuccess('');

        try {
            await runAssessment(form);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setIsBusy(false);
        }
    }

    async function handleUploadAndAnalyse(): Promise<void> {
        if (!referenceCode || selectedFiles.length === 0) {
            setError('Please select 1 to 3 PDF files first.');

            return;
        }

        setIsBusy(true);
        setError('');
        setSuccess('');

        const formData = new FormData();
        formData.append('reference_code', referenceCode);
        selectedFiles.forEach((file) => formData.append('files[]', file));

        try {
            const result = await requestForm<{
                analysis: {
                    compliance_score: number;
                    gaps: GapItem[];
                };
            }>(uploadDocuments.post().url, uploadDocuments.post().method, formData);

            setGaps(result.analysis.gaps ?? []);
            setComplianceScore(Number(result.analysis.compliance_score ?? 0));
            setStep(5);
            setSuccess('Document analysis complete.');
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setIsBusy(false);
        }
    }

    async function handleGenerateCar(): Promise<void> {
        if (!referenceCode) {
            setError('Assessment reference is missing.');

            return;
        }

        setIsBusy(true);
        setError('');
        setSuccess('');

        try {
            const blob = await requestBlob(generateCar.post().url, generateCar.post().method, {
                reference_code: referenceCode,
            });

            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `CAR_${referenceCode}.pdf`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);

            setStep(7);
            setSuccess('CAR draft downloaded successfully.');
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setIsBusy(false);
        }
    }

    async function handleSubmitToNdpc(): Promise<void> {
        if (!referenceCode) {
            setError('Assessment reference is missing.');

            return;
        }

        setIsBusy(true);
        setError('');
        setSuccess('');

        try {
            await requestJson(submitAssessment.post().url, submitAssessment.post().method, {
                reference_code: referenceCode,
            });

            setSuccess('Assessment submitted successfully.');
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setIsBusy(false);
        }
    }

    return (
        <>
            <Head title="GAID Guardian" />

            <main className="min-h-screen bg-slate-50">
                <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                    <header className="mb-6 rounded-2xl border border-emerald-200 bg-white p-6 shadow-sm">
                        <div className="mb-4 flex items-center justify-start">
                            <a
                                href={ndpc.url()}
                                className="rounded-lg border border-emerald-600 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-emerald-700 transition hover:bg-emerald-50"
                            >
                                Open NDPC Dashboard
                            </a>
                        </div>
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">
                            GAID Guardian
                        </p>
                        <h1 className="mt-2 text-3xl font-bold text-slate-900">
                            Nigerian GAID 2025 Compliance in 7 Guided Steps
                        </h1>
                        <p className="mt-2 text-sm text-slate-600">
                            Public demo flow for organisations and NDPC regulator visibility. No login required.
                        </p>
                    </header>

                    <ProgressBar step={step} />

                    {error !== '' && (
                        <div className="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                            {error}
                        </div>
                    )}

                    {success !== '' && (
                        <div className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                            {success}
                        </div>
                    )}

                    <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        {step === 1 && (
                            <div className="space-y-6">
                                <div>
                                    <h2 className="text-xl font-semibold text-slate-900">Step 1 - Intake Form</h2>
                                    <p className="mt-1 text-sm text-slate-600">
                                        Answer 10 quick questions to classify your organisation.
                                    </p>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <Field label="Organisation Name">
                                        <input
                                            className="input"
                                            value={form.organisation_name}
                                            onChange={(event) => setForm((prev) => ({ ...prev, organisation_name: event.target.value }))}
                                            placeholder="Lagos Fintech Startup Ltd"
                                        />
                                    </Field>
                                    <Field label="Organisation Email">
                                        <input
                                            className="input"
                                            type="email"
                                            value={form.organisation_email}
                                            onChange={(event) => setForm((prev) => ({ ...prev, organisation_email: event.target.value }))}
                                            placeholder="compliance@example.ng"
                                        />
                                    </Field>
                                    <Field label="Sector">
                                        <select
                                            className="input"
                                            value={form.sector}
                                            onChange={(event) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    sector: event.target.value as IntakeForm['sector'],
                                                }))
                                            }
                                        >
                                            <option value="">Select sector</option>
                                            {sectors.map((sector) => (
                                                <option key={sector} value={sector}>
                                                    {sector}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field label="Number of Data Subjects">
                                        <input
                                            className="input"
                                            type="text"
                                            inputMode="numeric"
                                            pattern="[0-9]*"
                                            placeholder="e.g. 75000"
                                            value={form.data_subjects}
                                            onChange={(event) => {
                                                const sanitized = event.target.value.replace(/\D+/g, '');

                                                setForm((prev) => ({
                                                    ...prev,
                                                    data_subjects: sanitized === '' ? '' : Number(sanitized),
                                                }));
                                            }}
                                        />
                                    </Field>
                                    <Field label="Annual Revenue Band">
                                        <select
                                            className="input"
                                            value={form.annual_revenue_band}
                                            onChange={(event) => setForm((prev) => ({ ...prev, annual_revenue_band: event.target.value }))}
                                        >
                                            <option value="">Select annual revenue band</option>
                                            {annualRevenueBands.map((band) => (
                                                <option key={band} value={band}>
                                                    {band}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>

                                <div className="grid gap-3 md:grid-cols-2">
                                    <Toggle
                                        label="Do you use AI in your operations?"
                                        value={form.uses_ai}
                                        onChange={(value) => setForm((prev) => ({ ...prev, uses_ai: value }))}
                                    />
                                    <Toggle
                                        label="Do you process sensitive data?"
                                        value={form.processes_sensitive_data}
                                        onChange={(value) => setForm((prev) => ({ ...prev, processes_sensitive_data: value }))}
                                    />
                                    <Toggle
                                        label="Do you transfer data outside Nigeria?"
                                        value={form.transfers_data_outside_nigeria}
                                        onChange={(value) => setForm((prev) => ({ ...prev, transfers_data_outside_nigeria: value }))}
                                    />
                                    <Toggle
                                        label="Do you have a designated DPO already?"
                                        value={form.has_dpo}
                                        onChange={(value) => setForm((prev) => ({ ...prev, has_dpo: value }))}
                                    />
                                    <Toggle
                                        label="Do you maintain a data retention policy?"
                                        value={form.has_data_retention_policy}
                                        onChange={(value) => setForm((prev) => ({ ...prev, has_data_retention_policy: value }))}
                                    />
                                    <Toggle
                                        label="Do you have a breach response policy?"
                                        value={form.has_breach_policy}
                                        onChange={(value) => setForm((prev) => ({ ...prev, has_breach_policy: value }))}
                                    />
                                    <Toggle
                                        label="Do vendor agreements include data protection clauses?"
                                        value={form.has_vendor_dp_agreements}
                                        onChange={(value) => setForm((prev) => ({ ...prev, has_vendor_dp_agreements: value }))}
                                    />
                                </div>

                                <div className="flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        onClick={handleClassify}
                                        disabled={!canClassify || isBusy}
                                        className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {isBusy ? 'Classifying...' : 'Classify My Organisation'}
                                    </button>
                                </div>
                            </div>
                        )}

                        {step === 2 && classification && (
                            <div className="space-y-5">
                                <h2 className="text-xl font-semibold text-slate-900">Step 2 - Instant Classification</h2>

                                <dl className="grid gap-4 md:grid-cols-2">
                                    <Info label="Tier" value={classification.tier} />
                                    <Info label="CAR Filing Fee" value={`N${classification.car_filing_fee.toLocaleString()}`} />
                                    <Info label="Filing Deadline" value={classification.filing_deadline ?? 'Not set'} />
                                    <Info label="DPO Required" value={classification.dpo_required ? 'Yes - Immediately' : 'No'} />
                                    <Info label="DPIA Required" value={classification.dpia_required ? 'Yes' : 'No'} />
                                    <Info label="Reference Code" value={referenceCode} />
                                </dl>

                                {(classification.reasons ?? []).length > 0 && (
                                    <div>
                                        <p className="text-sm font-semibold text-slate-800">Trigger Reasons</p>
                                        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600">
                                            {classification.reasons?.map((reason) => (
                                                <li key={reason}>{reason}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                <button
                                    type="button"
                                    onClick={() => setStep(3)}
                                    className="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700"
                                >
                                    Continue to Personalised Obligations
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setStep(1)}
                                    className="ml-3 rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Previous Step
                                </button>
                            </div>
                        )}

                        {step === 3 && (
                            <div className="space-y-5">
                                <h2 className="text-xl font-semibold text-slate-900">Step 3 - Personalised Obligations</h2>
                                <p className="text-sm text-slate-600">
                                    All obligations are taken directly from official GAID 2025 text using RAG.
                                </p>

                                <div className="overflow-hidden rounded-xl border border-slate-200">
                                    <table className="min-w-full text-sm">
                                        <thead className="bg-slate-100 text-left text-slate-700">
                                            <tr>
                                                <th className="px-4 py-2">Obligation</th>
                                                <th className="px-4 py-2">Clause</th>
                                                <th className="px-4 py-2">Deadline</th>
                                                <th className="px-4 py-2">Plain English</th>
                                                <th className="px-4 py-2">Risk</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {obligations.map((obligation) => (
                                                <tr key={`${obligation.clause_reference}-${obligation.title}`} className="border-t border-slate-200">
                                                    <td className="px-4 py-2 font-medium text-slate-900">{obligation.title}</td>
                                                    <td className="px-4 py-2 text-slate-700">{obligation.clause_reference}</td>
                                                    <td className="px-4 py-2 text-slate-700">{obligation.deadline ?? '-'}</td>
                                                    <td className="px-4 py-2 text-slate-700">{obligation.plain_language}</td>
                                                    <td className="px-4 py-2 text-slate-700">{obligation.risk}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => setStep(4)}
                                    className="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700"
                                >
                                    Continue to Document Upload
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setStep(2)}
                                    className="ml-3 rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Previous Step
                                </button>
                            </div>
                        )}

                        {step === 4 && (
                            <div className="space-y-5">
                                <h2 className="text-xl font-semibold text-slate-900">Step 4 - Upload Existing Policies</h2>
                                <p className="text-sm text-slate-600">Upload 1 to 3 PDF documents for evidence analysis.</p>

                                <label className="block rounded-xl border-2 border-dashed border-emerald-300 bg-emerald-50 p-8 text-center">
                                    <input
                                        type="file"
                                        className="hidden"
                                        accept="application/pdf"
                                        multiple
                                        onChange={(event) => {
                                            const files = Array.from(event.target.files ?? []).slice(0, 3);
                                            setSelectedFiles(files);
                                        }}
                                    />
                                    <span className="text-sm font-semibold text-emerald-700">Click to select policy PDF files</span>
                                    <p className="mt-2 text-xs text-emerald-600">Privacy policy, breach policy, DPIA, and related documents</p>
                                </label>

                                {selectedFiles.length > 0 && (
                                    <ul className="space-y-2 text-sm text-slate-700">
                                        {selectedFiles.map((file) => (
                                            <li key={file.name} className="rounded-lg border border-slate-200 px-3 py-2">
                                                {file.name}
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                <button
                                    type="button"
                                    onClick={handleUploadAndAnalyse}
                                    disabled={selectedFiles.length === 0 || isBusy}
                                    className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {isBusy ? 'Analysing documents...' : 'Analyse Documents'}
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setStep(3)}
                                    className="ml-3 rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Previous Step
                                </button>
                            </div>
                        )}

                        {step === 5 && (
                            <div className="space-y-5">
                                <h2 className="text-xl font-semibold text-slate-900">Step 5 - Gap Analysis</h2>
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <p className="text-sm text-slate-700">
                                        Overall Compliance Score: <strong>{Math.round(complianceScore)}%</strong>
                                    </p>
                                </div>

                                <div className="overflow-hidden rounded-xl border border-slate-200">
                                    <table className="min-w-full text-sm">
                                        <thead className="bg-slate-100 text-left text-slate-700">
                                            <tr>
                                                <th className="px-4 py-2">Obligation</th>
                                                <th className="px-4 py-2">Status</th>
                                                <th className="px-4 py-2">Risk</th>
                                                <th className="px-4 py-2">Recommendation</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {gaps.map((gap, index) => {
                                                const obligation = obligations.find((item) => item.id === gap.obligation_id)
                                                    ?? obligations.find((item) => item.clause_reference === gap.clause_reference);

                                                return (
                                                    <tr key={`${gap.clause_reference ?? gap.obligation_id ?? index}-${index}`} className="border-t border-slate-200">
                                                        <td className="px-4 py-2 text-slate-800">
                                                            {obligation?.title ?? gap.clause_reference ?? 'Obligation'}
                                                        </td>
                                                        <td className="px-4 py-2">
                                                            <StatusBadge status={gap.status} />
                                                        </td>
                                                        <td className="px-4 py-2 text-slate-700">{gap.risk_level ?? '-'}</td>
                                                        <td className="px-4 py-2 text-slate-700">{gap.recommendation ?? gap.detail ?? '-'}</td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => {
                                        setStep(6);
                                    }}
                                    className="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700"
                                >
                                    Continue to CAR Generation
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setStep(4)}
                                    className="ml-3 rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Previous Step
                                </button>
                            </div>
                        )}

                        {step === 6 && (
                            <div className="space-y-5">
                                <h2 className="text-xl font-semibold text-slate-900">Step 6 - Generate and Download CAR</h2>
                                <p className="text-sm text-slate-600">
                                    Generate a professional CAR draft pre-filled with your assessment data.
                                </p>

                                <button
                                    type="button"
                                    onClick={handleGenerateCar}
                                    disabled={isBusy}
                                    className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {isBusy ? 'Generating PDF...' : 'Download CAR - Ready for NDPC Submission'}
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setStep(5)}
                                    className="ml-3 rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Previous Step
                                </button>
                            </div>
                        )}

                        {step === 7 && (
                            <div className="space-y-5">
                                <h2 className="text-xl font-semibold text-slate-900">Step 7 - Submit to NDPC</h2>
                                <p className="text-sm text-slate-600">
                                    Submit this assessment and instantly update the regulator dashboard.
                                </p>

                                <div className="flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        onClick={handleSubmitToNdpc}
                                        disabled={isBusy}
                                        className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {isBusy ? 'Submitting...' : 'Submit Assessment to NDPC'}
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => setStep(6)}
                                        className="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                                    >
                                        Previous Step
                                    </button>
                                </div>
                            </div>
                        )}
                    </section>
                </div>
            </main>

            <style>{`
                .input {
                    width: 100%;
                    border-radius: 0.75rem;
                    border: 1px solid rgb(203 213 225);
                    background: white;
                    padding: 0.625rem 0.75rem;
                    font-size: 0.875rem;
                    color: rgb(15 23 42);
                }
                .input:focus {
                    border-color: rgb(16 185 129);
                    outline: none;
                    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
                }
            `}</style>
        </>
    );
}

function ProgressBar({ step }: { step: WizardStep }) {
    const steps = [
        'Intake',
        'Classification',
        'Obligations',
        'Upload',
        'Gap Analysis',
        'Generate CAR',
        'Submit NDPC',
    ];

    return (
        <ol className="grid gap-2 rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-7">
            {steps.map((label, index) => {
                const current = index + 1;
                const active = current === step;
                const done = current < step;

                return (
                    <li
                        key={label}
                        className={[
                            'rounded-lg border px-2 py-2 text-center text-xs font-semibold',
                            active
                                ? 'border-emerald-500 bg-emerald-50 text-emerald-700'
                                : done
                                  ? 'border-emerald-300 bg-emerald-100 text-emerald-700'
                                  : 'border-slate-200 bg-slate-50 text-slate-500',
                        ].join(' ')}
                    >
                        {current}. {label}
                    </li>
                );
            })}
        </ol>
    );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <label className="space-y-1">
            <span className="text-xs font-semibold uppercase tracking-wide text-slate-600">{label}</span>
            {children}
        </label>
    );
}

function Toggle({ label, value, onChange }: { label: string; value: boolean; onChange: (value: boolean) => void }) {
    return (
        <button
            type="button"
            onClick={() => onChange(!value)}
            className={[
                'flex items-center justify-between rounded-xl border px-4 py-3 text-left text-sm transition',
                value ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-slate-50 text-slate-700',
            ].join(' ')}
        >
            <span>{label}</span>
            <span className="ml-3 rounded-full bg-white px-2 py-0.5 text-xs font-semibold">{value ? 'Yes' : 'No'}</span>
        </button>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
            <dt className="text-xs uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className="mt-1 text-sm font-semibold text-slate-900">{value}</dd>
        </div>
    );
}

function StatusBadge({ status }: { status: GapItem['status'] }) {
    const map = {
        covered: 'bg-emerald-100 text-emerald-700',
        partial: 'bg-amber-100 text-amber-700',
        not_evidenced: 'bg-red-100 text-red-700',
        not_applicable: 'bg-slate-100 text-slate-700',
    };

    return <span className={`rounded-full px-2 py-1 text-xs font-semibold ${map[status]}`}>{status.replace('_', ' ')}</span>;
}
