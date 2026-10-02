<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InvoiceSource: string implements HasLabel
{
    case Harvest = 'harvest';
    case Excel = 'excel';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Harvest => 'Harvest',
            self::Excel => 'Excel Upload',
            self::Manual => 'Manual',
        };
    }
}
