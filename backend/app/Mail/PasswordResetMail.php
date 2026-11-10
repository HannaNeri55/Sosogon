<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Sent right away, not queued: no queue worker runs on the server, and the caller logs any SMTP failure.
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $userName, public string $resetUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your STIM password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset');
    }
}
