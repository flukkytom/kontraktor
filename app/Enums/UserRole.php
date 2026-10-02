<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    case Contractor = 'contractor';
    case Admin = 'admin';

    public function getLabel(): string
    {
        return match ($this) {
            self::Contractor => 'Contractor',
            self::Admin => 'Admin',
        };
    }
}
