<?php

namespace App\Mail;

use App\Models\BusinessSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ListingWarningMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public BusinessSubmission $submission)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Action required: visitor feedback for \"{$this->submission->business_name}\"");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.listing-warning');
    }
}
