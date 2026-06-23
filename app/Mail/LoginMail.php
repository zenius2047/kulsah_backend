<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginMail extends Mailable
{
    use Queueable, SerializesModels;

    public $name;
    public $ip;
    public $location;
    public $device;
    public $time;

    /**
     * Create a new message instance.
     */
    public function __construct($name, $ip, $location, $device, $time)
    {
        $this->name = $name;
        $this->ip = $ip;
        $this->location = $location;
        $this->device = $device;
        $this->time = $time;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Login Detected on Your Account',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'email.login_reminder',
            with: [
                'name' => $this->name,
                'ip' => $this->ip,
                'location' => $this->location,
                'device' => $this->device,
                'time' => $this->time,
            ],
        );
    }
}