<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $booking->booking_code ?? $booking->booking_reference }} — Activity Voucher</title>
<style>
    @page { margin: 24px 30px; }
    * { box-sizing: border-box; }
    body {
        font-family: 'Helvetica', 'DejaVu Sans', sans-serif;
        color: #1e293b;
        margin: 0;
        font-size: 11px;
        line-height: 1.5;
    }
    table { border-collapse: collapse; width: 100%; }
    .header-table td { vertical-align: top; }
    .brand-name { font-size: 22px; font-weight: bold; }
    .brand-name .fynd { color: #0b2545; }
    .brand-name .my { color: #14b8a6; }
    .brand-name .trip { color: #ef4444; }
    .brand-tag { font-size: 8px; color: #94a3b8; margin-top: -2px; }
    .logo-img { height: 34px; }
    .company-block { text-align: right; font-size: 9.5px; color: #475569; }
    .company-block strong { color: #0b2545; font-size: 10.5px; }

    .top-meta { margin-top: 10px; font-size: 10px; color: #475569; }
    .top-meta .right { text-align: right; }

    .route-banner {
        margin-top: 8px;
        background: #eaf2fb;
        border: 1px solid #d3e3f5;
        border-radius: 4px;
        padding: 10px 14px;
    }
    .route-banner table td { vertical-align: middle; }
    .route-title { font-size: 14px; font-weight: bold; color: #0b2545; }
    .route-date { font-size: 9.5px; color: #64748b; margin-top: 2px; }
    .route-right { text-align: right; }
    .route-right .pnr { font-size: 11px; color: #0b2545; font-weight: bold; }
    .route-right .ticketno { font-size: 9.5px; color: #64748b; margin-top: 2px; }

    table.grid th {
        background: #f1f5f9;
        text-align: left;
        padding: 6px 8px;
        font-size: 9px;
        text-transform: uppercase;
        color: #475569;
        border-bottom: 1px solid #e2e8f0;
    }
    table.grid td {
        padding: 7px 8px;
        border-bottom: 1px solid #e2e8f0;
        font-size: 10px;
        vertical-align: top;
    }

    h2.section {
        font-size: 11px;
        color: #fff;
        background: #0b2545;
        padding: 6px 10px;
        margin: 16px 0 0 0;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .info-grid { margin-top: 10px; }
    .info-grid table td { vertical-align: top; padding: 0 10px 0 0; width: 25%; }
    .info-label { font-size: 9px; text-transform: uppercase; color: #14b8a6; font-weight: bold; letter-spacing: 0.3px; }
    .info-value { font-size: 11px; color: #0b2545; font-weight: bold; margin-top: 2px; }
    .info-sub { font-size: 9px; color: #64748b; margin-top: 1px; }

    .fare-box {
        border: 1px solid #e2e8f0;
        border-top: none;
    }
    .fare-box table td {
        padding: 6px 12px;
        font-size: 10.5px;
        border-bottom: 1px solid #f1f5f9;
    }
    .fare-box .amount { text-align: right; }
    .fare-box tr.total td {
        border-top: 2px solid #0b2545;
        border-bottom: none;
        font-weight: bold;
        font-size: 12px;
        color: #0b2545;
    }

    ol.terms { margin: 8px 0 0 0; padding-left: 14px; }
    ol.terms li { font-size: 8.5px; color: #64748b; margin-bottom: 3px; }

    .footer {
        margin-top: 20px;
        padding-top: 8px;
        border-top: 1px solid #e2e8f0;
        font-size: 8.5px;
        color: #94a3b8;
        text-align: center;
    }
</style>
</head>
<body>
    @php
        $meta = $booking->metadata ?? [];
        $durationLabel = null;
        if (!empty($meta['duration_minutes'])) {
            $hours = intdiv((int) $meta['duration_minutes'], 60);
            $minutes = (int) $meta['duration_minutes'] % 60;
            $durationLabel = trim(($hours > 0 ? $hours.'h ' : '').($minutes > 0 ? $minutes.'m' : ''));
        }
    @endphp

    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                @if($logo ?? null)
                    <img src="{{ $logo }}" class="logo-img">
                @else
                    <div class="brand-name"><span class="fynd">fynd</span><span class="my">my</span><span class="trip">trip</span></div>
                    <div class="brand-tag">A venture by Afynd</div>
                @endif
            </td>
            <td style="width: 40%;">
                <div class="company-block">
                    <strong>{{ $brandName }}</strong><br>
                    @if($tenant?->business_address)
                        {{ $tenant->business_address }}<br>
                    @else
                        17, Gandhi Road, Alwarthirunagar, Chennai<br>
                    @endif
                    Tel: {{ $tenant?->support_phone ?? '9840594820' }} · {{ $tenant?->support_email ?? 'care@fyndmytrip.com' }}
                </div>
            </td>
        </tr>
    </table>

    <table class="top-meta">
        <tr>
            <td>Booking ID: <strong>{{ $booking->booking_code ?? $booking->booking_reference }}</strong></td>
            <td class="right">Booking Date - {{ optional($booking->created_at)->format('D, d M Y') }}</td>
        </tr>
    </table>

    <div class="route-banner">
        <table>
            <tr>
                <td style="width: 60%;">
                    <div class="route-title">{{ $meta['city'] ?? '—' }} — {{ $meta['category'] ?? 'Activity' }}</div>
                    <div class="route-date">{{ optional($booking->travel_date_start)->format('D, d M Y') }}</div>
                </td>
                <td class="route-right" style="width: 40%;">
                    <div class="pnr">Confirmation No : {{ $booking->supplier_booking_ref ?? '—' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="info-grid">
        <table>
            <tr>
                <td>
                    <div class="info-label">Operator</div>
                    <div class="info-value">{{ $meta['provider_name'] ?? '—' }}</div>
                </td>
                <td>
                    <div class="info-label">Category</div>
                    <div class="info-value">{{ $meta['category'] ?? '—' }}</div>
                </td>
                <td>
                    <div class="info-label">Duration</div>
                    <div class="info-value">{{ $durationLabel ?? '—' }}</div>
                    <div class="info-sub">{{ optional($booking->travel_date_start)->format('l, d M Y') }}</div>
                </td>
                <td>
                    <div class="info-label">Meeting Point</div>
                    <div class="info-value">{{ $meta['meeting_point'] ?? '—' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <h2 class="section">Traveller Details
        @if($booking->contact_phone || $booking->contact_email)
            <span style="float:right; text-transform:none; font-weight:normal; font-size:9px;">
                {{ $booking->contact_phone }} @if($booking->contact_email) · {{ $booking->contact_email }} @endif
            </span>
        @endif
    </h2>
    <table class="grid">
        <thead>
            <tr>
                <th style="width:10%;">S.No</th>
                <th style="width:90%;">Traveller Name</th>
            </tr>
        </thead>
        <tbody>
            @foreach($booking->passengers as $passenger)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $passenger->name }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2 class="section">Price Information</h2>
    <div class="fare-box">
        <table>
            @foreach($priceRows as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="amount">{{ $booking->currency }} {{ number_format($row['amount'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Net Sale Amount</td>
                <td class="amount">{{ $booking->currency }} {{ number_format($total, 2) }}</td>
            </tr>
        </table>
    </div>

    <h2 class="section">Terms &amp; Conditions</h2>
    <ol class="terms">
        <li>{{ $brandName }} is an online booking platform and does not operate this activity itself — it has tied up with several local operators to offer a wide choice of experiences.</li>
        <li>Please arrive at the meeting point at least 15 minutes before the start time shown above; latecomers may not be accommodated.</li>
        <li>Carry a printout or the e-mail/SMS of this voucher along with a valid photo ID at the meeting point.</li>
        <li>Cancellation of this booking is not allowed after the activity's start time.</li>
        <li>Grievances related to this activity should be reported to {{ $brandName }}'s support team within 10 days of the activity date.</li>
        <li>{{ $brandName }} is not responsible for weather-related changes, operator conduct, or on-site safety arrangements — these are the activity operator's responsibility.</li>
    </ol>

    @include('pdf.partials.brand-footer', ['socialLinks' => $socialLinks ?? [], 'qrDataUri' => $qrDataUri ?? null, 'playStoreUrl' => $playStoreUrl ?? null, 'appStoreUrl' => $appStoreUrl ?? null, 'brandName' => $brandName])

    <div class="footer">
        {{ $brandName }} · {{ $tenant?->business_address ?? '17, Gandhi Road, Alwarthirunagar' }} · {{ $tenant?->support_phone ?? '+91-9840594820' }} · {{ $tenant?->support_email ?? 'care@fyndmytrip.com' }}<br>
        This is a computer-generated voucher and does not require a signature · Generated on {{ $generatedAt }}
    </div>
</body>
</html>
