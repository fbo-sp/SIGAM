<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $userName, public readonly string $recoveryUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Sua senha do JUDOMA foi alterada');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-changed');
    }
}
