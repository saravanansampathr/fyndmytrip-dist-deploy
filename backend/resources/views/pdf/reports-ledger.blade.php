<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Wallet Ledger</title>
<style>
    @page { margin: 24px 28px; }
    * { box-sizing: border-box; }
    body {
        font-family: 'Helvetica', 'DejaVu Sans', sans-serif;
        color: #1e293b;
        margin: 0;
        font-size: 9.5px;
        line-height: 1.5;
    }
    table { border-collapse: collapse; width: 100%; }
    .header-table td { vertical-align: top; }
    .brand-name { font-size: 20px; font-weight: bold; }
    .brand-name .fynd { color: #0b2545; }
    .brand-name .my { color: #14b8a6; }
    .brand-name .trip { color: #ef4444; }
    .logo-img { height: 30px; }
    .meta-block { text-align: right; font-size: 9px; color: #475569; }
    .meta-block strong { color: #0b2545; }

    .report-title-row { margin-top: 14px; border-bottom: 2px solid #0b2545; padding-bottom: 6px; }
    .report-title { font-size: 15px; font-weight: bold; color: #0b2545; text-transform: uppercase; letter-spacing: 0.5px; }
    .report-subtitle { font-size: 9px; color: #64748b; }

    .data-table { margin-top: 12px; border: 1px solid #e2e8f0; }
    .data-table th {
        background: #0b2545;
        color: #fff;
        text-align: left;
        padding: 5px 8px;
        font-size: 8px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .data-table td {
        padding: 5px 8px;
        font-size: 8.5px;
        border-bottom: 1px solid #f1f5f9;
    }
    .data-table tr:nth-child(even) td { background: #f8fafc; }
    .amount { text-align: right; }
    .credit { color: #059669; font-weight: bold; }
    .debit { color: #dc2626; font-weight: bold; }
    .empty-row td { color: #94a3b8; font-style: italic; text-align: center; padding: 10px; }

    .footer {
        margin-top: 16px;
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
                <div class="report-title">Wallet Ledger</div>
                <div class="report-subtitle">{{ $from }} to {{ $to }}</div>
            </td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Wallet owner</th>
                <th>Entry type</th>
                <th>Reference</th>
                <th>Booking code</th>
                <th class="amount">Amount</th>
                <th class="amount">Running balance</th>
                <th>Currency</th>
                <th>Remarks</th>
                <th>Created by</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row['created_at'])->format('d M Y, H:i') }}</td>
                    <td>{{ $row['wallet_owner_name'] }} ({{ $row['wallet_owner_type'] }})</td>
                    <td class="{{ $row['entry_type'] === 'credit' ? 'credit' : 'debit' }}">{{ ucfirst($row['entry_type']) }}</td>
                    <td>{{ str_replace('_', ' ', $row['reference_type']) }}</td>
                    <td>{{ $row['reference_booking_code'] ?? '—' }}</td>
                    <td class="amount">{{ $money($row['amount']) }}</td>
                    <td class="amount">{{ $money($row['running_balance']) }}</td>
                    <td>{{ $row['currency'] }}</td>
                    <td>{{ $row['remarks'] ?? '—' }}</td>
                    <td>{{ $row['created_by_name'] ?? '—' }}</td>
                </tr>
            @empty
                <tr class="empty-row"><td colspan="10">No ledger entries in this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        This is a computer-generated statement &middot; Generated on {{ $generatedAt->format('d M Y, H:i') }}
    </div>
</body>
</html>
