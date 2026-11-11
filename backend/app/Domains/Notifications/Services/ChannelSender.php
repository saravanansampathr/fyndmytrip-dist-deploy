<?php

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Models\NotificationGatewayConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Talks to the actual providers. Returns the provider's message id (or
 * null) and throws on any failure, which NotificationService records.
 *
 * Business-initiated WhatsApp messages and Indian SMS must use a template
 * approved with the provider (Meta template / DLT template). Put that
 * template's name or id against the message in Admin settings → Messages;
 * the values are sent in the order the {{placeholders}} appear in the text.
 */
class ChannelSender
{
    public function send(string $channel, ?NotificationGatewayConfig $gateway, string $to, string $subject, string $body, ?string $providerTemplateId, array $vars, array $brand): ?string
    {
        $creds = $gateway?->credentials_encrypted ?? [];
        $params = $this->orderedParams($body, $vars, $providerTemplateId);

        return match ($channel) {
            'email' => $this->email($gateway, $creds, $to, $subject, $body, $brand),
            'sms' => $this->sms($gateway, $creds, $to, $body, $providerTemplateId, $vars),
            'whatsapp' => $this->whatsapp($gateway, $creds, $to, $body, $providerTemplateId, $params),
            default => throw new RuntimeException("Unknown channel {$channel}"),
        };
    }

    protected function email(?NotificationGatewayConfig $gateway, array $c, string $to, string $subject, string $body, array $brand): ?string
    {
        $from = $c['from_email'] ?? config('mail.from.address');
        $fromName = $brand['from_name'] ?? config('mail.from.name');
        $replyTo = $brand['support_email'] ?? null;

        switch ($gateway?->provider) {
            case 'sendgrid':
                $res = Http::withToken($c['api_key'] ?? '')->acceptJson()->post('https://api.sendgrid.com/v3/mail/send', [
                    'personalizations' => [['to' => [['email' => $to]]]],
                    'from' => ['email' => $from, 'name' => $fromName],
                    'reply_to' => $replyTo ? ['email' => $replyTo] : null,
                    'subject' => $subject,
                    'content' => [['type' => 'text/plain', 'value' => $body]],
                ]);
                $this->ensureOk($res, 'SendGrid');

                return $res->header('X-Message-Id') ?: null;

            case 'ses':
                $region = $c['region'] ?? 'ap-south-1';
                $payload = json_encode([
                    'FromEmailAddress' => $fromName ? "\"{$fromName}\" <{$from}>" : $from,
                    'Destination' => ['ToAddresses' => [$to]],
                    'ReplyToAddresses' => $replyTo ? [$replyTo] : [],
                    'Content' => ['Simple' => ['Subject' => ['Data' => $subject], 'Body' => ['Text' => ['Data' => $body]]]],
                ]);
                $url = "https://email.{$region}.amazonaws.com/v2/email/outbound-emails";
                $headers = AwsSigV4::sign('POST', $url, $payload, $region, 'ses', $c['access_key_id'] ?? '', $c['secret_access_key'] ?? '');
                $res = Http::withHeaders($headers)->withBody($payload, 'application/json')->post($url);
                $this->ensureOk($res, 'Amazon SES');

                return $res->json('MessageId');

            case 'smtp':
                $mailer = Mail::build([
                    'transport' => 'smtp',
                    'host' => $c['host'] ?? '',
                    'port' => (int) ($c['port'] ?? 587),
                    'encryption' => ((int) ($c['port'] ?? 587)) === 465 ? 'ssl' : 'tls',
                    'username' => $c['username'] ?? null,
                    'password' => $c['password'] ?? null,
                    'timeout' => 20,
                ]);
                $mailer->raw($body, fn ($m) => $m->to($to)->from($from, $fromName)->subject($subject)->replyTo($replyTo ?: $from));

                return null;

            default:
                // No provider connected: the server's own mail settings (.env).
                Mail::raw($body, fn ($m) => $m->to($to)->from(config('mail.from.address'), $fromName)->subject($subject)->replyTo($replyTo ?: config('mail.from.address')));

                return null;
        }
    }

    protected function sms(NotificationGatewayConfig $gateway, array $c, string $to, string $body, ?string $templateId, array $vars): ?string
    {
        $phone = $this->phone($to);

        switch ($gateway->provider) {
            case 'msg91':
                if (! $templateId) {
                    throw new RuntimeException('MSG91 needs the DLT/flow template id for this message (Admin settings → Messages).');
                }
                $recipient = ['mobiles' => $phone];
                foreach ($vars as $k => $v) {
                    if (str_starts_with($k, '__')) {
                        continue;
                    }
                    $recipient[$k] = mb_substr((string) $v, 0, 30);
                }
                $res = Http::withHeaders(['authkey' => $c['auth_key'] ?? ''])->acceptJson()->post('https://control.msg91.com/api/v5/flow', [
                    'template_id' => $templateId,
                    'short_url' => '0',
                    'recipients' => [$recipient],
                ]);
                $this->ensureOk($res, 'MSG91');
                if ($res->json('type') === 'error') {
                    throw new RuntimeException('MSG91: '.$res->json('message'));
                }

                return $res->json('message');

            case 'twilio':
                $res = Http::asForm()->withBasicAuth($c['account_sid'] ?? '', $c['auth_token'] ?? '')
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$c['account_sid']}/Messages.json", [
                        'To' => '+'.$phone,
                        'From' => $gateway->sender_id ?: ($c['from_number'] ?? ''),
                        'Body' => $body,
                    ]);
                $this->ensureOk($res, 'Twilio');

                return $res->json('sid');

            default:
                throw new RuntimeException("SMS through {$gateway->provider} is not supported. Use MSG91 or Twilio.");
        }
    }

    protected function whatsapp(NotificationGatewayConfig $gateway, array $c, string $to, string $body, ?string $templateId, array $params): ?string
    {
        $phone = $this->phone($to);

        switch ($gateway->provider) {
            case 'gupshup':
                $source = preg_replace('/\D/', '', $c['source_number'] ?? $gateway->sender_id ?? '');
                if ($templateId) {
                    $res = Http::asForm()->withHeaders(['apikey' => $c['api_key'] ?? ''])->post('https://api.gupshup.io/wa/api/v1/template/msg', [
                        'channel' => 'whatsapp', 'source' => $source, 'destination' => $phone,
                        'template' => json_encode(['id' => $templateId, 'params' => $params]),
                    ]);
                } else {
                    $res = Http::asForm()->withHeaders(['apikey' => $c['api_key'] ?? ''])->post('https://api.gupshup.io/wa/api/v1/msg', [
                        'channel' => 'whatsapp', 'source' => $source, 'destination' => $phone,
                        'message' => json_encode(['type' => 'text', 'text' => $body]),
                    ]);
                }
                $this->ensureOk($res, 'Gupshup');

                return $res->json('messageId');

            case 'twilio':
                $from = $c['from_number'] ?? $gateway->sender_id ?? '';
                $form = ['To' => 'whatsapp:+'.$phone, 'From' => str_starts_with($from, 'whatsapp:') ? $from : 'whatsapp:'.$from];
                if ($templateId) {
                    $form['ContentSid'] = $templateId;
                    $form['ContentVariables'] = json_encode((object) array_combine(array_map('strval', range(1, max(1, count($params)))), $params ?: ['']));
                } else {
                    $form['Body'] = $body;
                }
                $res = Http::asForm()->withBasicAuth($c['account_sid'] ?? '', $c['auth_token'] ?? '')
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$c['account_sid']}/Messages.json", $form);
                $this->ensureOk($res, 'Twilio');

                return $res->json('sid');

            case 'interakt':
                if (! $templateId) {
                    throw new RuntimeException('Interakt needs the approved WhatsApp template name for this message (Admin settings → Messages).');
                }
                $res = Http::withHeaders(['Authorization' => 'Basic '.($c['api_key'] ?? '')])->acceptJson()->post('https://api.interakt.ai/v1/public/message/', [
                    'countryCode' => '+'.substr($phone, 0, strlen($phone) - 10),
                    'phoneNumber' => substr($phone, -10),
                    'type' => 'Template',
                    'template' => ['name' => $templateId, 'languageCode' => 'en', 'bodyValues' => $params],
                ]);
                $this->ensureOk($res, 'Interakt');

                return $res->json('id');

            default:
                throw new RuntimeException("WhatsApp through {$gateway->provider} is not supported.");
        }
    }

    /** Values for a provider template, in the order the placeholders appear in the text. */
    protected function orderedParams(string $renderedBody, array $vars, ?string $templateId): array
    {
        return array_values($vars['__ordered'] ?? []);
    }

    /** Digits with country code; a bare 10-digit number is taken as Indian. */
    protected function phone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '91'.substr($digits, 1);
        }

        return $digits;
    }

    protected function ensureOk($response, string $provider): void
    {
        if ($response->failed()) {
            throw new RuntimeException("{$provider} returned HTTP {$response->status()}: ".mb_substr($response->body(), 0, 500));
        }
    }
}
