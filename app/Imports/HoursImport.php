<?php

namespace App\Imports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Hours template import. Expected headings (case-insensitive, snake
 * normalized by maatwebsite): date, hours, description (optional).
 */
class HoursImport implements ToCollection, WithHeadingRow
{
    /**
     * Normalize each row into an invoice line shape.
     *
     * @return Collection<int, array{date: string, description: string, hours: float, error: ?string}>
     */
    public function collection(Collection $rows): Collection
    {
        return $rows->map(function ($row) {
            $date = $this->parseDate($row['date'] ?? null);
            $hours = is_numeric($row['hours'] ?? null) ? (float) $row['hours'] : null;

            return [
                'date' => $date?->toDateString(),
                'description' => filled($row['description'] ?? null)
                    ? trim((string) $row['description'])
                    : ($date ? "Hours for {$date->format('F jS, Y')}" : ''),
                'hours' => $hours,
                'error' => match (true) {
                    $date === null => 'Missing or invalid date',
                    $hours === null || $hours <= 0 => 'Missing or invalid hours',
                    default => null,
                },
            ];
        });
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        // Excel serial dates arrive as floats when the column is numeric.
        if (is_numeric($value)) {
            try {
                return Carbon::instance(Date::excelToDateTimeObject($value));
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
