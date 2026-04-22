<?php

namespace App\Services;

use App\Models\FinancialReport;
use App\Models\ReportField;
use App\Models\RuleViolation;
use App\Models\ValidationRule;

/**
 * THE AUTHORITY — deterministic, auditable, reproducible.
 * AI never touches this layer. Rules decide; AI only explains.
 */
class RuleEngineService
{
    /**
     * Run all active rules against a report.
     * Returns summary: ['passed', 'violations', 'rules_checked']
     */
    public function run(FinancialReport $report): array
    {
        $rules = ValidationRule::active()->ordered()->get();
        $fields = $report->fields()->get()->keyBy('normalized_key');
        $violations = [];

        foreach ($rules as $rule) {
            // Skip rules that target a different report type
            if ($rule->applies_to_type && $rule->applies_to_type !== $report->report_type) {
                continue;
            }

            $result = $this->evaluate($rule, $report, $fields);

            if (!$result['passed']) {
                $violation = RuleViolation::create([
                    'financial_report_id' => $report->id,
                    'validation_rule_id' => $rule->id,
                    'report_field_id' => $result['field_id'] ?? null,
                    'violation_detail' => $result['detail'],
                    'status' => 'open',
                ]);
                $violations[] = $violation;

                // Stop processing on first critical failure if rule demands it
                if ($rule->severity === 'critical' && $rule->action === 'reject') {
                    break;
                }
            }
        }

        $report->update(['status' => count($violations) > 0 ? 'flagged' : 'validated']);

        return [
            'passed' => count($violations) === 0,
            'violations' => $violations,
            'rules_checked' => $rules->count(),
        ];
    }

    // ── Rule evaluator — reads JSON condition from DB ──────────────────────────
    private function evaluate(ValidationRule $rule, FinancialReport $report, $fields): array
    {
        $cond = $rule->condition;

        return match ($cond['type'] ?? 'field_check') {
            'field_required' => $this->checkRequired($cond, $fields),
            'field_range' => $this->checkRange($cond, $fields),
            'field_format' => $this->checkFormat($cond, $fields),
            'cross_field' => $this->checkCrossField($cond, $fields),
            'duplicate_check' => $this->checkDuplicate($cond, $report),
            'total_consistency' => $this->checkTotalConsistency($cond, $report, $fields),
            default => ['passed' => true],
        };
    }

    // ─── Rule Type: field_required ─────────────────────────────────────────────
    // Condition: {"type":"field_required","field":"national_id"}
    private function checkRequired(array $cond, $fields): array
    {
        $field = $fields->get($cond['field']);
        if (!$field || empty($field->normalized_value)) {
            return [
                'passed' => false,
                'detail' => "Required field '{$cond['field']}' is missing or empty.",
                'field_id' => $field?->id,
            ];
        }
        return ['passed' => true];
    }

    // ─── Rule Type: field_range ────────────────────────────────────────────────
    // Condition: {"type":"field_range","field":"total_expenditure","min":0,"max":null}
    private function checkRange(array $cond, $fields): array
    {
        $field = $fields->get($cond['field']);
        if (!$field)
            return ['passed' => true];

        $value = floatval(str_replace([',', ' ', 'NGN', '₦'], '', $field->normalized_value));

        if (isset($cond['min']) && $value < $cond['min']) {
            return ['passed' => false, 'detail' => "'{$cond['field']}' value {$value} is below minimum {$cond['min']}.", 'field_id' => $field->id];
        }
        if (isset($cond['max']) && $value > $cond['max']) {
            return ['passed' => false, 'detail' => "'{$cond['field']}' value {$value} exceeds maximum {$cond['max']}.", 'field_id' => $field->id];
        }
        return ['passed' => true];
    }

    // ─── Rule Type: field_format ───────────────────────────────────────────────
    // Condition: {"type":"field_format","field":"fiscal_year","regex":"^\\d{4}$"}
    private function checkFormat(array $cond, $fields): array
    {
        $field = $fields->get($cond['field']);
        if (!$field)
            return ['passed' => true];

        if (!preg_match('/' . $cond['regex'] . '/', $field->normalized_value)) {
            return ['passed' => false, 'detail' => "'{$cond['field']}' has invalid format.", 'field_id' => $field->id];
        }
        return ['passed' => true];
    }

    // ─── Rule Type: cross_field ────────────────────────────────────────────────
    // Condition: {"type":"cross_field","field_a":"total_revenue","operator":">=","field_b":"total_expenditure"}
    private function checkCrossField(array $cond, $fields): array
    {
        $a = floatval($fields->get($cond['field_a'])?->normalized_value ?? 0);
        $b = floatval($fields->get($cond['field_b'])?->normalized_value ?? 0);

        $passes = match ($cond['operator']) {
            '>=' => $a >= $b,
            '<=' => $a <= $b,
            '==' => abs($a - $b) < 0.01,
            '!=' => abs($a - $b) >= 0.01,
            '>' => $a > $b,
            '<' => $a < $b,
            default => true,
        };

        if (!$passes) {
            return ['passed' => false, 'detail' => "Cross-field check failed: {$cond['field_a']} {$cond['operator']} {$cond['field_b']} ({$a} vs {$b})."];
        }
        return ['passed' => true];
    }

    // ─── Rule Type: duplicate_check ───────────────────────────────────────────
    // Condition: {"type":"duplicate_check","match_fields":["source_agency","fiscal_year","report_type"]}
    private function checkDuplicate(array $cond, FinancialReport $report): array
    {
        $query = FinancialReport::where('id', '!=', $report->id)
            ->whereNotIn('status', ['rejected']);

        foreach ($cond['match_fields'] as $f) {
            $query->where($f, $report->$f);
        }

        if ($query->exists()) {
            return ['passed' => false, 'detail' => 'A duplicate report exists with the same agency, fiscal year, and type.'];
        }
        return ['passed' => true];
    }

    // ─── Rule Type: total_consistency ─────────────────────────────────────────
    // Verifies sum of line items equals declared total
    private function checkTotalConsistency(array $cond, FinancialReport $report, $fields): array
    {
        $declaredTotal = floatval($report->total_amount);
        $sumField = $fields->get($cond['sum_field'] ?? 'total_amount');

        if (!$sumField)
            return ['passed' => true];

        $parsed = floatval(str_replace([',', ' ', 'NGN', '₦'], '', $sumField->normalized_value));
        $tolerance = $cond['tolerance'] ?? 1.0;

        if (abs($parsed - $declaredTotal) > $tolerance) {
            return [
                'passed' => false,
                'detail' => "Declared total {$declaredTotal} does not match extracted total {$parsed}.",
                'field_id' => $sumField->id,
            ];
        }
        return ['passed' => true];
    }
}
