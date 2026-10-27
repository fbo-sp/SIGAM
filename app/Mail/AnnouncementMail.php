<?php

namespace App\Mail;

use App\Models\Announcement;
use App\Services\Notifications\AnnouncementService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AnnouncementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Announcement $announcement, public readonly string $recipientName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->announcement->title.' — '.$this->announcement->association->trade_name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.announcement', with: [
            'signature' => app(AnnouncementService::class)->signature($this->announcement->sender, $this->announcement->dojo, $this->announcement->sender_level),
        ]);
    }
}
