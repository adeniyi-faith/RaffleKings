<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends email through Brevo's HTTP API (https://developers.brevo.com/reference/sendtransacemail)
 * with just an API key — no SMTP server details to get right, and it
 * works on hosts that block outgoing SMTP ports. Picked in Settings →
 * Email ("Send emails using: Brevo").
 */
final class BrevoTransport extends AbstractTransport
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    protected function doSend(SentMessage $message): void
    {
        $key = (string) config('services.brevo.key');

        if ($key === '') {
            throw new RuntimeException('Brevo is chosen for email but no Brevo API key is set (Settings → Email).');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $person = fn (Address $a) => array_filter(['email' => $a->getAddress(), 'name' => $a->getName() ?: null]);
        $from = $email->getFrom()[0] ?? new Address((string) config('mail.from.address'), (string) config('mail.from.name'));

        $payload = array_filter([
            'sender' => $person($from),
            'to' => array_map($person, $email->getTo()),
            'cc' => array_map($person, $email->getCc()) ?: null,
            'bcc' => array_map($person, $email->getBcc()) ?: null,
            'replyTo' => ($email->getReplyTo()[0] ?? null) ? $person($email->getReplyTo()[0]) : null,
            'subject' => (string) $email->getSubject(),
            'htmlContent' => $email->getHtmlBody() ? (string) $email->getHtmlBody() : null,
            'textContent' => $email->getTextBody() ? (string) $email->getTextBody() : null,
            'attachment' => array_map(fn ($part) => [
                'name' => $part->getFilename() ?? 'attachment',
                'content' => base64_encode($part->getBody()),
            ], $email->getAttachments()) ?: null,
        ], fn ($v) => $v !== null);

        $response = Http::withHeaders(['api-key' => $key, 'accept' => 'application/json'])
            ->timeout(20)
            ->post(self::ENDPOINT, $payload);

        if ($response->failed()) {
            throw new RuntimeException('Brevo refused the email: '.($response->json('message') ?? $response->status()));
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
