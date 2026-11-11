<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $invoiceNumber }} — Invoice</title>
<style>
    @page { margin: 26px 32px; }
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

    .invoice-title-row {
        margin-top: 18px;
        border-bottom: 2px solid #0b2545;
        padding-bottom: 8px;
    }
    .invoice-title { font-size: 17px; font-weight: bold; color: #0b2545; text-transform: uppercase; letter-spacing: 0.5px; }
    .invoice-meta { text-align: right; font-size: 9.5px; color: #475569; }
    .invoice-meta strong { color: #0b2545; }

    .parties-row { margin-top: 14px; }
    .party-box {
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        padding: 10px 12px;
        font-size: 9.5px;
        color: #475569;
    }
    .party-label {
        font-size: 8px;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 4px;
    }
    .party-name { font-size: 11px; font-weight: bold; color: #0b2545; margin-bottom: 2px; }
    .status-badge {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 3px;
        font-weight: bold;
        font-size: 9.5px;
        color: #fff;
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

    .line-items {
        border: 1px solid #e2e8f0;
        border-top: none;
    }
    .line-items th {
        background: #f1f5f9;
        text-align: left;
        padding: 6px 12px;
        font-size: 9px;
        text-transform: uppercase;
        color: #475569;
        border-bottom: 1px solid #e2e8f0;
    }
    .line-items td {
        padding: 7px 12px;
        font-size: 10.5px;
        border-bottom: 1px solid #f1f5f9;
    }
    .line-items .amount { text-align: right; }
    .line-items tr.total td {
        border-top: 2px solid #0b2545;
        border-bottom: none;
        font-weight: bold;
        font-size: 12.5px;
        color: #0b2545;
    }

    .booking-summary { margin-top: 4px; font-size: 9.5px; color: #64748b; }
    .booking-summary .label { color: #94a3b8; }

    .notes { margin: 14px 0 0 0; padding-left: 14px; }
    .notes li { font-size: 8.5px; color: #64748b; margin-bottom: 3px; }

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
            'refund_processing' => '#f59e0b',
            'failed' => '#dc2626',
            'cart' => '#94a3b8',
        ];
        $statusColor = $statusColors[$booking->status] ?? '#64748b';
        $moduleLabels = [
            'flight' => 'Flight', 'hotel' => 'Hotel', 'bus' => 'Bus', 'cab' => 'Cab',
            'holiday' => 'Holiday Package', 'insurance' => 'Insurance', 'visa' => 'Visa',
            'cruise' => 'Cruise', 'activity' => 'Activity',
        ];
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
                    @if(!empty($gst['seller_gstin']))
                        <br>GSTIN: {{ $gst['seller_gstin'] }}
                    @elseif($tenant?->business_registration_no)
                        <br>Reg. No: {{ $tenant->business_registration_no }}
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="invoice-title-row">
        <tr>
            <td style="width: 50%;">
                <div class="invoice-title">Tax Invoice</div>
            </td>
            <td style="width: 50%;">
                <div class="invoice-meta">
                    <div>Invoice No: <strong>{{ $invoiceNumber }}</strong></div>
                    <div>Invoice Date: <strong>{{ $invoiceDate }}</strong></div>
                    <div>Booking ID: <strong>{{ $booking->booking_code ?? $booking->booking_reference }}</strong></div>
                    @if(!empty($gst))
                        <div>SAC: <strong>{{ $gst['sac'] }}</strong></div>
                        @if($gst['place_of_supply'])<div>Place of supply: <strong>{{ $gst['place_of_supply'] }}</strong></div>@endif
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="parties-row">
        <tr>
            <td style="width: 48%; padding-right: 8px;">
                <div class="party-box">
                    <div class="party-label">Invoice To ({{ $billToLabel }})</div>
                    <div class="party-name">{{ $billTo['name'] }}</div>
                    @if($billTo['address'])
                        <div>{{ $billTo['address'] }}</div>
                    @endif
                    @if(!empty($gst['buyer_gstin']))
                        <div>GSTIN: {{ $gst['buyer_gstin'] }}</div>
                    @elseif($billTo['registration_no'])
                        <div>Reg. No: {{ $billTo['registration_no'] }}</div>
                    @endif
                    @if($billTo['phone'] || $billTo['email'])
                        <div>
                            {{ $billTo['phone'] }}
                            @if($billTo['phone'] && $billTo['email']) · @endif
                            {{ $billTo['email'] }}
                        </div>
                    @endif
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%;">
                <div class="party-box">
                    <div class="party-label">Booking Summary</div>
                    <div class="booking-summary"><span class="label">Product:</span> {{ $moduleLabels[$booking->module] ?? ucfirst($booking->module) }}</div>
                    <div class="booking-summary"><span class="label">Status:</span> <span class="status-badge" style="background: {{ $statusColor }};">{{ strtoupper($booking->status) }}</span></div>
                    <div class="booking-summary"><span class="label">Booked on:</span> {{ optional($booking->created_at)->format('d M Y') }}</div>
                    @if($booking->supplier_booking_ref)
                        <div class="booking-summary"><span class="label">Supplier Ref:</span> {{ $booking->supplier_booking_ref }}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <h2 class="section">Charges</h2>
    <table class="line-items">
        <thead>
            <tr>
                <th style="width:75%;">Description</th>
                <th style="width:25%;" class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lineItems as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="amount">{{ $row['amount'] < 0 ? '−' : '' }}{{ $booking->currency }} {{ number_format(abs($row['amount']), 2) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total Amount ({{ strtoupper($billToLabel === 'Customer' ? 'amount paid' : 'amount payable') }})</td>
                <td class="amount">{{ $booking->currency }} {{ number_format($total, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <ol class="notes">
        <li>This invoice is generated electronically and is valid without a signature.</li>
        <li>All amounts are inclusive of applicable taxes and fees unless stated otherwise.</li>
        <li>For any billing query, please contact us using the details in the header of this invoice.</li>
        <li>This invoice reflects charges for the booking referenced above only; refunds or amendments, if any, are recorded separately against the same booking.</li>
    </ol>

    @include('pdf.partials.brand-footer', ['socialLinks' => $socialLinks ?? [], 'qrDataUri' => $qrDataUri ?? null, 'playStoreUrl' => $playStoreUrl ?? null, 'appStoreUrl' => $appStoreUrl ?? null, 'brandName' => $brandName])

    <div class="footer">
        This is a computer-generated invoice and does not require a signature · Generated by {{ $brandName }} on {{ $generatedAt }}
    </div>
</body>
</html>
