<?php
namespace App\Services;

use App\Mail\TriageDigest;
use Illuminate\Support\Facades\Mail;

class DigestMailService
{
    public function send(string $email, array $digestData): void
    {
        Mail::to($email)->send(new TriageDigest($digestData));
    }
}
