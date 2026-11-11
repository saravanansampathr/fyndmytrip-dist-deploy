<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $booking->booking_code ?? $booking->booking_reference }} — Bus Ticket</title>
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

    .barcode { margin-top: 4px; height: 22px; }
    .barcode div { display: inline-block; width: 1px; height: 22px; background: #1e293b; margin-right: 1px; }

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

    .point-box { border: 1px solid #d3e3f5; border-radius: 4px; overflow: hidden; }
    .point-box .point-head {
        background: #eaf2fb;
        color: #0b2545;
        font-weight: bold;
        font-size: 10px;
        text-align: center;
        padding: 6px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .point-box .point-body { padding: 8px 10px; font-size: 9.5px; }
    .point-body div { margin-bottom: 3px; }
    .point-body strong { color: #0b2545; }
    .cols-2 td { vertical-align: top; }

    ol.terms, ul.terms-plain { margin: 8px 0 0 0; padding-left: 14px; }
    ol.terms li, ul.terms-plain li { font-size: 8.5px; color: #64748b; margin-bottom: 3px; }

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
        $statusColors = [
            'held' => '#f59e0b', 'confirmed' => '#0ea5e9', 'ticketed' => '#16a34a',
            'cancelled' => '#dc2626', 'refunded' => '#64748b', 'failed' => '#dc2626', 'cart' => '#94a3b8',
        ];
        $meta = $booking->metadata ?? [];
        $primaryPassenger = $booking->passengers->first();
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
                    <div class="route-title">{{ $meta['origin'] ?? '—' }} &rarr; {{ $meta['destination'] ?? '—' }}</div>
                    <div class="route-date">{{ optional($booking->travel_date_start)->format('D, d M Y') }}</div>
                </td>
                <td class="route-right" style="width: 40%;">
                    <div class="pnr">PNR : {{ $booking->supplier_booking_ref ?? '—' }}</div>
                    <div class="ticketno">Ticket No : {{ $meta['ticket_no'] ?? ($booking->booking_code ?? '—') }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="info-grid">
        <table>
            <tr>
                <td>
                    <div class="info-label">Bus Operator</div>
                    <div class="info-value">{{ $meta['operator_name'] ?? '—' }}</div>
                </td>
                <td>
                    <div class="info-label">Bus Type</div>
                    <div class="info-value">{{ $meta['bus_type'] ?? '—' }}</div>
                </td>
                <td>
                    <div class="info-label">Reporting Time</div>
                    <div class="info-value">{{ $meta['reporting_time'] ?? '—' }}</div>
                    <div class="info-sub">{{ optional($booking->travel_date_start)->format('l, d M Y') }}</div>
                </td>
                <td>
                    <div class="info-label">Departure Time</div>
                    <div class="info-value">{{ $meta['departure_time'] ?? '—' }}</div>
                    <div class="info-sub">{{ optional($booking->travel_date_start)->format('l, d M Y') }}</div>
                </td>
            </tr>
        </table>
    </div>

    <h2 class="section">Passenger Details
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
                <th style="width:50%;">Passenger Name</th>
                <th style="width:20%;">Seat Number</th>
                <th style="width:20%;">Barcode</th>
            </tr>
        </thead>
        <tbody>
            @foreach($booking->passengers as $passenger)
                @php
                    $barcodeBars = array_map(
                        fn ($h) => 1 + (hexdec($h) % 4),
                        str_split(str_pad(dechex(crc32($booking->booking_reference.'-'.$passenger->id)), 20, '3a7c', STR_PAD_RIGHT))
                    );
                @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $passenger->name }}</td>
                    <td>{{ $passenger->seat_number ?? 'Not assigned' }}</td>
                    <td>
                        <div class="barcode">
                            @foreach($barcodeBars as $width)
                                <div style="width: {{ $width }}px;"></div>
                            @endforeach
                        </div>
                    </td>
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
                    <td class="amount">{{ $row['amount'] < 0 ? '−' : '' }}{{ $booking->currency }} {{ number_format(abs($row['amount']), 2) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Net Sale Amount</td>
                <td class="amount">{{ $booking->currency }} {{ number_format($total, 2) }}</td>
            </tr>
        </table>
    </div>

    <h2 class="section">Boarding &amp; Dropping</h2>
    <table class="cols-2" style="margin-top: 8px;">
        <tr>
            <td style="width: 50%; padding-right: 8px;">
                <div class="point-box">
                    <div class="point-head">Boarding Point</div>
                    <div class="point-body">
                        <div><strong>Location:</strong> {{ $meta['boarding_point'] ?? '—' }}</div>
                        <div><strong>Reporting time:</strong> {{ $meta['reporting_time'] ?? '—' }}, {{ optional($booking->travel_date_start)->format('D, d M Y') }}</div>
                        <div><strong>Contact:</strong> {{ $tenant?->support_phone ?? '9840594820' }}</div>
                    </div>
                </div>
            </td>
            <td style="width: 50%; padding-left: 8px;">
                <div class="point-box">
                    <div class="point-head">Dropping Point</div>
                    <div class="point-body">
                        <div><strong>Location:</strong> {{ $meta['dropping_point'] ?? '—' }}</div>
                        <div><strong>Arrival:</strong> as per operator schedule</div>
                        <div><strong>Contact:</strong> {{ $tenant?->support_phone ?? '9840594820' }}</div>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <h2 class="section">Terms &amp; Conditions</h2>
    <ol class="terms">
        <li>{{ $brandName }} is an online ticketing platform and does not operate bus services of its own — it has tied up with several bus operators to offer a wide choice of departures and prices.</li>
        <li>The departure time mentioned on the ticket is only a tentative timing; the bus will not leave the source before the time mentioned on the ticket.</li>
        <li>Carry a printout or the e-mail/SMS of this ticket along with a valid photo ID at the time of boarding — you may not be allowed to board without these.</li>
        <li>In case the operator changes the type of bus, {{ $brandName }} will refund the differential amount upon being informed within 24 hours of the journey.</li>
        <li>Cancellation of this ticket is not allowed after the bus departure time.</li>
        <li>Each passenger may carry one bag up to 10 kg and one personal item up to 5 kg; prohibited items under law may not be carried.</li>
        <li>Grievances related to this journey should be reported to {{ $brandName }}'s support team within 10 days of travel.</li>
        <li>{{ $brandName }} is not responsible for delays, operator conduct, seat/amenity quality, or lost baggage — these are the bus operator's responsibility.</li>
    </ol>

    @include('pdf.partials.brand-footer', ['socialLinks' => $socialLinks ?? [], 'qrDataUri' => $qrDataUri ?? null, 'playStoreUrl' => $playStoreUrl ?? null, 'appStoreUrl' => $appStoreUrl ?? null, 'brandName' => $brandName])

    <div class="footer">
        {{ $brandName }} · {{ $tenant?->business_address ?? '17, Gandhi Road, Alwarthirunagar' }} · {{ $tenant?->support_phone ?? '+91-9840594820' }} · {{ $tenant?->support_email ?? 'care@fyndmytrip.com' }}<br>
        This is a computer-generated ticket and does not require a signature · Generated on {{ $generatedAt }}
    </div>
</body>
</html>
