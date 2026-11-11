<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Reports &amp; Analytics Summary</title>
<style>
    @page { margin: 26px 32px; }
    * { box-sizing: border-box; }
    body {
        font-family: 'Helvetica', 'DejaVu Sans', sans-serif;
        color: #1e293b;
        margin: 0;
        font-size: 10.5px;
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

    h2.section {
        font-size: 10px;
        color: #fff;
        background: #0b2545;
        padding: 5px 10px;
        margin: 14px 0 0 0;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .stat-grid td {
        width: 20%;
        border: 1px solid #e2e8f0;
        padding: 8px 10px;
        vertical-align: top;
    }
    .stat-label { font-size: 7.5px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.3px; }
    .stat-value { font-size: 13px; font-weight: bold; color: #0b2545; margin-top: 2px; }

    .data-table { border: 1px solid #e2e8f0; border-top: none; }
    .data-table th {
        background: #f1f5f9;
        text-align: left;
        padding: 5px 10px;
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
    .data-table .amount { text-align: right; }
    .empty-row td { color: #94a3b8; font-style: italic; text-align: center; padding: 8px; }

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
    @php
        $moduleLabels = [
            'flight' => 'Flights', 'hotel' => 'Hotels', 'bus' => 'Bus', 'cab' => 'Cabs',
            'holiday' => 'Holiday packages', 'insurance' => 'Insurance', 'visa' => 'Visa',
            'cruise' => 'Cruise', 'activity' => 'Activities',
        ];
        $channelLabels = [
            'direct' => 'Direct', 'agent' => 'Agent', 'sub_agent' => 'Sub-agent',
            'white_label' => 'White-label', 'api_partner' => 'API partner',
        ];
        $money = fn ($v) => number_format((float) $v, 2);
    @endphp

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
                    Generated: <strong>{{ $generatedAt->format('d M Y, H:i') }}</strong><br>
                    Scope: <strong>{{ $data['scope']['is_platform_wide'] ? 'Platform-wide' : ($data['scope']['tenant_count'] > 1 ? $data['scope']['tenant_count'].' tenants' : 'Your organization') }}</strong>
                </div>
            </td>
        </tr>
    </table>

    <table class="report-title-row">
        <tr>
            <td style="width: 60%;">
                <div class="report-title">Reports &amp; Analytics Summary</div>
                <div class="report-subtitle">{{ $data['range']['from'] }} to {{ $data['range']['to'] }}</div>
            </td>
        </tr>
    </table>

    <h2 class="section">Totals</h2>
    <table class="stat-grid">
        <tr>
            <td>
                <div class="stat-label">Bookings</div>
                <div class="stat-value">{{ number_format($data['totals']['bookings_count']) }}</div>
            </td>
            <td>
                <div class="stat-label">Gross bookings value</div>
                <div class="stat-value">{{ $money($data['totals']['gross_amount']) }}</div>
            </td>
            <td>
                <div class="stat-label">Total collected</div>
                <div class="stat-value">{{ $money($data['totals']['total_amount']) }}</div>
            </td>
            <td>
                <div class="stat-label">Net profit</div>
                <div class="stat-value">{{ $money($data['totals']['net_profit_amount']) }}</div>
            </td>
            <td>
                <div class="stat-label">Profit margin</div>
                <div class="stat-value">{{ $data['totals']['profit_margin_pct'] }}%</div>
            </td>
        </tr>
    </table>

    <h2 class="section">By product module</h2>
    <table class="data-table">
        <thead><tr><th>Module</th><th class="amount">Bookings</th><th class="amount">Total</th></tr></thead>
        <tbody>
            @forelse($data['by_module'] as $row)
                <tr>
                    <td>{{ $moduleLabels[$row['module']] ?? $row['module'] }}</td>
                    <td class="amount">{{ number_format($row['count']) }}</td>
                    <td class="amount">{{ $money($row['total_amount']) }}</td>
                </tr>
            @empty
                <tr class="empty-row"><td colspan="3">No bookings in this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="section">By channel</h2>
    <table class="data-table">
        <thead><tr><th>Channel</th><th class="amount">Bookings</th><th class="amount">Revenue</th><th class="amount">Markup</th><th class="amount">Commission paid</th><th class="amount">Profit</th></tr></thead>
        <tbody>
            @forelse($data['by_channel'] as $row)
                <tr>
                    <td>{{ $channelLabels[$row['channel']] ?? ($row['channel'] ?? 'Unknown') }}</td>
                    <td class="amount">{{ number_format($row['count']) }}</td>
                    <td class="amount">{{ $money($row['total_amount']) }}</td>
                    <td class="amount">{{ $money($row['markup_amount']) }}</td>
                    <td class="amount">{{ $money($row['commission_amount']) }}</td>
                    <td class="amount">{{ $money($row['profit_amount']) }}</td>
                </tr>
            @empty
                <tr class="empty-row"><td colspan="6">No bookings in this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if(!empty($data['direct_split']))
    <h2 class="section">Direct channel — guest vs. signed-in customer</h2>
    <table class="data-table">
        <thead><tr><th>Audience</th><th class="amount">Bookings</th><th class="amount">Total</th></tr></thead>
        <tbody>
            @foreach($data['direct_split'] as $row)
                <tr>
                    <td>{{ ucfirst($row['audience']) }}</td>
                    <td class="amount">{{ number_format($row['count']) }}</td>
                    <td class="amount">{{ $money($row['total_amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if(!empty($data['api_partners']))
    <h2 class="section">API partners</h2>
    <table class="data-table">
        <thead><tr><th>Partner</th><th class="amount">Bookings</th><th class="amount">Total</th></tr></thead>
        <tbody>
            @foreach($data['api_partners'] as $row)
                <tr>
                    <td>{{ $row['tenant_name'] }}</td>
                    <td class="amount">{{ number_format($row['bookings_count']) }}</td>
                    <td class="amount">{{ $money($row['total_amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if(!empty($data['top_tenants']))
    <h2 class="section">Top tenants</h2>
    <table class="data-table">
        <thead><tr><th>Tenant</th><th class="amount">Bookings</th><th class="amount">Total</th></tr></thead>
        <tbody>
            @foreach($data['top_tenants'] as $row)
                <tr>
                    <td>{{ $row['tenant_name'] }}</td>
                    <td class="amount">{{ number_format($row['bookings_count']) }}</td>
                    <td class="amount">{{ $money($row['total_amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <h2 class="section">Payments by status</h2>
    <table class="data-table">
        <thead><tr><th>Status</th><th class="amount">Count</th><th class="amount">Amount</th></tr></thead>
        <tbody>
            @forelse($data['payments']['by_status'] as $row)
                <tr>
                    <td>{{ ucfirst($row['status']) }}</td>
                    <td class="amount">{{ number_format($row['count']) }}</td>
                    <td class="amount">{{ $money($row['amount']) }}</td>
                </tr>
            @empty
                <tr class="empty-row"><td colspan="3">No payment activity in this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        This is a computer-generated report &middot; Generated on {{ $generatedAt->format('d M Y, H:i') }}
    </div>
</body>
</html>
