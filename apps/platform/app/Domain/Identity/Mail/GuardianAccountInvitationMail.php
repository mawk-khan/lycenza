<?php

namespace App\Domain\Identity\Mail;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Models\School;
use App\Support\Domains\CanonicalOrigin;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 5D.3 -- Identity domain. Deliberately separate from
 * App\Domain\Communications\Application\Channels\CommunicationMail
 * (brief §56/§45): this is account-provisioning transactional mail,
 * never a Communication Hub broadcast -- it is not gated by
 * `communications.channels.email.enabled`, not recorded as a
 * CommunicationAnnouncement/CommunicationDelivery/CommunicationRecipient,
 * and carries no attachments/rich content, only the one-time
 * plaintext acceptance token embedded in a URL. Every test exercising
 * this Mailable uses `Mail::fake()` (brief §44) -- no real provider is
 * ever contacted by this checkpoint's test suite.
 *
 * The constructor receives the PLAINTEXT token only transiently, for
 * this single render -- it is never stored (see the invitation
 * table's `token_hash` column) and this class itself does not log it.
 */
class GuardianAccountInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly School $school,
        private readonly Guardian $guardian,
        private readonly GuardianAccountInvitation $invitation,
        private readonly string $plaintextToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited to {$this->school->name} on School OS",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.identity.guardian-account-invitation',
            with: [
                'schoolName' => $this->school->name,
                'guardianFirstName' => $this->guardian->first_name,
                // ADR 0054 section 8.9: the School's stored canonical origin --
                // never the URL helper, which would follow the request's Host.
                'acceptanceUrl' => app(CanonicalOrigin::class)->schoolUrl($this->school, "invitations/{$this->school->id}/{$this->plaintextToken}"),
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
