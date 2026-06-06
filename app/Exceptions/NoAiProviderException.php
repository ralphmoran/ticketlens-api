<?php

namespace App\Exceptions;

class NoAiProviderException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No AI provider configured. Add one in Console > Admin > AI Settings or via \'ticketlens cloud-keys add\'.');
    }
}
