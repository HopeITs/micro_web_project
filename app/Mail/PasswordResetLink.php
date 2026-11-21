<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The single-use password reset link (never the password: passwords are stored only as hashes). */
final class PasswordResetLink extends Mailable
{
    public function __construct(public string $link, public int $minutes, string $mailLocale)
    {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account.mail_reset_subject', [], $this->locale));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-reset', text: 'mail.password-reset-text');
    }
}
