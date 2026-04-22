<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; }
        .header { border-bottom: 2px solid #10b981; padding-bottom: 10px; margin-bottom: 16px; }
        .title { font-size: 20px; margin: 0 0 6px; color: #065f46; }
        .muted { color: #475569; }
        .section { margin-bottom: 16px; }
        .section h2 { font-size: 14px; margin: 0 0 8px; color: #065f46; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #ecfdf5; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; }
        .badge-low { background: #dcfce7; color: #14532d; }
        .badge-medium { background: #fef3c7; color: #78350f; }
        .badge-high { background: #fee2e2; color: #7f1d1d; }
        .footer { margin-top: 22px; border-top: 1px solid #cbd5e1; padding-top: 8px; color: #64748b; font-size: 11px; }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">NDPC Compliance Audit Return (Draft)</p>
        <p class="muted">Reference: {{ $submission->reference_code }}</p>
        <p class="muted">Generated: {{ now()->format('Y-m-d H:i') }}</p>
    </div>

    <div class="section">
        <h2>Organisation Details</h2>
        <table>
            <tr><th>Organisation</th><td>{{ $submission->organisation_name }}</td></tr>
            <tr><th>Email</th><td>{{ $submission->organisation_email }}</td></tr>
            <tr><th>Parastatal</th><td>{{ $submission->parastatal }}</td></tr>
            <tr><th>Sector</th><td>{{ $submission->sector }}</td></tr>
            <tr><th>DCPMI Tier</th><td>{{ $submission->dcpmi_tier }}</td></tr>
            <tr><th>Compliance Score</th><td>{{ number_format((float) $submission->compliance_score, 2) }}%</td></tr>
            <tr><th>CAR Fee</th><td>N{{ number_format((float) $submission->car_filing_fee, 2) }}</td></tr>
            <tr><th>Filing Deadline</th><td>{{ optional($submission->filing_deadline)->format('Y-m-d') }}</td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Key Obligations and Gap Status</h2>
        <table>
            <thead>
                <tr>
                    <th>Clause</th>
                    <th>Obligation</th>
                    <th>Status</th>
                    <th>Recommendation</th>
                </tr>
            </thead>
            <tbody>
                @foreach($submission->gaps as $gap)
                    <tr>
                        <td>{{ $gap->obligation?->clause_reference }}</td>
                        <td>{{ $gap->obligation?->obligation_title }}</td>
                        <td>
                            @php
                                $status = (string) $gap->status;
                                $badgeClass = $status === 'covered' ? 'badge-low' : ($status === 'partial' ? 'badge-medium' : 'badge-high');
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ strtoupper($status) }}</span>
                        </td>
                        <td>{{ $gap->ai_recommendation ?? 'No recommendation recorded.' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Remediation Plan Summary</h2>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Target Date</th>
                </tr>
            </thead>
            <tbody>
                @foreach(($carData['remediation'] ?? []) as $item)
                    <tr>
                        <td>{{ $item['obligation'] ?? 'Unnamed obligation' }} - {{ $item['recommendation'] ?? 'Define remediation action.' }}</td>
                        <td>{{ $item['target_date'] ?? now()->addDays(45)->toDateString() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="footer">
        Built for Microsoft AI Skills Week 2026 - RegTech Hackathon
    </div>
</body>
</html>
