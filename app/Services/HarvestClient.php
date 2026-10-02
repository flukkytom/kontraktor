<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Harvest API v2 for pulling a contractor's
 * own time entries. Token auth (personal access token + account id).
 */
class HarvestClient
{
    private const BASE_URL = 'https://api.harvestapp.com/v2';

    public function __construct(
        private readonly string $accessToken,
        private readonly string $accountId,
    ) {}

    /**
     * Fetch the authenticated user — used to validate saved credentials.
     *
     * @return array{id: int, first_name: string, last_name: string, email: string}
     */
    public function me(): array
    {
        // No retries: a 401 is a 401, and this runs inline during form save.
        return $this->request(retries: 0)->timeout(8)->get('/users/me')->throw()->json();
    }

    /**
     * All billable + non-billable time entries for the user in the range.
     * Follows Harvest pagination until exhausted.
     *
     * @return Collection<int, array{date: string, hours: float, notes: string, project: string, task: string}>
     */
    public function timeEntries(string $from, string $to): Collection
    {
        $entries = collect();
        $url = '/time_entries';

        while ($url !== null) {
            $response = $this->request()->get($url, [
                'from' => $from,
                'to' => $to,
                'per_page' => 100,
            ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    "Harvest request failed ({$response->status()}): {$response->body()}"
                );
            }

            $data = $response->json();

            foreach ($data['time_entries'] ?? [] as $entry) {
                $entries->push([
                    'date' => $entry['spent_date'],
                    'hours' => (float) $entry['hours'],
                    'notes' => $entry['notes'] ?? '',
                    'project' => $entry['project']['name'] ?? '',
                    'task' => $entry['task']['name'] ?? '',
                ]);
            }

            // Harvest gives a full URL for next_page; drop the base prefix.
            $url = isset($data['links']['next'])
                ? str_replace(self::BASE_URL, '', $data['links']['next'])
                : null;
        }

        return $entries;
    }

    private function request(int $retries = 2): PendingRequest
    {
        $request = Http::baseUrl(self::BASE_URL)
            ->withToken($this->accessToken)
            ->withHeaders([
                'Harvest-Account-ID' => $this->accountId,
                'User-Agent' => 'Kontraktor (invoicing)',
                'Accept' => 'application/json',
            ])
            ->timeout(15);

        return $retries > 0 ? $request->retry($retries, 500) : $request;
    }
}
