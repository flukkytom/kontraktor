<?php

namespace App\Filament\Resources\ContractorProfiles\Pages;

use App\Filament\Resources\ContractorProfiles\ContractorProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContractorProfile extends CreateRecord
{
    protected static string $resource = ContractorProfileResource::class;

    public function getTitle(): string
    {
        return auth()->user()?->isAdmin() ? parent::getTitle() : 'Set up your profile';
    }

    public function getBreadcrumbs(): array
    {
        return auth()->user()?->isAdmin() ? parent::getBreadcrumbs() : [];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] ??= auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return auth()->user()?->isAdmin()
            ? ContractorProfileResource::getUrl('index')
            : ContractorProfileResource::getUrl('edit', ['record' => $this->record]);
    }
}
