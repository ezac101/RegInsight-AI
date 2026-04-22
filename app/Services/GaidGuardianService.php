<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GaidGuardianService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.python_ai.url', 'http://127.0.0.1:8001'), '/');
    }

    public function classify(array $payload): array
    {
        $response = Http::timeout(20)->acceptJson()->post("{$this->baseUrl}/classify", $payload);

        if ($response->successful() && is_array($response->json())) {
            return $response->json();
        }

        return $this->fallbackClassify($payload);
    }

    public function getObligations(array $payload): array
    {
        $response = Http::timeout(40)->acceptJson()->post("{$this->baseUrl}/get-obligations", $payload);

        if ($response->successful()) {
            $data = $response->json();

            if (is_array($data['obligations'] ?? null)) {
                return $data['obligations'];
            }
        }

        throw new \RuntimeException('AI service failed to return obligations. Ensure GAID regulation PDF is loaded and Python service is running.');
    }

    public function analyseGaps(array $payload): array
    {
        $response = Http::timeout(60)->acceptJson()->post("{$this->baseUrl}/analyse-gaps", $payload);

        if ($response->successful() && is_array($response->json())) {
            $data = $response->json();
            return [
                'gaps' => is_array($data['gaps'] ?? null) ? $data['gaps'] : [],
                'compliance_score' => (float) ($data['compliance_score'] ?? 0),
                'summary' => (string) ($data['summary'] ?? ''),
            ];
        }

        throw new \RuntimeException('AI service failed to return gap analysis. Check Groq API configuration and Python service health.');
    }

    public function generateCar(array $payload): array
    {
        $response = Http::timeout(40)->acceptJson()->post("{$this->baseUrl}/generate-car", $payload);

        if ($response->successful()) {
            $data = $response->json();
            if (is_array($data['car_data'] ?? null)) {
                return $data['car_data'];
            }
        }

        throw new \RuntimeException('AI service failed to return CAR data.');
    }

    private function fallbackClassify(array $payload): array
    {
        $subjects = (int) ($payload['data_subjects'] ?? 0);
        $sector = (string) ($payload['sector'] ?? 'Other');
        $usesAi = (bool) ($payload['uses_ai'] ?? false);
        $sensitive = (bool) ($payload['processes_sensitive_data'] ?? false);
        $transfer = (bool) ($payload['transfers_data_outside_nigeria'] ?? false);

        $tier = 'not_classified';
        $fee = 0;
        $deadline = null;

        if ($subjects >= 50000 || ($sector === 'Fintech' && $subjects >= 25000) || ($usesAi && $sensitive && $subjects >= 10000)) {
            $tier = 'ultra_high';
            $fee = 1000000;
            $deadline = '2026-03-31';
        } elseif ($subjects >= 10000 || ($sensitive && $transfer)) {
            $tier = 'extra_high';
            $fee = 500000;
            $deadline = '2026-03-31';
        } elseif ($subjects >= 1000) {
            $tier = 'ordinary_high';
            $fee = 200000;
            $deadline = '2026-06-30';
        }

        $triggers = [];
        if ($subjects >= 50000) {
            $triggers[] = 'More than 50,000 data subjects';
        }
        if ($sector === 'Fintech') {
            $triggers[] = 'Fintech sector risk weighting';
        }
        if ($usesAi) {
            $triggers[] = 'AI systems in operation';
        }
        if ($sensitive) {
            $triggers[] = 'Sensitive personal data processing';
        }
        if ($transfer) {
            $triggers[] = 'Cross-border transfer exposure';
        }

        return [
            'tier' => $tier,
            'car_filing_fee' => $fee,
            'filing_deadline' => $deadline,
            'dpo_required' => $tier !== 'not_classified',
            'dpia_required' => $usesAi,
            'reasons' => $triggers,
            'deterministic' => true,
        ];
    }

}
