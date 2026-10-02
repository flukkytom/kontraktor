<?php

namespace App\Filament\Resources\ContractorProfiles\Pages;

use App\Filament\Resources\ContractorProfiles\ContractorProfileResource;
use Filament\Resources\Pages\EditRecord;

class EditContractorProfile extends EditRecord
{
    protected static string $resource = ContractorProfileResource::class;

    public function getTitle(): string
    {
        return auth()->user()?->isAdmin() ? parent::getTitle() : 'My Profile';
    }

    public function getBreadcrumbs(): array
    {
        return auth()->user()?->isAdmin() ? parent::getBreadcrumbs() : [];
    }

    protected function getRedirectUrl(): string
    {
        return auth()->user()?->isAdmin()
            ? ContractorProfileResource::getUrl('index')
            : $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
