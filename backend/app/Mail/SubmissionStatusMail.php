<?php

namespace App\Mail;

use App\Models\BusinessSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Sent right away, not queued: no queue worker runs on the server, and the caller logs any SMTP failure.
class SubmissionStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public BusinessSubmission $submission)
    {
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->submission->status) {
            'approved' => "Your submission for \"{$this->submission->business_name}\" has been approved",
            'removed' => "Your listing \"{$this->submission->business_name}\" has been removed",
            default => "Your submission for \"{$this->submission->business_name}\" needs changes",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.submission-status');
    }
}
