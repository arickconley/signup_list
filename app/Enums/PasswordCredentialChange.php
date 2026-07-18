<?php

namespace App\Enums;

enum PasswordCredentialChange: string
{
    case Added = 'added';
    case Replaced = 'replaced';
    case Removed = 'removed';
    case Reset = 'reset';
}
