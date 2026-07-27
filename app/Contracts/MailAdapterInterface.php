<?php

namespace App\Contracts;

use Illuminate\Mail\Mailable;

interface MailAdapterInterface
{
    public function sendMailable(string $to, Mailable $mailable): void;
}