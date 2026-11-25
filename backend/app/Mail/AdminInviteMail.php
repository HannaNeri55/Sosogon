<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Sent right away, not queued: no queue worker runs on the server, and the caller logs any failure.
class AdminInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $userName, public string $municipality, public string $setupUrl, public string $loginUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->municipality} Tourism Office account on STIM");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin-invite');
    }
}
