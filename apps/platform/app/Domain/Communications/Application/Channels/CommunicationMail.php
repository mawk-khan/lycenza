<?php

namespace App\Domain\Communications\Application\Channels;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 5A.3 §12: plain-text-only for this checkpoint (brief §11 --
 * communication_messages.body is stored as plain text today, so
 * sending safe plain text avoids building an HTML
 * sanitization/XSS-review boundary this checkpoint does not need).
 * Receives only an immutable CommunicationEmailPayload -- no
 * recipient resolution, authorization, tenant lookup, audience
 * resolution, or provider selection happens in this class (those are
 * EmailChannelDriver's job, brief §12).
 */
class CommunicationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private readonly CommunicationEmailPayload $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->payload->fromAddress, $this->payload->fromName),
            subject: $this->payload->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.communications.message',
            with: ['bodyText' => $this->payload->bodyText],
        );
    }

    /**
     * Phase 5A.6 §28: streams each attachment lazily from its own
     * storage disk (`Attachment::fromStorageDisk()` -- never reads the
     * whole file into this process's memory up front) and always
     * serves it under its safe, sanitized display name -- never the
     * server-generated storage key/path (brief §10/§27).
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $a) => Attachment::fromStorageDisk($a['disk'], $a['path'])
                ->as($a['displayName'])
                ->withMime($a['mimeType']),
            $this->payload->attachments,
        );
    }
}
