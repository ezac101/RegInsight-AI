<?php

namespace App\Services;

use App\Models\GaidCarDraft;
use App\Models\GaidDocument;
use App\Models\GaidGap;
use App\Models\GaidObligation;
use App\Models\GaidSubmission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel → Python AI bridge for GAID Guardian.
 * Python handles: PDF extraction, RAG obligation lookup, gap analysis, CAR generation.
 */
class GaidPythonService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.python_ai.url', 'http://localhost:8001');
    }

    // ── Step 3: RAG obligation extraction ─────────────────────────────────────
    public function extractObligations(GaidSubmission $submission): void
    {
        try {
            $response = Http::timeout(120)->post("{$this->baseUrl}/gaid/obligations", [
                'submission_id' => $submission->id,
                'tier'          => $submission->dcpmi_tier,
                'sector'        => $submission->sector,
                'parastatal'    => $submission->parastatal,
                'uses_ai'       => $submission->uses_ai,
                'sensitive'     => $submission->processes_sensitive_data,
                'transfers'     => $submission->transfers_data_outside_nigeria,
                'opa_decision'  => $submission->opa_decision,
            ]);

            foreach ($response->json('obligations', []) as $i => $ob) {
                GaidObligation::create([
                    'gaid_submission_id'        => $submission->id,
                    'clause_reference'          => $ob['clause_reference'],
                    'obligation_title'          => $ob['title'],
                    'obligation_description'    => $ob['description'],
                    'plain_language_explanation'=> $ob['plain_language'],
                    'deadline'                  => $ob['deadline'] ?? null,
                    'penalty_exposure'          => $ob['penalty'] ?? null,
                    'category'                  => $ob['category'],
                    'is_mandatory'              => $ob['mandatory'] ?? true,
                    'priority'                  => $i + 1,
                ]);
            }

            $submission->update(['status' => 'obligations_extracted']);

        } catch (\Throwable $e) {
            Log::error("GAID obligation extraction failed [{$submission->reference_code}]: {$e->getMessage()}");
        }
    }

    // ── Step 4/5: Document analysis + gap detection ───────────────────────────
    public function analyseDocument(GaidDocument $doc, GaidSubmission $submission): void
    {
        try {
            $doc->update(['processing_status' => 'processing']);

            $response = Http::timeout(180)->post("{$this->baseUrl}/gaid/analyse-document", [
                'document_id'   => $doc->id,
                'submission_id' => $submission->id,
                'file_path'     => storage_path("app/{$doc->file_path}"),
                'document_type' => $doc->document_type,
                'parastatal'    => $doc->parastatal,
                'tier'          => $submission->dcpmi_tier,
            ]);

            $data = $response->json();

            $doc->update([
                'extracted_text'    => $data['extracted_text'] ?? null,
                'ai_analysis'       => $data['analysis'] ?? [],
                'clauses_covered'   => $data['clauses_covered'] ?? [],
                'coverage_score'    => $data['coverage_score'] ?? 0,
                'processing_status' => 'analysed',
            ]);

            // Auto-run gap analysis after each document upload
            $this->runGapAnalysis($submission->fresh(['obligations', 'documents']));

        } catch (\Throwable $e) {
            $doc->update(['processing_status' => 'failed', 'processing_error' => $e->getMessage()]);
            Log::error("GAID doc analysis failed [doc#{$doc->id}]: {$e->getMessage()}");
        }
    }

    // ── Step 5: Full gap analysis ─────────────────────────────────────────────
    public function runGapAnalysis(GaidSubmission $submission): void
    {
        try {
            $response = Http::timeout(120)->post("{$this->baseUrl}/gaid/gap-analysis", [
                'submission_id' => $submission->id,
                'tier'          => $submission->dcpmi_tier,
                'obligations'   => $submission->obligations->map(fn($o) => [
                    'id'        => $o->id,
                    'title'     => $o->obligation_title,
                    'category'  => $o->category,
                    'clause'    => $o->clause_reference,
                ])->toArray(),
                'documents' => $submission->documents->where('processing_status', 'analysed')->map(fn($d) => [
                    'id'            => $d->id,
                    'type'          => $d->document_type,
                    'analysis'      => $d->ai_analysis,
                    'clauses'       => $d->clauses_covered,
                ])->values()->toArray(),
            ]);

            // Clear old gaps then re-insert
            GaidGap::where('gaid_submission_id', $submission->id)->delete();

            $score = 0;
            $gaps  = $response->json('gaps', []);

            foreach ($gaps as $gap) {
                GaidGap::create([
                    'gaid_submission_id'  => $submission->id,
                    'gaid_obligation_id'  => $gap['obligation_id'],
                    'gaid_document_id'    => $gap['document_id'] ?? null,
                    'status'              => $gap['status'],
                    'evidence_confidence' => $gap['confidence'] ?? 0,
                    'gap_detail'          => $gap['detail'] ?? null,
                    'ai_recommendation'   => $gap['recommendation'] ?? null,
                    'risk_level'          => $gap['risk_level'] ?? 'medium',
                ]);
            }

            $score = $response->json('compliance_score', 0);
            $submission->update([
                'compliance_score' => $score,
                'gap_analysis'     => $gaps,
                'status'           => 'gap_analysed',
            ]);

        } catch (\Throwable $e) {
            Log::error("GAID gap analysis failed [{$submission->reference_code}]: {$e->getMessage()}");
        }
    }

    // ── Step 6: CAR draft generation ──────────────────────────────────────────
    public function generateCar(GaidSubmission $submission): void
    {
        try {
            $response = Http::timeout(180)->post("{$this->baseUrl}/gaid/generate-car", [
                'submission_id'    => $submission->id,
                'reference_code'   => $submission->reference_code,
                'organisation'     => $submission->organisation_name,
                'parastatal'       => $submission->parastatal,
                'sector'           => $submission->sector,
                'tier'             => $submission->dcpmi_tier,
                'car_filing_fee'   => $submission->car_filing_fee,
                'filing_deadline'  => $submission->filing_deadline?->toDateString(),
                'obligations'      => $submission->obligations->toArray(),
                'gaps'             => $submission->gaps->load('obligation')->toArray(),
                'compliance_score' => $submission->compliance_score,
            ]);

            $carData  = $response->json('car_data', []);
            $filePath = $response->json('file_path');

            GaidCarDraft::updateOrCreate(
                ['gaid_submission_id' => $submission->id],
                [
                    'file_path'        => $filePath,
                    'car_data'         => $carData,
                    'compliance_score' => $submission->compliance_score,
                    'status'           => 'draft',
                ]
            );

            $submission->update([
                'status'           => 'car_generated',
                'car_generated_at' => now(),
            ]);

        } catch (\Throwable $e) {
            Log::error("GAID CAR generation failed [{$submission->reference_code}]: {$e->getMessage()}");
        }
    }
}
