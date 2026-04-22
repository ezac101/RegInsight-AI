<?php

namespace App\Services;

use App\Models\AiProcessingJob;
use App\Models\FinancialReport;
use App\Models\ReportField;
use App\Models\ReportInsight;
use App\Models\RuleViolation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bridge to the Python AI microservice.
 * Laravel calls Python; Python calls Claude API + spaCy + LangChain.
 * Results are stored back in DB by this service.
 */
class AiPythonService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.python_ai.url', 'http://localhost:8001');
    }

    // ── Step 1: Extract fields from PDF + normalize ───────────────────────────
    public function extractAndNormalize(FinancialReport $report): void
    {
        $job = $this->createJob($report, 'normalize');

        try {
            $report->update(['status' => 'processing']);

            $response = Http::timeout(120)
                ->post("{$this->baseUrl}/extract", [
                    'report_id' => $report->id,
                    'file_path' => storage_path("app/{$report->file_path}"),
                    'agency' => $report->source_agency,
                    'type' => $report->report_type,
                ]);

            $data = $response->json();

            // Persist normalized fields
            foreach ($data['fields'] ?? [] as $field) {
                ReportField::updateOrCreate(
                    ['financial_report_id' => $report->id, 'original_key' => $field['original_key']],
                    [
                        'normalized_key' => $field['normalized_key'],
                        'original_value' => $field['original_value'],
                        'normalized_value' => $field['normalized_value'],
                        'field_type' => $field['field_type'] ?? 'text',
                        'ai_confidence' => $field['confidence'] ?? 0,
                    ]
                );
            }

            $report->update([
                'normalized_data' => $data['summary'] ?? [],
                'total_amount' => $data['total_amount'] ?? null,
                'status' => 'pending',  // ready for rule engine
            ]);

            $this->completeJob($job, $data);

        } catch (\Throwable $e) {
            $this->failJob($job, $e->getMessage());
            Log::error("AI extraction failed for report {$report->id}: {$e->getMessage()}");
        }
    }

    // ── Step 2: AI explains rule violations in plain English ──────────────────
    public function explainViolations(FinancialReport $report): void
    {
        $violations = $report->violations()->with('rule', 'field')->open()->get();
        if ($violations->isEmpty())
            return;

        $job = $this->createJob($report, 'explain');

        try {
            $response = Http::timeout(60)
                ->post("{$this->baseUrl}/explain", [
                    'report_id' => $report->id,
                    'violations' => $violations->map(fn($v) => [
                        'id' => $v->id,
                        'rule_code' => $v->rule->code,
                        'rule_name' => $v->rule->name,
                        'severity' => $v->rule->severity,
                        'violation_detail' => $v->violation_detail,
                        'field_value' => $v->field?->normalized_value,
                    ])->toArray(),
                ]);

            foreach ($response->json('explanations', []) as $exp) {
                RuleViolation::where('id', $exp['violation_id'])
                    ->update([
                        'ai_explanation' => $exp['explanation'],
                        'ai_confidence' => $exp['confidence'],
                    ]);
            }

            $this->completeJob($job, $response->json());

        } catch (\Throwable $e) {
            $this->failJob($job, $e->getMessage());
        }
    }

    // ── Step 3: Generate AI insights for dashboard ────────────────────────────
    public function generateInsights(FinancialReport $report): void
    {
        $job = $this->createJob($report, 'insight');

        try {
            $response = Http::timeout(90)
                ->post("{$this->baseUrl}/insights", [
                    'report_id' => $report->id,
                    'normalized_data' => $report->normalized_data,
                    'total_amount' => $report->total_amount,
                    'fiscal_year' => $report->fiscal_year,
                    'agency' => $report->source_agency,
                    'report_type' => $report->report_type,
                ]);

            foreach ($response->json('insights', []) as $insight) {
                ReportInsight::create([
                    'financial_report_id' => $report->id,
                    'insight_type' => $insight['type'],
                    'content' => $insight['content'],
                    'supporting_data' => $insight['data'] ?? [],
                    'confidence_score' => $insight['confidence'] ?? 0,
                    'model_used' => $insight['model'] ?? null,
                    'is_shown_on_dashboard' => true,
                ]);
            }

            $this->completeJob($job, $response->json());

        } catch (\Throwable $e) {
            $this->failJob($job, $e->getMessage());
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createJob(FinancialReport $report, string $type): AiProcessingJob
    {
        return AiProcessingJob::create([
            'financial_report_id' => $report->id,
            'job_type' => $type,
            'status' => 'running',
        ]);
    }

    private function completeJob(AiProcessingJob $job, array $output): void
    {
        $job->update([
            'status' => 'completed',
            'output_payload' => $output,
            'duration_seconds' => now()->diffInSeconds($job->created_at),
        ]);
    }

    private function failJob(AiProcessingJob $job, string $error): void
    {
        $job->update(['status' => 'failed', 'error_message' => $error]);
    }
}
