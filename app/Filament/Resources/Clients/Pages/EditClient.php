<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (auth()->user()->isAdmin() && array_key_exists('is_shared', $data)) {
            $data['owner_user_id'] = $data['is_shared'] ? null : ($this->record->owner_user_id ?? auth()->id());
        }

        unset($data['is_shared']);

        return $data;
    }
}
