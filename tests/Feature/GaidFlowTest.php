<?php

namespace Tests\Feature;

use App\Models\GaidGap;
use App\Models\GaidObligation;
use App\Models\GaidSubmission;
use App\Services\GaidGuardianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;
use Tests\TestCase;

class GaidFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_assess_creates_submission_and_returns_classification_and_obligations(): void
    {
        $classification = [
            'tier' => 'ultra_high',
            'car_filing_fee' => 1000000,
            'filing_deadline' => '2026-03-31',
            'dpo_required' => true,
            'dpia_required' => true,
            'reasons' => ['More than 50,000 data subjects', 'AI systems in operation'],
        ];

        $obligations = [
            [
                'clause_reference' => 'GAID 2025 §9.1',
                'title' => 'Appoint a Data Protection Officer',
                'description' => 'DCPMI entities must appoint a qualified DPO.',
                'plain_language' => 'Assign an accountable DPO and notify NDPC.',
                'deadline' => null,
                'risk' => 'Up to N10m fine or 2% annual revenue.',
                'category' => 'dpo',
                'mandatory' => true,
            ],
            [
                'clause_reference' => 'GAID 2025 §11.2',
                'title' => 'Conduct DPIA before AI deployment',
                'description' => 'AI systems processing personal data require DPIA assessment.',
                'plain_language' => 'Complete DPIA before launching the model.',
                'deadline' => null,
                'risk' => 'AI launch may be blocked by regulator.',
                'category' => 'dpia',
                'mandatory' => true,
            ],
            [
                'clause_reference' => 'GAID 2025 §4.2',
                'title' => 'Maintain data governance controls',
                'description' => 'Document accountability lines and governance controls for personal data processing.',
                'plain_language' => 'Define who is accountable for personal data and document governance.',
                'deadline' => '2026-03-31',
                'risk' => 'Weak oversight and remediation orders.',
                'category' => 'governance',
                'mandatory' => true,
            ],
        ];

        $this->mock(GaidGuardianService::class, function (MockInterface $mock) use ($classification, $obligations): void {
            $mock->shouldReceive('classify')->once()->andReturn($classification);
            $mock->shouldReceive('getObligations')->once()->andReturn($obligations);
        });

        $response = $this->postJson(route('gaid.assess'), $this->assessmentPayload());

        $response->assertCreated()
            ->assertJsonPath('classification.tier', 'ultra_high')
            ->assertJsonCount(3, 'obligations');

        $referenceCode = (string) $response->json('reference_code');
        $this->assertNotSame('', $referenceCode);

        $this->assertDatabaseHas('gaid_submissions', [
            'reference_code' => $referenceCode,
            'status' => 'classified',
            'dcpmi_tier' => 'ultra_high',
        ]);

        $submission = GaidSubmission::query()
            ->where('reference_code', $referenceCode)
            ->firstOrFail();

        $this->assertDatabaseCount('gaid_obligations', 3);
        $this->assertDatabaseHas('gaid_obligations', [
            'gaid_submission_id' => $submission->id,
            'clause_reference' => 'GAID 2025 §4.2',
            'category' => 'other',
        ]);
    }

    public function test_upload_documents_analyses_gaps_and_updates_submission_state(): void
    {
        $submission = $this->createSubmission([
            'status' => 'classified',
            'dcpmi_tier' => 'ultra_high',
            'opa_decision' => ['tier' => 'ultra_high'],
        ]);

        $dpoObligation = GaidObligation::create([
            'gaid_submission_id' => $submission->id,
            'clause_reference' => 'GAID 2025 §9.1',
            'obligation_title' => 'Appoint a Data Protection Officer',
            'obligation_description' => 'DCPMI entities must appoint a qualified DPO.',
            'plain_language_explanation' => 'Assign a DPO and document governance.',
            'deadline' => null,
            'penalty_exposure' => 'Up to N10m fine or 2% annual revenue.',
            'category' => 'dpo',
            'is_mandatory' => true,
            'priority' => 1,
        ]);

        $dpiaObligation = GaidObligation::create([
            'gaid_submission_id' => $submission->id,
            'clause_reference' => 'GAID 2025 §11.2',
            'obligation_title' => 'Conduct DPIA before AI deployment',
            'obligation_description' => 'AI systems processing personal data require DPIA assessment.',
            'plain_language_explanation' => 'Complete DPIA before AI launch.',
            'deadline' => null,
            'penalty_exposure' => 'AI launch may be blocked by regulator.',
            'category' => 'dpia',
            'is_mandatory' => true,
            'priority' => 2,
        ]);

        $analysis = [
            'gaps' => [
                [
                    'obligation_id' => $dpoObligation->id,
                    'status' => 'not_evidenced',
                    'confidence' => 0.2,
                    'detail' => 'No DPO appointment evidence found.',
                    'recommendation' => 'Appoint DPO within 6 months.',
                    'risk_level' => 'high',
                ],
                [
                    'obligation_id' => $dpiaObligation->id,
                    'status' => 'partial',
                    'confidence' => 0.5,
                    'detail' => 'DPIA mention exists but no full report.',
                    'recommendation' => 'Complete DPIA before AI release.',
                    'risk_level' => 'medium',
                ],
            ],
            'compliance_score' => 35,
            'summary' => 'Detected major compliance gaps.',
        ];

        $this->mock(GaidGuardianService::class, function (MockInterface $mock) use ($analysis): void {
            $mock->shouldReceive('analyseGaps')->once()->andReturn($analysis);
        });

        $response = $this->post(route('gaid.upload-documents'), [
            'reference_code' => $submission->reference_code,
            'files' => [UploadedFile::fake()->create('privacy-policy.pdf', 32, 'application/pdf')],
        ]);

        $response->assertOk()
            ->assertJsonPath('analysis.compliance_score', 35);

        $this->assertDatabaseHas('gaid_submissions', [
            'id' => $submission->id,
            'status' => 'gap_analysed',
        ]);

        $this->assertDatabaseHas('gaid_documents', [
            'gaid_submission_id' => $submission->id,
            'processing_status' => 'analysed',
        ]);

        $this->assertDatabaseHas('gaid_gaps', [
            'gaid_submission_id' => $submission->id,
            'gaid_obligation_id' => $dpoObligation->id,
            'status' => 'not_evidenced',
        ]);

        $this->assertDatabaseHas('gaid_gaps', [
            'gaid_submission_id' => $submission->id,
            'gaid_obligation_id' => $dpiaObligation->id,
            'status' => 'partial',
        ]);
    }

    public function test_generate_car_creates_pdf_and_persists_draft_metadata(): void
    {
        $submission = $this->createSubmission([
            'status' => 'gap_analysed',
            'dcpmi_tier' => 'ultra_high',
            'car_filing_fee' => 1000000,
            'filing_deadline' => '2026-03-31',
            'compliance_score' => 35,
            'opa_decision' => ['tier' => 'ultra_high'],
        ]);

        $obligation = GaidObligation::create([
            'gaid_submission_id' => $submission->id,
            'clause_reference' => 'GAID 2025 §9.1',
            'obligation_title' => 'Appoint a Data Protection Officer',
            'obligation_description' => 'DCPMI entities must appoint a qualified DPO.',
            'plain_language_explanation' => 'Assign a DPO and document governance.',
            'deadline' => null,
            'penalty_exposure' => 'Up to N10m fine or 2% annual revenue.',
            'category' => 'dpo',
            'is_mandatory' => true,
            'priority' => 1,
        ]);

        GaidGap::create([
            'gaid_submission_id' => $submission->id,
            'gaid_obligation_id' => $obligation->id,
            'status' => 'not_evidenced',
            'evidence_confidence' => 0.2,
            'gap_detail' => 'No DPO appointment evidence found.',
            'ai_recommendation' => 'Appoint DPO within 6 months.',
            'risk_level' => 'high',
        ]);

        $this->mock(GaidGuardianService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateCar')->once()->andReturn([
                'remediation' => [
                    [
                        'obligation' => 'Appoint a Data Protection Officer',
                        'recommendation' => 'Appoint DPO within 6 months.',
                        'target_date' => '2026-02-15',
                    ],
                ],
            ]);
        });

        $response = $this->post(route('gaid.generate-car'), [
            'reference_code' => $submission->reference_code,
        ]);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));

        $this->assertDatabaseHas('gaid_car_drafts', [
            'gaid_submission_id' => $submission->id,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('gaid_submissions', [
            'id' => $submission->id,
            'status' => 'car_generated',
        ]);
    }

    public function test_submit_marks_submission_as_submitted_and_exposes_it_in_ndpc_data(): void
    {
        $submission = $this->createSubmission([
            'status' => 'car_generated',
            'dcpmi_tier' => 'extra_high',
            'compliance_score' => 62,
            'filing_deadline' => now()->addDays(14)->toDateString(),
        ]);

        $submitResponse = $this->postJson(route('gaid.submit'), [
            'reference_code' => $submission->reference_code,
        ]);

        $submitResponse->assertOk()
            ->assertJsonPath('submission.status', 'submitted');

        $this->assertDatabaseHas('gaid_submissions', [
            'id' => $submission->id,
            'status' => 'submitted',
        ]);

        $dashboardResponse = $this->getJson(route('gaid.ndpc.data'));

        $dashboardResponse->assertOk()
            ->assertJsonPath('stats.total_submissions', 1)
            ->assertJsonPath('stats.submitted_to_ndpc', 1)
            ->assertJsonPath('submissions.0.reference_code', $submission->reference_code);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createSubmission(array $overrides = []): GaidSubmission
    {
        return GaidSubmission::create(array_merge([
            'reference_code' => GaidSubmission::generateReference(),
            'organisation_name' => 'Lagos Fintech Startup Ltd',
            'organisation_email' => 'compliance@lagosfintech.ng',
            'parastatal' => 'NDPC',
            'sector' => 'Fintech',
            'data_subjects' => 75000,
            'uses_ai' => true,
            'processes_sensitive_data' => true,
            'transfers_data_outside_nigeria' => true,
            'questionnaire_answers' => $this->assessmentPayload(),
            'status' => 'draft',
            'opa_decision' => ['tier' => 'ultra_high'],
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function assessmentPayload(): array
    {
        return [
            'organisation_name' => 'Lagos Fintech Startup Ltd',
            'organisation_email' => 'compliance@lagosfintech.ng',
            'parastatal' => 'NDPC',
            'sector' => 'Fintech',
            'data_subjects' => 75000,
            'uses_ai' => true,
            'processes_sensitive_data' => true,
            'transfers_data_outside_nigeria' => true,
            'has_dpo' => false,
            'has_data_retention_policy' => false,
            'has_breach_policy' => false,
            'has_vendor_dp_agreements' => false,
            'annual_revenue_band' => 'N500m-N5bn',
        ];
    }
}
