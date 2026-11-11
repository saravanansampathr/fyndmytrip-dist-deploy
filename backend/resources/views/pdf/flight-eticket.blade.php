<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $booking->booking_code ?? $booking->booking_reference }} — E-Ticket</title>
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
    .meta-strip {
        margin-top: 14px;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        padding: 10px 14px;
    }
    .meta-strip table td { vertical-align: middle; }
    .meta-left div { margin-bottom: 2px; }
    .meta-label { color: #94a3b8; }
    .status-badge {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 3px;
        font-weight: bold;
        font-size: 10px;
        color: #fff;
    }
    .pnr-badge {
        text-align: right;
        margin-bottom: 6px;
    }
    .pnr-badge .airline { font-size: 9.5px; color: #475569; }
    .pnr-badge .pnr { font-size: 15px; font-weight: bold; color: #0b2545; }
    .pnr-badge .pnr-label { font-size: 8px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }

    h2.section {
        font-size: 11px;
        color: #fff;
        background: #0b2545;
        padding: 6px 10px;
        margin: 16px 0 0 0;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .section-note {
        font-size: 8.5px;
        font-style: italic;
        color: #94a3b8;
        float: right;
        text-transform: none;
        letter-spacing: 0;
    }

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
    table.grid tr.layover td {
        background: #f8fafc;
        color: #64748b;
        font-size: 9.5px;
        text-align: center;
        padding: 5px 8px;
    }
    .flight-code { font-weight: bold; color: #0b2545; }
    .airline-name { color: #64748b; font-size: 9px; }
    .city { font-weight: bold; color: #0b2545; }
    .city-sub { color: #64748b; font-size: 9px; }
    .datetime { font-weight: bold; }

    .stops-badge {
        display: inline-block;
        padding: 1.5px 7px;
        border-radius: 3px;
        font-size: 8.5px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.2px;
    }
    .stops-badge.nonstop { background: #ecfdf5; color: #16a34a; }
    .stops-badge.stop { background: #fffbeb; color: #b45309; }
    .layover-note { color: #94a3b8; font-size: 8.5px; margin-top: 2px; }

    .refund-badge {
        display: inline-block;
        padding: 1.5px 7px;
        border-radius: 3px;
        font-size: 8.5px;
        font-weight: bold;
    }
    .refund-badge.yes { background: #ecfdf5; color: #16a34a; }
    .refund-badge.no { background: #fef2f2; color: #b91c1c; }

    .bag-row { margin-bottom: 2px; }
    .bag-label { color: #94a3b8; }
    .meal-row { margin-top: 3px; }
    .meal-yes { color: #16a34a; }
    .meal-no { color: #94a3b8; }

    .barcode { margin-top: 4px; height: 26px; }
    .barcode div { display: inline-block; width: 1px; height: 26px; background: #1e293b; margin-right: 1px; }

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
    ol.terms li { font-size: 9px; color: #64748b; margin-bottom: 3px; }

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
            'held' => '#f59e0b',
            'confirmed' => '#0ea5e9',
            'ticketed' => '#16a34a',
            'cancelled' => '#dc2626',
            'refunded' => '#64748b',
            'failed' => '#dc2626',
            'cart' => '#94a3b8',
        ];
        $statusColor = $statusColors[$booking->status] ?? '#64748b';

        $segments = $booking->segments;
        $ticketNumbers = $booking->metadata['ticket_numbers'] ?? [];

        // A deterministic, code-128-styled bar pattern seeded from the
        // passenger + booking reference — reads as a real barcode at a
        // glance without depending on a barcode-generation library.
        $barcodeBars = function (string $seed): array {
            $hash = str_split(str_pad(dechex(crc32($seed)), 24, '3a7c', STR_PAD_RIGHT));
            return array_map(fn ($h) => 1 + (hexdec($h) % 4), $hash);
        };
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
                        3rd Floor, No.17/A2, Daaru Complex, Gandhi Road, Alwarthirunagar, Chennai 600087<br>
                    @endif
                    Tel: {{ $tenant?->support_phone ?? '9841811880' }} · {{ $tenant?->support_email ?? 'care@fyndmytrip.com' }}
                </div>
            </td>
        </tr>
    </table>

    <div class="meta-strip">
        <table>
            <tr>
                <td class="meta-left" style="width: 55%;">
                    <div><span class="meta-label">Booking Time:</span> {{ optional($booking->created_at)->format('d M Y, h:i A') }}</div>
                    <div><span class="meta-label">Booking ID:</span> <strong>{{ $booking->booking_code ?? $booking->booking_reference }}</strong></div>
                    <div><span class="meta-label">Booking Status:</span>
                        <span class="status-badge" style="background: {{ $statusColor }};">{{ strtoupper($booking->status) }}</span>
                    </div>
                    @if($booking->contact_email || $booking->contact_phone)
                        <div><span class="meta-label">Contact:</span> {{ $booking->contact_phone }} @if($booking->contact_email) · {{ $booking->contact_email }} @endif</div>
                    @endif
                </td>
                <td style="width: 45%;">
                    @foreach($segments as $segment)
                        <div class="pnr-badge">
                            <div class="airline">{{ $segment->carrier_or_provider ?? 'Airline' }}</div>
                            <div class="pnr">{{ $booking->supplier_booking_ref ?? '—' }}</div>
                            <div class="pnr-label">Airline PNR</div>
                        </div>
                    @endforeach
                </td>
            </tr>
        </table>
    </div>

    <h2 class="section">Flight Detail <span class="section-note">Please verify flight timings &amp; terminal info with the airline</span></h2>
    <table class="grid">
        <thead>
            <tr>
                <th style="width:15%;">Flight</th>
                <th style="width:9%;">Class</th>
                <th style="width:19%;">Departing</th>
                <th style="width:19%;">Arriving</th>
                <th style="width:15%;">Trip</th>
                <th style="width:23%;">Baggage &amp; Meal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($segments as $segment)
                @php
                    $raw = $segment->raw_supplier_payload ?? [];
                    $flightNumber = $raw['flight_number'] ?? null;
                    $cabin = $raw['cabin_class'] ?? 'economy';
                    $isRefundable = array_key_exists('is_refundable', $raw) ? (bool) $raw['is_refundable'] : null;
                    $durationMinutes = $raw['duration_minutes'] ?? null;
                    $durationLabel = $durationMinutes ? sprintf('%dh %02dm', intdiv($durationMinutes, 60), $durationMinutes % 60) : '—';
                    $checkinBaggage = $raw['baggage_allowance_kg'] ?? null;
                    // No separate cabin-baggage column in the data model —
                    // cabin allowance is standard by cabin class (industry
                    // convention) rather than something suppliers quote
                    // separately, unlike check-in baggage which varies by
                    // fare and is already tracked per segment.
                    $cabinBaggage = in_array($cabin, ['business', 'first'], true) ? 10 : 7;
                    $mealIncluded = $raw['meal_included'] ?? null;
                    $stops = array_key_exists('stops', $raw) ? (int) $raw['stops'] : null;
                    $layoverCity = $raw['layover_city'] ?? null;
                    $layoverMinutes = $raw['layover_minutes'] ?? null;
                    $checkinUrl = \App\Support\AirlineCheckIn::checkInUrl($flightNumber, $booking->supplier_booking_ref);
                @endphp
                <tr>
                    <td>
                        <div class="flight-code">{{ $segment->carrier_or_provider }} {{ $flightNumber }}</div>
                        <div class="airline-name">{{ $segment->fare_basis }}</div>
                        @if($checkinUrl)
                            <div style="margin-top:3px;"><a href="{{ $checkinUrl }}" style="font-size:8px; color:#0ea5e9; text-decoration:none; font-weight:bold;">&#8594; Check in online</a></div>
                        @endif
                    </td>
                    <td>{{ ucfirst(str_replace('_', ' ', $cabin)) }}</td>
                    <td>
                        <div class="datetime">{{ optional($segment->departure_at)->format('D, d M \'y, H:i') }}</div>
                        <div class="city">{{ $segment->origin }}</div>
                    </td>
                    <td>
                        <div class="datetime">{{ optional($segment->arrival_at)->format('D, d M \'y, H:i') }}</div>
                        <div class="city">{{ $segment->destination }}</div>
                    </td>
                    <td>
                        @if($stops === 0)
                            <span class="stops-badge nonstop">Non-stop</span>
                        @elseif($stops !== null)
                            <span class="stops-badge stop">{{ $stops }} stop{{ $stops > 1 ? 's' : '' }}</span>
                            @if($layoverCity)
                                <div class="layover-note">
                                    via {{ $layoverCity }}
                                    @if($layoverMinutes)
                                        ({{ sprintf('%dh %02dm', intdiv((int) $layoverMinutes, 60), $layoverMinutes % 60) }})
                                    @endif
                                </div>
                            @endif
                        @endif
                        <div class="city-sub" style="margin-top:3px;">{{ $durationLabel }}</div>
                    </td>
                    <td>
                        <div class="bag-row"><span class="bag-label">Cabin:</span> {{ $cabinBaggage }} kg</div>
                        <div class="bag-row"><span class="bag-label">Check-in:</span> {{ $checkinBaggage ? $checkinBaggage.' kg' : '—' }}</div>
                        <div class="meal-row">
                            @if($mealIncluded === true)
                                <span class="meal-yes">&#10003; Meal included</span>
                            @elseif($mealIncluded === false)
                                <span class="meal-no">Meal not included</span>
                            @endif
                        </div>
                        @if($isRefundable !== null)
                            <div style="margin-top:3px;">
                                <span class="refund-badge {{ $isRefundable ? 'yes' : 'no' }}">
                                    {{ $isRefundable ? 'Refundable' : 'Non-Refundable' }}
                                </span>
                            </div>
                        @endif
                    </td>
                </tr>
                @if(!$loop->last)
                    @php
                        $next = $segments[$loop->index + 1];
                        $gapMinutes = $segment->arrival_at && $next->departure_at
                            ? $next->departure_at->diffInMinutes($segment->arrival_at)
                            : null;
                    @endphp
                    <tr class="layover">
                        <td colspan="6">
                            Connecting flight at {{ $segment->destination }}
                            @if($gapMinutes)
                                — {{ sprintf('%dh %02dm', intdiv($gapMinutes, 60), $gapMinutes % 60) }} layover
                            @endif
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    <h2 class="section">Passenger Details</h2>
    <table class="grid">
        <thead>
            <tr>
                <th style="width:6%;">Sr.</th>
                <th style="width:24%;">Name</th>
                <th style="width:12%;">Type</th>
                <th style="width:20%;">Ticket / Document No.</th>
                <th style="width:14%;">Seat</th>
                <th style="width:24%;">Barcode</th>
            </tr>
        </thead>
        <tbody>
            @foreach($booking->passengers as $passenger)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        {{ strtoupper($passenger->title ?? '') }} {{ $passenger->name }}
                        @if($passenger->dob)
                            <div class="city-sub">DOB: {{ $passenger->dob->format('d/m/Y') }}</div>
                        @endif
                    </td>
                    <td>{{ ucfirst($passenger->passenger_type) }}</td>
                    <td>
                        {{ $ticketNumbers[$loop->index] ?? '—' }}
                        @if($passenger->document_number)
                            <div class="city-sub">Doc: {{ $passenger->document_number }}</div>
                        @endif
                    </td>
                    <td>{{ $passenger->seat_number ?? 'Not assigned' }}</td>
                    <td>
                        <div class="barcode">
                            @foreach($barcodeBars(($booking->booking_reference).'-'.$passenger->id) as $width)
                                <div style="width: {{ $width }}px;"></div>
                            @endforeach
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($booking->ancillaries->count() > 0)
        <h2 class="section">Add-on Services</h2>
        <table class="grid">
            <thead>
                <tr>
                    <th style="width:40%;">Service</th>
                    <th style="width:30%;">Passenger</th>
                    <th style="width:15%;">Category</th>
                    <th style="width:15%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($booking->ancillaries as $ancillary)
                    <tr>
                        <td>{{ $ancillary->label }}</td>
                        <td>{{ $ancillary->passenger?->name ?? 'All passengers' }}</td>
                        <td>{{ ucfirst($ancillary->category) }}</td>
                        <td>{{ $booking->currency }} {{ number_format((float) $ancillary->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($includeFare)
        <h2 class="section">Fare Details</h2>
        <div class="fare-box">
            <table>
                @foreach($priceRows as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="amount">{{ $row['amount'] < 0 ? '−' : '' }}{{ $booking->currency }} {{ number_format(abs($row['amount']), 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td>Total Amount</td>
                    <td class="amount">{{ $booking->currency }} {{ number_format($total, 2) }}</td>
                </tr>
            </table>
        </div>
    @endif

    <h2 class="section">Important Information</h2>
    <ol class="terms">
        <li>You must complete web check-in on the airline's website or app and obtain a boarding pass before arriving at the airport.</li>
        <li>Reach the airport at least 2 hours before departure for domestic flights and 4 hours before departure for international flights.</li>
        <li>Please confirm the departure terminal directly with the airline, as it may change without prior notice.</li>
        <li>All dates and times shown are local to the departure/arrival city.</li>
        <li>Use the Airline PNR shown above for any direct correspondence with the airline regarding this booking.</li>
        <li>For rescheduling or cancellation within 4 hours of departure, please contact the airline directly.</li>
        <li>Carry a valid government-recognised photo ID (and passport, for international travel) matching the name on this ticket.</li>
        <li>Fare rules, baggage allowance and refund eligibility are governed by the operating airline's own policy and are subject to change.</li>
        <li>Your ability to travel is at the sole discretion of the airline and airport authorities; {{ $brandName }} shall not be held responsible for denied boarding.</li>
        <li>For any assistance, please contact our support team using the details in the header of this ticket.</li>
    </ol>

    @include('pdf.partials.brand-footer', ['socialLinks' => $socialLinks ?? [], 'qrDataUri' => $qrDataUri ?? null, 'playStoreUrl' => $playStoreUrl ?? null, 'appStoreUrl' => $appStoreUrl ?? null, 'brandName' => $brandName])

    <div class="footer">
        This is a computer-generated e-ticket and does not require a signature · Generated by {{ $brandName }} on {{ $generatedAt }}
    </div>
</body>
</html>
