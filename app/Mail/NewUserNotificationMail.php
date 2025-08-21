<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewUserNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $username,
        public string $password,
        public object $notifiable
    )
    {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->notifiable->email,
            subject: __("Nouvel utilisateur"),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.new-user-notification',
        );
    }
}
