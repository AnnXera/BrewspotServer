<?php

namespace App\Mail;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Cafe $cafe,
        public readonly CafeBranch $branch
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BrewSpot — Registration Application Acknowledgment',
        );
    }

    public function content(): Content
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $applicationUrl = "{$frontendUrl}/application/{$this->user->uuid}";

        return new Content(
            view: 'emails.registration-submitted',
            with: [
                'user'           => $this->user,
                'cafe'           => $this->cafe,
                'branch'         => $this->branch,
                'firstname'      => $this->user->firstname,
                'cafeName'       => $this->cafe->cafe_name,
                'branchName'     => $this->branch->branch_name,
                'applicationUrl' => $applicationUrl,
            ],
        );
    }
}
