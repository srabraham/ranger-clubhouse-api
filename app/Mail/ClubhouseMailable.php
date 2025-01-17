<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Auth;

class ClubhouseMailable extends Mailable
{
    public $senderId;
    public $personId;

    public function __construct()
    {
        $this->senderId = Auth::id();
        $this->personId = isset($this->person) ? $this->person->id : null;
    }
}
