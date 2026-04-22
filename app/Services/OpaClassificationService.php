<?php

namespace App\Services;

/**
 * Deterministic OPA-style DCPMI classification.
 * Encoded directly from GAID 2025 classification criteria.
 * AI never touches this layer — rules decide tier.
 */
class OpaClassificationService
{
    /**
     * Evaluate questionnaire answers and return DCPMI classification.
     * This mirrors what OPA (Open Policy Agent) would evaluate.
     */
    public function classify(array $answers): array
    {
        $subjects  = (int) $answers['data_subjects'];
        $usesAi    = (bool) $answers['uses_ai'];
        $sensitive = (bool) $answers['processes_sensitive_data'];
        $transfers = (bool) $answers['transfers_data_outside_nigeria'];

        // ── GAID 2025 Classification Logic ────────────────────────────────────
        // Ultra-High-Level DCPMI: ≥500,000 subjects OR (≥100k + sensitive + AI)
        if ($subjects >= 500_000 || ($subjects >= 100_000 && $sensitive && $usesAi)) {
            return $this->buildDecision('ultra_high', $answers, [
                'car_filing_fee'  => 1_000_000,
                'filing_deadline' => '2026-03-31',
                'dpo_required'    => true,
                'dpia_required'   => $usesAi,
                'clauses'         => ['GAID 2025 §3.1', 'GAID 2025 §4.2', 'GAID 2025 §5.1'],
                'note'            => 'Ultra-High-Level DCPMI — highest regulatory scrutiny.',
            ]);
        }

        // Extra-High-Level: 100k–499k OR (50k + sensitive OR transfers)
        if ($subjects >= 100_000 || ($subjects >= 50_000 && ($sensitive || $transfers))) {
            return $this->buildDecision('extra_high', $answers, [
                'car_filing_fee'  => 500_000,
                'filing_deadline' => '2026-03-31',
                'dpo_required'    => true,
                'dpia_required'   => $usesAi && $sensitive,
                'clauses'         => ['GAID 2025 §3.2', 'GAID 2025 §4.3'],
                'note'            => 'Extra-High-Level DCPMI.',
            ]);
        }

        // Ordinary-High-Level: 10k–49k
        if ($subjects >= 10_000) {
            return $this->buildDecision('ordinary_high', $answers, [
                'car_filing_fee'  => 200_000,
                'filing_deadline' => '2026-06-30',
                'dpo_required'    => $sensitive,
                'dpia_required'   => $usesAi,
                'clauses'         => ['GAID 2025 §3.3'],
                'note'            => 'Ordinary High-Level DCPMI.',
            ]);
        }

        // Below threshold
        return $this->buildDecision('not_classified', $answers, [
            'car_filing_fee'  => 0,
            'filing_deadline' => null,
            'dpo_required'    => false,
            'dpia_required'   => false,
            'clauses'         => [],
            'note'            => 'Below DCPMI threshold. Voluntary compliance recommended.',
        ]);
    }

    private function buildDecision(string $tier, array $answers, array $params): array
    {
        $obligations = $this->deriveObligations($tier, $answers, $params);

        return [
            'tier'             => $tier,
            'car_filing_fee'   => $params['car_filing_fee'],
            'filing_deadline'  => $params['filing_deadline'],
            'dpo_required'     => $params['dpo_required'],
            'dpia_required'    => $params['dpia_required'],
            'triggering_clauses' => $params['clauses'],
            'note'             => $params['note'],
            'obligations'      => $obligations,
            'decided_at'       => now()->toIso8601String(),
            'engine'           => 'OPA-PHP v1.0 (deterministic)',
        ];
    }

    private function deriveObligations(string $tier, array $answers, array $params): array
    {
        $list = [];

        if ($tier === 'not_classified') return $list;

        $list[] = ['title' => 'Register with NDPC as DCPMI',          'clause' => 'GAID 2025 §3.1',    'deadline' => $params['filing_deadline'], 'category' => 'registration'];
        $list[] = ['title' => 'File Compliance Audit Return (CAR)',    'clause' => 'GAID 2025 §8.1',    'deadline' => $params['filing_deadline'], 'category' => 'car'];
        $list[] = ['title' => 'Implement 72-hour breach notification', 'clause' => 'GAID 2025 §10.3',   'deadline' => null,                       'category' => 'breach'];
        $list[] = ['title' => 'Maintain Data Retention Policy',       'clause' => 'GAID 2025 §7.2',    'deadline' => null,                       'category' => 'retention'];
        $list[] = ['title' => 'Establish Consent Framework',          'clause' => 'GAID 2025 §6.1',    'deadline' => null,                       'category' => 'consent'];

        if ($params['dpo_required']) {
            $list[] = ['title' => 'Appoint Data Protection Officer (DPO)', 'clause' => 'GAID 2025 §9.1', 'deadline' => now()->addMonths(6)->toDateString(), 'category' => 'dpo'];
        }

        if ($params['dpia_required']) {
            $list[] = ['title' => 'Conduct DPIA before AI deployment', 'clause' => 'GAID 2025 §11.2', 'deadline' => null, 'category' => 'dpia'];
        }

        if ($answers['transfers_data_outside_nigeria'] ?? false) {
            $list[] = ['title' => 'Obtain NDPC cross-border transfer authorisation', 'clause' => 'GAID 2025 §13.1', 'deadline' => null, 'category' => 'transfer'];
        }

        return $list;
    }
}
