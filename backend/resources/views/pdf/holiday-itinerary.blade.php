<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $package->name }} — Itinerary</title>
<style>
    @page { margin: 0; }
    * { box-sizing: border-box; }
    body {
        font-family: 'Helvetica', 'DejaVu Sans', sans-serif;
        color: #1e293b;
        margin: 0;
        font-size: 12px;
        line-height: 1.5;
    }
    .cover {
        position: relative;
        height: 260px;
        background-color: #0b2545;
        padding: 28px 36px;
        color: #ffffff;
    }
    .cover::after {
        content: '';
    }
    .cover-image {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 260px;
    }
    .cover-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(11,37,69,0.15) 0%, rgba(11,37,69,0.85) 100%);
    }
    .cover-logo-badge {
        position: absolute;
        top: 18px;
        right: 20px;
        z-index: 3;
    }
    .cover-logo-badge img { height: 26px; display: block; }
    .cover-content { position: relative; z-index: 2; }
    .brand { font-size: 13px; letter-spacing: 2px; text-transform: uppercase; color: #f2c94c; font-weight: bold; }
    .pkg-title { font-size: 26px; font-weight: bold; margin-top: 90px; }
    .pkg-sub { font-size: 12px; color: #dbe4f0; margin-top: 4px; }
    .badges { margin-top: 10px; }
    .badge {
        display: inline-block;
        background: rgba(255,255,255,0.18);
        border: 1px solid rgba(255,255,255,0.4);
        border-radius: 3px;
        padding: 3px 9px;
        margin-right: 6px;
        font-size: 10px;
    }
    .content { padding: 22px 36px 30px 36px; }
    .price-strip {
        background: #f2c94c;
        color: #0b2545;
        border-radius: 6px;
        padding: 12px 16px;
        margin-bottom: 20px;
    }
    .price-strip .amount { font-size: 22px; font-weight: bold; }
    .price-strip .label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; }
    h2.section {
        font-size: 14px;
        color: #0b2545;
        border-bottom: 2px solid #14b8a6;
        padding-bottom: 4px;
        margin: 18px 0 10px 0;
    }
    .day {
        margin-bottom: 8px;
        padding-left: 12px;
        border-left: 3px solid #14b8a6;
    }
    .day-title { font-weight: bold; color: #0b2545; font-size: 12px; }
    .day-desc { color: #475569; font-size: 11px; }
    .day-row { width: 100%; margin-bottom: 14px; }
    .day-row td { vertical-align: top; }
    .day-image {
        width: 108px;
        height: 78px;
        object-fit: cover;
        border-radius: 4px;
    }
    .day-badge {
        display: inline-block;
        background: #16a34a;
        color: #fff;
        font-size: 9px;
        font-weight: bold;
        letter-spacing: 0.3px;
        border-radius: 3px;
        padding: 3px 8px;
        margin-right: 8px;
    }
    .inclusion-strip {
        margin: 4px 0 18px 0;
    }
    .inclusion-strip table td {
        text-align: center;
        padding: 8px 4px;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
    }
    .inclusion-icon {
        display: inline-block;
        width: 22px;
        height: 22px;
        line-height: 22px;
        border-radius: 50%;
        background: #ecfdf5;
        color: #0f766e;
        font-weight: bold;
        font-size: 10px;
        margin-bottom: 4px;
    }
    .inclusion-label { font-size: 8.5px; text-transform: uppercase; color: #475569; letter-spacing: 0.3px; }
    table.price-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.price-table th {
        background: #0b2545;
        color: #fff;
        text-align: left;
        padding: 6px 8px;
        font-size: 10px;
        text-transform: uppercase;
    }
    table.price-table td {
        padding: 6px 8px;
        border-bottom: 1px solid #e2e8f0;
        font-size: 11px;
    }
    .cols { width: 100%; }
    .cols td { vertical-align: top; padding: 0; }
    ul.plain { margin: 0; padding-left: 14px; }
    ul.plain li { margin-bottom: 3px; font-size: 11px; }
    .incl li { color: #0f766e; }
    .excl li { color: #b91c1c; }
    .footer {
        margin-top: 22px;
        padding-top: 10px;
        border-top: 1px solid #e2e8f0;
        font-size: 9px;
        color: #94a3b8;
        text-align: center;
    }
    .terms li { font-size: 9px; color: #64748b; margin-bottom: 2px; }
</style>
</head>
<body>
    <div class="cover">
        @if($coverImage)
            <img src="{{ $coverImage }}" class="cover-image">
        @endif
        <div class="cover-overlay"></div>
        @if($logo ?? null)
            <div class="cover-logo-badge"><img src="{{ $logo }}"></div>
        @endif
        <div class="cover-content">
            <div class="brand">{{ $brandName }}</div>
            <div class="pkg-title">{{ $package->name }}</div>
            <div class="pkg-sub">{{ $package->route_summary ?? $package->destination_city }}</div>
            <div class="badges">
                <span class="badge">{{ $package->duration_nights }}N / {{ $package->duration_days }}D</span>
                <span class="badge">{{ ucfirst($package->package_type) }}</span>
                <span class="badge">{{ ucfirst($package->booking_scope) }}</span>
                @if($package->hotel_category)
                    <span class="badge">{{ $package->hotel_category }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="content">
        <div class="price-strip">
            <span class="label">Starts from</span><br>
            <span class="amount">{{ $package->currency }} {{ number_format((float) $package->sell_price, 2) }}</span>
            <span style="font-size:10px;"> / person, taxes extra</span>
        </div>

        @if(!empty($inclusionIcons))
            <div class="inclusion-strip">
                <table>
                    <tr>
                        @foreach($inclusionIcons as $label)
                            <td style="width: {{ (int) floor(100 / count($inclusionIcons)) }}%;">
                                <div class="inclusion-icon">{{ strtoupper(substr($label, 0, 1)) }}</div><br>
                                <span class="inclusion-label">{{ $label }}</span>
                            </td>
                        @endforeach
                    </tr>
                </table>
            </div>
        @endif

        @if($package->itineraryDays->count() > 0)
            <h2 class="section">Day-wise itinerary</h2>
            @foreach($package->itineraryDays as $day)
                <table class="day-row">
                    <tr>
                        @if(!empty($dayImages[$day->id]))
                            <td style="width: 118px; padding-right: 12px;">
                                <img src="{{ $dayImages[$day->id] }}" class="day-image">
                            </td>
                        @endif
                        <td>
                            <div><span class="day-badge">DAY {{ $day->day_number }}</span><strong style="color:#0b2545; font-size:12px;">{{ $day->title }}</strong></div>
                            @if($day->description)
                                <div class="day-desc" style="margin-top:4px;">{{ $day->description }}</div>
                            @endif
                        </td>
                    </tr>
                </table>
            @endforeach
        @endif

        @if(($package->inclusions && count($package->inclusions)) || ($package->exclusions && count($package->exclusions)))
            <h2 class="section">Inclusions &amp; exclusions</h2>
            <table class="cols">
                <tr>
                    <td style="width:50%; padding-right:10px;">
                        @if($package->inclusions && count($package->inclusions))
                            <strong style="color:#0f766e; font-size:11px;">Included</strong>
                            <ul class="plain incl">
                                @foreach($package->inclusions as $item)
                                    <li>&#10003; {{ $item }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </td>
                    <td style="width:50%;">
                        @if($package->exclusions && count($package->exclusions))
                            <strong style="color:#b91c1c; font-size:11px;">Not included</strong>
                            <ul class="plain excl">
                                @foreach($package->exclusions as $item)
                                    <li>&#10007; {{ $item }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </td>
                </tr>
            </table>
        @endif

        @if($package->priceTiers->count() > 0)
            <h2 class="section">Pricing by occupancy</h2>
            <table class="price-table">
                <thead>
                    <tr><th>Occupancy</th><th>Age</th><th>Price / person</th></tr>
                </thead>
                <tbody>
                    @foreach($package->priceTiers as $tier)
                        <tr>
                            <td>{{ $occupancyLabels[$tier->occupancy_type] ?? $tier->occupancy_type }}</td>
                            <td>{{ $tier->age_label ?? '—' }}</td>
                            <td>{{ $tier->currency }} {{ number_format((float) $tier->sell_price, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if($package->terms && count($package->terms))
            <h2 class="section">Terms &amp; conditions</h2>
            <ul class="plain terms">
                @foreach($package->terms as $term)
                    <li>{{ $term }}</li>
                @endforeach
            </ul>
        @endif

        @include('pdf.partials.brand-footer', ['socialLinks' => $socialLinks ?? [], 'qrDataUri' => $qrDataUri ?? null, 'playStoreUrl' => $playStoreUrl ?? null, 'appStoreUrl' => $appStoreUrl ?? null, 'brandName' => $brandName])

        <div class="footer">
            Generated by {{ $brandName }} · {{ $generatedAt }} · Prices are indicative and subject to availability at the time of booking.
        </div>
    </div>
</body>
</html>
