<?php

namespace App\Enums;

enum TwoFactorCredentialChange: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case RecoveryCodesRegenerated = 'recovery codes regenerated';
}
