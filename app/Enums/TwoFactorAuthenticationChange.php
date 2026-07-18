<?php

namespace App\Enums;

enum TwoFactorAuthenticationChange: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case RecoveryCodesRegenerated = 'recovery codes regenerated';
}
