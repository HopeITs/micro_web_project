<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The e-mail copy of a message a staff member sent through the notification box (M4.16, owner decision 22 Nov 2026). The notice itself is the record. */
final class StaffMessage extends Mailable
{
    public function __construct(public string $sender, public string $city, public string $title, public string $body, public ?string $project, string $mailLocale)
    {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title !== '' ? $this->title : __('staff.notifications.mail_subject', ['sender' => $this->sender], $this->locale));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.staff-message', text: 'mail.staff-message-text');
    }
}
