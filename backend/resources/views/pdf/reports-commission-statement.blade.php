<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Commission Statement</title>
<style>
    @page { margin: 26px 32px; }
    * { box-sizing: border-box; }
    body {
        font-family: 'Helvetica', 'DejaVu Sans', sans-serif;
        color: #1e293b;
        margin: 0;
        font-size: 10px;
        line-height: 1.5;
    }
    table { border-collapse: collapse; width: 100%; }
    .header-table td { vertical-align: top; }
    .brand-name { font-size: 20px; font-weight: bold; }
    .brand-name .fynd { color: #0b2545; }
    .brand-name .my { color: #14b8a6; }
    .brand-name .trip { color: #ef4444; }
    .logo-img { height: 30px; }
    .meta-block { text-align: right; font-size: 9.5px; color: #475569; }
    .meta-block strong { color: #0b2545; }

    .report-title-row { margin-top: 16px; border-bottom: 2px solid #0b2545; padding-bottom: 6px; }
    .report-title { font-size: 15px; font-weight: bold; color: #0b2545; text-transform: uppercase; letter-spacing: 0.5px; }
    .report-subtitle { font-size: 9.5px; color: #64748b; }

    .total-banner {
        margin-top: 14px;
        background: #0b2545;
        color: #fff;
        padding: 12px 16px;
        border-radius: 4px;
    }
    .total-banner .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.4px; opacity: 0.8; }
    .total-banner .value { font-size: 20px; font-weight: bold; margin-top: 2px; }

    .data-table { margin-top: 14px; border: 1px solid #e2e8f0; }
    .data-table th {
        background: #f1f5f9;
        text-align: left;
        padding: 6px 10px;
        font-size: 8.5px;
        text-transform: uppercase;
        color: #475569;
        border-bottom: 1px solid #e2e8f0;
    }
    .data-table td {
        padding: 6px 10px;
        font-size: 9.5px;
        border-bottom: 1px solid #f1f5f9;
    }
    .amount { text-align: right; color: #059669; font-weight: bold; }
    .empty-row td { color: #94a3b8; font-style: italic; text-align: center; padding: 10px; }

    .footer {
        margin-top: 18px;
        padding-top: 8px;
        border-top: 1px solid #e2e8f0;
        font-size: 8px;
        color: #94a3b8;
        text-align: center;
    }
</style>
</head>
<body>
    @php $money = fn ($v) => number_format((float) $v, 2); @endphp

    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                @if($logo ?? null)
                    <img src="{{ $logo }}" class="logo-img">
                @else
                    <div class="brand-name"><span class="fynd">fynd</span><span class="my">my</span><span class="trip">trip</span></div>
                @endif
            </td>
            <td style="width: 40%;">
                <div class="meta-block">
                    Generated: <strong>{{ $generatedAt->format('d M Y, H:i') }}</strong>
                </div>
            </td>
        </tr>
    </table>

    <table class="report-title-row">
        <tr>
            <td>
                <div class="report-title">Commission Statement</div>
                <div class="report-subtitle">{{ $from }} to {{ $to }}</div>
            </td>
        </tr>
    </table>

    <div class="total-banner">
        <div class="label">Total commission earned</div>
        <div class="value">{{ $money($total) }}</div>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Booking code</th>
                <th>Module</th>
                <th>Channel</th>
                <th>Tenant</th>
                <th class="amount">Gross amount</th>
                <th>Commission rule</th>
                <th class="amount">Commission</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row['created_at'])->format('d M Y') }}</td>
                    <td>{{ $row['booking_code'] }}</td>
                    <td>{{ ucfirst($row['module']) }}</td>
                    <td>{{ str_replace('_', ' ', $row['channel'] ?? '—') }}</td>
                    <td>{{ $row['tenant_name'] }}</td>
                    <td style="text-align: right;">{{ $money($row['gross_amount']) }}</td>
                    <td>{{ $row['rule_name'] }}</td>
                    <td class="amount">{{ $money($row['amount']) }}</td>
                </tr>
            @empty
                <tr class="empty-row"><td colspan="8">No commission was earned in this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        This is a computer-generated statement &middot; Generated on {{ $generatedAt->format('d M Y, H:i') }}
    </div>
</body>
</html>
