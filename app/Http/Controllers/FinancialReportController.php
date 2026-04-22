<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FinancialReport;
use App\Models\ValidationRule;
use App\Services\AiPythonService;
use App\Services\RuleEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FinancialReportController extends Controller
{
    public function __construct(
        private readonly AiPythonService $aiService,
        private readonly RuleEngineService $ruleEngine
    ) {
    }

    // ── GET /api/reports ──────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $reports = FinancialReport::with(['submitter', 'violations' => fn($q) => $q->open()])
            ->when($request->status, fn($q, $v) => $q->where('status', $v))
            ->when($request->agency, fn($q, $v) => $q->where('source_agency', $v))
            ->when($request->fiscal_year, fn($q, $v) => $q->where('fiscal_year', $v))
            ->when($request->report_type, fn($q, $v) => $q->where('report_type', $v))
            ->latest()
            ->paginate(20);

        return response()->json($reports);
    }

    // ── POST /api/reports ─────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'source_agency' => 'required|string|max:100',
            'report_type' => 'required|in:budget,audit,revenue,expenditure,compliance,quarterly,annual',
            'fiscal_year' => 'required|string|max:10',
            'quarter' => 'nullable|in:Q1,Q2,Q3,Q4',
            'file' => 'required|file|mimes:pdf|max:20480',
            'currency' => 'nullable|string|max:5',
        ]);

        DB::beginTransaction();
        try {
            $path = $request->file('file')->store('reports', 'local');
            $hash = hash_file('sha256', storage_path("app/{$path}"));

            $report = FinancialReport::create([
                ...$validated,
                'file_path' => $path,
                'file_hash' => $hash,
                'status' => 'pending',
                'submitted_by' => auth()->id(),
                'currency' => $validated['currency'] ?? 'NGN',
            ]);

            AuditLog::record('FinancialReport', $report->id, 'created', [], $report->toArray());

            // Kick off async AI extraction via Python service
            dispatch(fn() => $this->aiService->extractAndNormalize($report));

            DB::commit();

            return response()->json([
                'message' => 'Report uploaded. AI extraction queued.',
                'report' => $report,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── GET /api/reports/{id} ─────────────────────────────────────────────────
    public function show(FinancialReport $report): JsonResponse
    {
        $report->load([
            'fields',
            'violations.rule',
            'violations.field',
            'insights',
            'aiJobs',
            'submitter',
            'reviewer',
        ]);

        return response()->json($report);
    }

    // ── POST /api/reports/{id}/validate ───────────────────────────────────────
    // Runs the deterministic rule engine against the report
    public function validate(FinancialReport $report): JsonResponse
    {
        if (!in_array($report->status, ['pending', 'processing'])) {
            return response()->json(['error' => 'Report is not in a validatable state.'], 422);
        }

        $before = $report->only('status');

        $result = $this->ruleEngine->run($report);

        $report->status = $result['passed'] ? 'validated' : 'flagged';
        $report->save();

        AuditLog::record('FinancialReport', $report->id, 'validated', $before, $report->only('status'));

        // AI explains any violations found
        if (!$result['passed']) {
            dispatch(fn() => $this->aiService->explainViolations($report));
        }

        return response()->json([
            'status' => $report->status,
            'violations_found' => count($result['violations']),
            'rules_checked' => $result['rules_checked'],
        ]);
    }

    // ── POST /api/reports/{id}/approve ────────────────────────────────────────
    public function approve(Request $request, FinancialReport $report): JsonResponse
    {
        $this->authorize('approve', $report);

        if ($report->hasCriticalViolations()) {
            return response()->json(['error' => 'Cannot approve report with open critical violations.'], 422);
        }

        $before = $report->only('status', 'reviewed_by', 'reviewed_at');
        $report->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        AuditLog::record('FinancialReport', $report->id, 'approved', $before, $report->only('status'));

        return response()->json(['message' => 'Report approved.', 'report' => $report]);
    }

    // ── POST /api/reports/{id}/reject ─────────────────────────────────────────
    public function reject(Request $request, FinancialReport $report): JsonResponse
    {
        $this->authorize('reject', $report);

        $request->validate(['reason' => 'required|string|min:10']);

        $before = $report->only('status');
        $report->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        AuditLog::record('FinancialReport', $report->id, 'rejected', $before, [
            'status' => 'rejected',
            'reason' => $request->reason,
        ]);

        return response()->json(['message' => 'Report rejected.']);
    }

    // ── GET /api/reports/{id}/insights ────────────────────────────────────────
    public function insights(FinancialReport $report): JsonResponse
    {
        return response()->json($report->insights()->get());
    }

    // ── GET /api/reports/{id}/audit ───────────────────────────────────────────
    public function auditTrail(FinancialReport $report): JsonResponse
    {
        $logs = AuditLog::where('entity_type', 'FinancialReport')
            ->where('entity_id', $report->id)
            ->with('performer')
            ->orderByDesc('performed_at')
            ->get();

        return response()->json($logs);
    }

    // ── GET /api/dashboard/stats ──────────────────────────────────────────────
    public function dashboardStats(): JsonResponse
    {
        $stats = [
            'total' => FinancialReport::count(),
            'pending' => FinancialReport::where('status', 'pending')->count(),
            'validated' => FinancialReport::where('status', 'validated')->count(),
            'flagged' => FinancialReport::where('status', 'flagged')->count(),
            'approved' => FinancialReport::where('status', 'approved')->count(),
            'rejected' => FinancialReport::where('status', 'rejected')->count(),
            'open_violations' => \App\Models\RuleViolation::where('status', 'open')->count(),
            'critical_violations' => \App\Models\RuleViolation::where('status', 'open')
                ->whereHas('rule', fn($q) => $q->where('severity', 'critical'))
                ->count(),
            'by_agency' => FinancialReport::selectRaw('source_agency, count(*) as total')
                ->groupBy('source_agency')->pluck('total', 'source_agency'),
            'by_status_monthly' => FinancialReport::selectRaw(
                "DATE_FORMAT(created_at,'%Y-%m') as month, status, count(*) as total"
            )->groupBy('month', 'status')
                ->orderBy('month')
                ->get(),
        ];

        return response()->json($stats);
    }
}
