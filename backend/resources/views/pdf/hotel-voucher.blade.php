<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $booking->booking_code ?? $booking->booking_reference }} — Hotel Voucher</title>
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

    .status-strip {
        margin-top: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        padding: 8px 14px;
    }
    .status-badge { font-weight: bold; text-transform: uppercase; font-size: 10px; letter-spacing: 0.3px; }
    .status-badge.confirmed, .status-badge.ticketed { color: #16a34a; }
    .status-badge.held { color: #f59e0b; }
    .status-badge.cancelled, .status-badge.failed { color: #dc2626; }
    .booking-id { font-size: 10px; color: #475569; }
    .booking-id strong { color: #0b2545; }

    h2.section {
        font-size: 11px;
        color: #fff;
        background: #0b2545;
        padding: 6px 10px;
        margin: 16px 0 0 0;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .hotel-name { font-size: 15px; font-weight: bold; color: #0b2545; margin-top: 4px; }
    .hotel-stars { color: #f59e0b; font-size: 11px; }
    .hotel-address { font-size: 9.5px; color: #64748b; margin-top: 2px; }

    .stay-strip { margin-top: 10px; background: #eaf2fb; border: 1px solid #d3e3f5; border-radius: 4px; padding: 8px 12px; }
    .stay-strip table td { width: 25%; vertical-align: top; }
    .stay-label { font-size: 9px; text-transform: uppercase; color: #14b8a6; font-weight: bold; letter-spacing: 0.3px; }
    .stay-value { font-size: 11.5px; color: #0b2545; font-weight: bold; margin-top: 2px; }

    .room-row { margin-top: 10px; font-size: 10.5px; }
    .room-row .room-type { font-weight: bold; color: #0b2545; }
    .room-row .incl-badge { float: right; background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; border-radius: 3px; padding: 2px 8px; font-size: 9px; }

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

    .fare-box { border: 1px solid #e2e8f0; border-top: none; }
    .fare-box table td { padding: 6px 12px; font-size: 10.5px; border-bottom: 1px solid #f1f5f9; }
    .fare-box .amount { text-align: right; }
    .fare-box tr.total td {
        border-top: 2px solid #0b2545; border-bottom: none; font-weight: bold; font-size: 12px; color: #0b2545;
    }

    ol.terms { margin: 8px 0 0 0; padding-left: 14px; }
    ol.terms li { font-size: 8.5px; color: #64748b; margin-bottom: 3px; }

    .footer {
        margin-top: 20px; padding-top: 8px; border-top: 1px solid #e2e8f0;
        font-size: 8.5px; color: #94a3b8; text-align: center;
    }
</style>
</head>
<body>
    @php
        $meta = $booking->metadata ?? [];
        $rooms = $meta['rooms'] ?? 1;
        $nights = $meta['nights'] ?? null;
        $primaryGuest = $booking->passengers->first();
        $stars = (int) ($meta['star_rating'] ?? 0);
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
                        3rd Floor, No.17/A2, Daaru Complex, Gandhi Road, Alwarthirunagar, Chennai<br>
                    @endif
                    Tel: {{ $tenant?->support_phone ?? '9841811880' }} · {{ $tenant?->support_email ?? 'care@fyndmytrip.com' }}
                </div>
            </td>
        </tr>
    </table>

    <div class="status-strip">
        <table>
            <tr>
                <td>
                    <span class="status-badge {{ $booking->status }}">{{ strtoupper($booking->status) }}</span><br>
                    <span class="booking-id">Booking ID: <strong>{{ $booking->booking_code ?? $booking->booking_reference }}</strong></span>
                </td>
                <td style="text-align:right; font-size:9.5px; color:#64748b;">
                    Generated {{ $generatedAt }}
                </td>
            </tr>
        </table>
    </div>

    <div class="hotel-name">{{ $meta['hotel_name'] ?? 'Hotel' }}
        @if($stars > 0)
            <span class="hotel-stars">{{ str_repeat('●', min($stars, 5)) }}{{ str_repeat('○', 5 - min($stars, 5)) }}</span>
        @endif
    </div>
    <div class="hotel-address">{{ $meta['city'] ?? '' }}</div>

    <div class="stay-strip">
        <table>
            <tr>
                <td>
                    <div class="stay-label">Check in</div>
                    <div class="stay-value">{{ optional($booking->travel_date_start)->format('d-m-Y') }}</div>
                </td>
                <td>
                    <div class="stay-label">Check out</div>
                    <div class="stay-value">{{ optional($booking->travel_date_end)->format('d-m-Y') }}</div>
                </td>
                <td>
                    <div class="stay-label">Total Rooms</div>
                    <div class="stay-value">{{ $rooms }}</div>
                </td>
                <td>
                    <div class="stay-label">Total Stay</div>
                    <div class="stay-value">{{ $nights ? $nights.' Night(s)' : '—' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="room-row">
        <span class="room-type">{{ $meta['room_type'] ?? 'Standard Room' }}</span>
        @if($meta['meal_plan'] ?? null)
            <span class="incl-badge">Incl: {{ strtoupper($meta['meal_plan']) }}</span>
        @endif
    </div>

    <h2 class="section">Guest Details</h2>
    <table class="grid">
        <thead>
            <tr>
                <th style="width:10%;">S.No</th>
                <th style="width:60%;">Guest Name</th>
                <th style="width:30%;">Type</th>
            </tr>
        </thead>
        <tbody>
            @foreach($booking->passengers as $passenger)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $passenger->name }}{{ $loop->first ? ' (Primary guest)' : '' }}</td>
                    <td>{{ ucfirst($passenger->passenger_type ?? 'adult') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($meta['special_requests'] ?? null)
        <h2 class="section">Special Requests</h2>
        <p style="font-size: 10px; margin: 8px 0 0 0;">{{ $meta['special_requests'] }}</p>
    @endif

    @if($booking->contact_email || $booking->contact_phone)
        <h2 class="section">Contact Details</h2>
        <p style="font-size: 10px; margin: 8px 0 0 0;">
            @if($booking->contact_email) Email: {{ $booking->contact_email }} @endif
            @if($booking->contact_phone) &nbsp;·&nbsp; Mobile: {{ $booking->contact_phone }} @endif
        </p>
    @endif

    <h2 class="section">Cancellation Policy</h2>
    <table class="grid">
        <thead>
            <tr>
                <th style="width:40%;">Cancellation on or after</th>
                <th style="width:40%;">Cancellation on or before check-in</th>
                <th style="width:20%;">Charges</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Booking date</td>
                <td>{{ optional($booking->travel_date_start)?->subDay()->format('d-m-Y') }}</td>
                <td>As per hotel cancellation policy</td>
            </tr>
            <tr>
                <td>{{ optional($booking->travel_date_start)?->subDay()->format('d-m-Y') }}</td>
                <td>{{ optional($booking->travel_date_start)->format('d-m-Y') }}</td>
                <td>100% of booking value</td>
            </tr>
        </tbody>
    </table>
    <p style="font-size: 8.5px; color: #94a3b8; margin-top: 4px;">
        *No-show or early check-out will attract full cancellation charges unless otherwise specified. Any amount already retained under the cancellation policy is non-refundable.
    </p>

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
                <td>Total Amount Payable</td>
                <td class="amount">{{ $booking->currency }} {{ number_format($total, 2) }}</td>
            </tr>
        </table>
    </div>

    <h2 class="section">General Terms &amp; Conditions</h2>
    <ol class="terms">
        <li>Your booking is confirmed; the guest name will be listed in the hotel's reservation system closer to the arrival date.</li>
        <li>Guest photo ID must be presented at the time of check-in — Aadhar, Passport or Driving License are acceptable proofs.</li>
        <li>A credit card or cash deposit may be required for extra services at check-in; extra charges are payable directly to the hotel.</li>
        <li>Extra-person and/or extra-bed charges may apply and vary depending on property policy.</li>
        <li>Special requests (bed type, smoking room, early check-in, late check-out) are subject to availability and are not guaranteed at the time of booking.</li>
        <li>City tax and resort fee, if any, are to be paid directly to the hotel.</li>
        <li>In case of foreign nationals, a scanned copy of the guest's passport may be required as per local regulations.</li>
        <li>Please check the hotel's / destination's own guidelines for any additional entry requirements.</li>
    </ol>

    @include('pdf.partials.brand-footer', ['socialLinks' => $socialLinks ?? [], 'qrDataUri' => $qrDataUri ?? null, 'playStoreUrl' => $playStoreUrl ?? null, 'appStoreUrl' => $appStoreUrl ?? null, 'brandName' => $brandName])

    <div class="footer">
        {{ $brandName }} · {{ $tenant?->business_address ?? '3rd Floor, No.17/A2, Daaru Complex, Gandhi Road, Alwarthirunagar, Chennai' }} · {{ $tenant?->support_phone ?? '9841811880' }} · {{ $tenant?->support_email ?? 'care@fyndmytrip.com' }}<br>
        This is a computer-generated voucher and does not require a signature · Generated on {{ $generatedAt }}
    </div>
</body>
</html>
