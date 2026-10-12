<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountActivationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $activationUrl, public readonly string $associationName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ative sua conta no JUDOMA');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-activation');
    }
}
