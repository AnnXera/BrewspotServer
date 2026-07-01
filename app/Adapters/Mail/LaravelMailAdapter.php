<?php

namespace App\Adapters\Mail;

use App\Contracts\MailAdapterInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

class LaravelMailAdapter implements MailAdapterInterface
{
    public function sendMailable(string $to, Mailable $mailable): void
    {
        Mail::to($to)->send($mailable);
    }
}