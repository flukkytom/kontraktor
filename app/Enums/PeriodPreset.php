<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasLabel;

enum PeriodPreset: string implements HasLabel
{
    case FirstHalf = 'first_half';
    case SecondHalf = 'second_half';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::FirstHalf => '1st – 15th',
            self::SecondHalf => '16th – end of month',
            self::Custom => 'Custom range',
        };
    }

    /**
     * Resolve the period for the month containing the given date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function resolve(CarbonImmutable $reference): array
    {
        $month = $reference->startOfMonth();

        return match ($this) {
            self::FirstHalf => [$month, $month->setDay(15)],
            self::SecondHalf => [$month->setDay(16), $month->endOfMonth()],
            self::Custom => [$month, $month->endOfMonth()],
        };
    }
}
