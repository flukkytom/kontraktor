<?php

namespace Tests\Unit;

use App\Enums\PeriodPreset;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PeriodPresetTest extends TestCase
{
    #[Test]
    public function first_half_runs_first_through_fifteenth(): void
    {
        [$start, $end] = PeriodPreset::FirstHalf->resolve(CarbonImmutable::parse('2026-09-20'));

        $this->assertSame('2026-09-01', $start->toDateString());
        $this->assertSame('2026-09-15', $end->toDateString());
    }

    #[Test]
    public function second_half_runs_sixteenth_through_month_end(): void
    {
        [$start, $end] = PeriodPreset::SecondHalf->resolve(CarbonImmutable::parse('2026-09-02'));

        $this->assertSame('2026-09-16', $start->toDateString());
        $this->assertSame('2026-09-30', $end->toDateString());
    }

    #[Test]
    public function second_half_handles_leap_february(): void
    {
        [$start, $end] = PeriodPreset::SecondHalf->resolve(CarbonImmutable::parse('2024-02-10'));

        $this->assertSame('2024-02-29', $end->toDateString());
    }
}
