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

        throw new \RuntimeException('AI service failed to return classification. Check Python service health and configuration.');
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

        $detail = is_array($response->json()) ? ($response->json('detail') ?? null) : null;
        $suffix = is_string($detail) && $detail !== '' ? " {$detail}" : '';

        throw new \RuntimeException('AI service failed to return obligations. Ensure GAID regulation PDF is loaded and Python service is running.'.$suffix);
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
}
