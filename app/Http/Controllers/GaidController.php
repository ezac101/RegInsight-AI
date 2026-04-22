<?php

namespace App\Http\Controllers;

use App\Models\GaidCarDraft;
use App\Models\GaidDocument;
use App\Models\GaidGap;
use App\Models\GaidObligation;
use App\Models\GaidSubmission;
use App\Services\GaidGuardianService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GaidController extends Controller
{
    /** @var array<int, string> */
    private const ALLOWED_OBLIGATION_CATEGORIES = [
        'dpo',
        'dpia',
        'car',
        'breach',
        'consent',
        'retention',
        'registration',
        'transfer',
        'other',
    ];

    public function __construct(
        private readonly GaidGuardianService $guardian,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('Gaid/Index');
    }

    public function ndpc(): Response
    {
        return Inertia::render('Gaid/NdpcDashboard');
    }

    public function show(string $referenceCode): JsonResponse
    {
        $submission = GaidSubmission::query()
            ->where('reference_code', $referenceCode)
            ->with(['documents', 'obligations', 'gaps.obligation', 'carDraft'])
            ->firstOrFail();

        return response()->json(['submission' => $submission]);
    }

    public function assess(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organisation_name' => 'required|string|max:255',
            'organisation_email' => 'required|email|max:255',
            'sector' => [
                'required',
                Rule::in([
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
                ]),
            ],
            'data_subjects' => 'required|integer|min:0',
            'uses_ai' => 'required|boolean',
            'processes_sensitive_data' => 'required|boolean',
            'transfers_data_outside_nigeria' => 'required|boolean',
            'has_dpo' => 'nullable|boolean',
            'has_data_retention_policy' => 'nullable|boolean',
            'has_breach_policy' => 'nullable|boolean',
            'has_vendor_dp_agreements' => 'nullable|boolean',
            'annual_revenue_band' => [
                'nullable',
                Rule::in([
                    'Below N50m',
                    'N50m-N500m',
                    'N500m-N5bn',
                    'Above N5bn',
                ]),
            ],
        ]);

        $validated['parastatal'] = 'NDPC';

        DB::beginTransaction();

        try {
            $submission = GaidSubmission::create([
                'organisation_name' => $validated['organisation_name'],
                'organisation_email' => $validated['organisation_email'],
                'parastatal' => $validated['parastatal'],
                'sector' => $validated['sector'],
                'data_subjects' => $validated['data_subjects'],
                'uses_ai' => $validated['uses_ai'],
                'processes_sensitive_data' => $validated['processes_sensitive_data'],
                'transfers_data_outside_nigeria' => $validated['transfers_data_outside_nigeria'],
                'questionnaire_answers' => $validated,
                'reference_code' => GaidSubmission::generateReference(),
                'status' => 'draft',
            ]);

            $classification = $this->guardian->classify($validated);
            $obligations = $this->guardian->getObligations([
                ...$validated,
                'classification' => $classification,
                'reference_code' => $submission->reference_code,
            ]);

            $submission->update([
                'dcpmi_tier' => $classification['tier'],
                'car_filing_fee' => $classification['car_filing_fee'],
                'filing_deadline' => $classification['filing_deadline'],
                'dpo_required' => $classification['dpo_required'],
                'dpia_required' => $classification['dpia_required'],
                'opa_decision' => $classification,
                'obligations' => $obligations,
                'status' => 'classified',
            ]);

            GaidObligation::query()->where('gaid_submission_id', $submission->id)->delete();

            foreach ($obligations as $index => $obligation) {
                GaidObligation::create([
                    'gaid_submission_id' => $submission->id,
                    'clause_reference' => $obligation['clause_reference'] ?? 'GAID 2025',
                    'obligation_title' => $obligation['title'] ?? 'Regulatory obligation',
                    'obligation_description' => $obligation['description'] ?? '',
                    'plain_language_explanation' => $obligation['plain_language'] ?? '',
                    'deadline' => $obligation['deadline'] ?? null,
                    'penalty_exposure' => $obligation['risk'] ?? ($obligation['penalty'] ?? null),
                    'category' => $this->normalizeObligationCategory($obligation['category'] ?? null),
                    'is_mandatory' => (bool) ($obligation['mandatory'] ?? true),
                    'priority' => $index + 1,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Assessment complete.',
                'reference_code' => $submission->reference_code,
                'classification' => $classification,
                'obligations' => $obligations,
                'submission' => $submission->fresh(['obligations']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function uploadDocuments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference_code' => 'required|string',
            'files' => 'required|array|min:1|max:3',
            'files.*' => 'required|file|mimes:pdf|max:20480',
        ]);

        $submission = GaidSubmission::query()
            ->where('reference_code', $validated['reference_code'])
            ->with(['obligations'])
            ->firstOrFail();

        $obligations = $submission->obligations()->get();

        $uploaded = collect($validated['files'])->map(function ($file) use ($submission) {
            $path = $file->store("gaid/{$submission->id}/docs", 'local');
            $hash = hash_file('sha256', Storage::disk('local')->path($path));

            return GaidDocument::create([
                'gaid_submission_id' => $submission->id,
                'parastatal' => $submission->parastatal,
                'document_type' => 'other',
                'document_label' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_hash' => $hash,
                'file_size_kb' => (int) ceil($file->getSize() / 1024),
                'mime_type' => $file->getMimeType(),
                'processing_status' => 'pending',
            ]);
        })->values();

        $analysis = $this->guardian->analyseGaps([
            'reference_code' => $submission->reference_code,
            'classification' => $submission->opa_decision,
            'obligations' => $obligations->map(function (GaidObligation $obligation) {
                return [
                    'id' => $obligation->id,
                    'clause_reference' => $obligation->clause_reference,
                    'title' => $obligation->obligation_title,
                    'description' => $obligation->obligation_description,
                    'plain_language' => $obligation->plain_language_explanation,
                    'deadline' => optional($obligation->deadline)?->toDateString(),
                    'risk' => $obligation->penalty_exposure,
                ];
            })->values()->all(),
            'files' => $uploaded->map(fn(GaidDocument $document) => [
                'id' => $document->id,
                'file_path' => Storage::disk('local')->path($document->file_path),
                'file_name' => $document->file_name,
            ])->all(),
        ]);

        $obligationMap = $obligations->keyBy('id');

        GaidGap::query()->where('gaid_submission_id', $submission->id)->delete();

        foreach ($analysis['gaps'] ?? [] as $gap) {
            $obligationId = $gap['obligation_id'] ?? null;

            if ($obligationId === null && isset($gap['clause_reference'])) {
                $matched = $obligations
                    ->firstWhere('clause_reference', $gap['clause_reference']);
                $obligationId = $matched?->id;
            }

            if ($obligationId === null || !$obligationMap->has($obligationId)) {
                continue;
            }

            GaidGap::create([
                'gaid_submission_id' => $submission->id,
                'gaid_obligation_id' => $obligationId,
                'gaid_document_id' => $gap['document_id'] ?? null,
                'status' => $gap['status'] ?? 'not_evidenced',
                'evidence_confidence' => (float) ($gap['confidence'] ?? 0),
                'gap_detail' => $gap['detail'] ?? null,
                'ai_recommendation' => $gap['recommendation'] ?? null,
                'risk_level' => $gap['risk_level'] ?? 'medium',
            ]);
        }

        GaidDocument::query()
            ->whereIn('id', $uploaded->pluck('id'))
            ->update(['processing_status' => 'analysed']);

        $submission->update([
            'gap_analysis' => $analysis['gaps'] ?? [],
            'compliance_score' => $analysis['compliance_score'] ?? 0,
            'status' => 'gap_analysed',
        ]);

        return response()->json([
            'message' => 'Documents analysed successfully.',
            'reference_code' => $submission->reference_code,
            'analysis' => $analysis,
            'submission' => $submission->fresh(['documents', 'gaps.obligation']),
        ]);
    }

    public function generateCar(Request $request)
    {
        $validated = $request->validate([
            'reference_code' => 'required|string',
        ]);

        $submission = GaidSubmission::query()
            ->where('reference_code', $validated['reference_code'])
            ->with(['obligations', 'gaps.obligation'])
            ->firstOrFail();

        $obligations = $submission->obligations()->get();

        $carData = $this->guardian->generateCar([
            'reference_code' => $submission->reference_code,
            'organisation_name' => $submission->organisation_name,
            'organisation_email' => $submission->organisation_email,
            'parastatal' => $submission->parastatal,
            'sector' => $submission->sector,
            'classification' => $submission->opa_decision,
            'obligations' => $obligations->toArray(),
            'gaps' => $submission->gaps->map(function (GaidGap $gap) {
                return [
                    'status' => $gap->status,
                    'risk_level' => $gap->risk_level,
                    'detail' => $gap->gap_detail,
                    'recommendation' => $gap->ai_recommendation,
                    'obligation' => [
                        'title' => $gap->obligation?->obligation_title,
                        'clause_reference' => $gap->obligation?->clause_reference,
                    ],
                ];
            })->values()->all(),
            'compliance_score' => $submission->compliance_score,
        ]);

        $pdf = Pdf::loadView('pdf.car', [
            'submission' => $submission,
            'carData' => $carData,
        ]);

        $filePath = "gaid/{$submission->id}/car/CAR_{$submission->reference_code}.pdf";
        Storage::put($filePath, $pdf->output());

        GaidCarDraft::updateOrCreate(
            ['gaid_submission_id' => $submission->id],
            [
                'file_path' => $filePath,
                'car_data' => $carData,
                'compliance_score' => $submission->compliance_score,
                'status' => 'draft',
                'downloaded_at' => now(),
            ]
        );

        $submission->update([
            'status' => 'car_generated',
            'car_generated_at' => now(),
        ]);

        return $pdf->download("CAR_{$submission->reference_code}.pdf");
    }

    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference_code' => 'required|string',
        ]);

        $submission = GaidSubmission::query()
            ->where('reference_code', $validated['reference_code'])
            ->firstOrFail();

        $submission->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Assessment submitted to NDPC dashboard.',
            'submission' => $submission,
        ]);
    }

    public function ndpcData(): JsonResponse
    {
        $stats = [
            'total_submissions' => GaidSubmission::query()->count(),
            'submitted_to_ndpc' => GaidSubmission::query()->where('status', 'submitted')->count(),
            'cars_generated' => GaidSubmission::query()->whereIn('status', ['car_generated', 'submitted'])->count(),
            'deadline_at_risk' => GaidSubmission::query()
                ->whereDate('filing_deadline', '<=', now()->addDays(30))
                ->whereNotIn('status', ['submitted'])
                ->count(),
            'anomalies' => GaidSubmission::query()
                ->where('data_subjects', '>=', 100000)
                ->where(function ($query) {
                    $query->where('dpo_required', true)
                        ->where(function ($nested) {
                            $nested->whereNull('compliance_score')->orWhere('compliance_score', '<', 40);
                        });
                })
                ->count(),
            'sector_heatmap' => GaidSubmission::query()
                ->selectRaw('sector, round(avg(coalesce(compliance_score, 0)), 2) as compliance_percent, count(*) as organisations')
                ->groupBy('sector')
                ->orderBy('sector')
                ->get(),
        ];

        $submissions = GaidSubmission::query()
            ->select([
                'id',
                'reference_code',
                'organisation_name',
                'sector',
                'dcpmi_tier',
                'compliance_score',
                'status',
                'filing_deadline',
                'updated_at',
            ])
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'stats' => $stats,
            'submissions' => $submissions,
        ]);
    }

    private function normalizeObligationCategory(?string $category): string
    {
        if ($category !== null && in_array($category, self::ALLOWED_OBLIGATION_CATEGORIES, true)) {
            return $category;
        }

        return 'other';
    }
}
