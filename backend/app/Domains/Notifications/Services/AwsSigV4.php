<?php

namespace App\Domains\Notifications\Services;

/** Minimal AWS Signature Version 4 for one JSON POST (used for Amazon SES). */
class AwsSigV4
{
    /** @return array<string, string> headers to send */
    public static function sign(string $method, string $url, string $payload, string $region, string $service, string $accessKey, string $secretKey): array
    {
        $parts = parse_url($url);
        $host = $parts['host'];
        $path = $parts['path'] ?? '/';
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $payloadHash = hash('sha256', $payload);

        $canonicalHeaders = "content-type:application/json\nhost:{$host}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'content-type;host;x-amz-date';
        $canonicalRequest = implode("\n", [$method, $path, '', $canonicalHeaders, $signedHeaders, $payloadHash]);
        $scope = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $kDate = hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return [
            'Content-Type' => 'application/json',
            'X-Amz-Date' => $amzDate,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
    }
}
