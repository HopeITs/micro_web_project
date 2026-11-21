<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The six-digit code that proves a user owns the institution e-mail they add to their account. */
final class EmailVerificationCode extends Mailable
{
    public function __construct(public string $code, public int $minutes, string $mailLocale)
    {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account.mail_code_subject', [], $this->locale));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.email-code', text: 'mail.email-code-text');
    }
}
