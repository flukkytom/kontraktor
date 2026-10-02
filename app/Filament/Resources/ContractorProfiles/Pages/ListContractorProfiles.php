<?php

namespace App\Filament\Resources\ContractorProfiles\Pages;

use App\Filament\Resources\ContractorProfiles\ContractorProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContractorProfiles extends ListRecords
{
    protected static string $resource = ContractorProfileResource::class;

    /**
     * The list is an admin view. Contractors land on their own profile.
     */
    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            $this->redirect(ContractorProfileResource::getNavigationUrl());

            return;
        }

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New contractor profile'),
        ];
    }
}
