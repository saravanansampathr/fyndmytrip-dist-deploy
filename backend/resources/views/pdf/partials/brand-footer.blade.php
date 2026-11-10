{{--
    Platform Roadmap Phase 5 — the one shared branding strip every
    ticket/voucher/policy/itinerary PDF includes, instead of each PDF
    controller/template styling its own: social handles, a QR code to the
    tenant's own site, and app-store links, all sourced from
    App\Support\PdfBranding (which in turn reads the white-label profile
    CRM screen — WhiteLabelController) rather than hardcoded per template.

    Expects (all optional — the whole strip renders nothing if none are set):
      $socialLinks  array<string, string>   e.g. ['facebook' => 'https://...']
      $qrDataUri    ?string                 base64 data: URI
      $playStoreUrl ?string
      $appStoreUrl  ?string
      $brandName    string
--}}
@if(!empty($socialLinks) || ($qrDataUri ?? null) || ($playStoreUrl ?? null) || ($appStoreUrl ?? null))
    <table style="width:100%; margin-top:10px; padding-top:8px; border-top:1px solid #e2e8f0;">
        <tr>
            <td style="width:36%; vertical-align:middle;">
                @if(!empty($socialLinks))
                    <div style="font-size:8px; color:#94a3b8; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:2px;">Follow {{ $brandName }}</div>
                    <div style="font-size:9px;">
                        @foreach($socialLinks as $platform => $url)
                            <a href="{{ $url }}" style="color:#0b2545; text-decoration:none; margin-right:9px; font-weight:bold;">{{ ucfirst($platform) }}</a>
                        @endforeach
                    </div>
                @endif
            </td>
            <td style="width:28%; text-align:center; vertical-align:middle;">
                @if($qrDataUri ?? null)
                    <img src="{{ $qrDataUri }}" style="height:50px; width:50px;">
                    <div style="font-size:7px; color:#94a3b8; margin-top:2px;">Scan to visit us online</div>
                @endif
            </td>
            <td style="width:36%; text-align:right; vertical-align:middle;">
                @if(($playStoreUrl ?? null) || ($appStoreUrl ?? null))
                    <div style="font-size:8px; color:#94a3b8; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:3px;">Get the {{ $brandName }} app</div>
                    @if($playStoreUrl ?? null)
                        <a href="{{ $playStoreUrl }}" style="display:inline-block; border:1px solid #cbd5e1; border-radius:3px; padding:3px 7px; font-size:8px; color:#0b2545; text-decoration:none; margin-left:4px;">Google Play</a>
                    @endif
                    @if($appStoreUrl ?? null)
                        <a href="{{ $appStoreUrl }}" style="display:inline-block; border:1px solid #cbd5e1; border-radius:3px; padding:3px 7px; font-size:8px; color:#0b2545; text-decoration:none; margin-left:4px;">App Store</a>
                    @endif
                @endif
            </td>
        </tr>
    </table>
@endif
