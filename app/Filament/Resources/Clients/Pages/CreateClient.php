<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        // Admins choose shared/private; contractors always create private.
        $data['owner_user_id'] = $user->isAdmin() && ($data['is_shared'] ?? true)
            ? null
            : $user->id;

        unset($data['is_shared']);

        return $data;
    }
}
