<?php

namespace App\Filament\Resources\AdResource\Pages;

use App\Filament\Resources\AdResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAd extends CreateRecord
{
    protected static string $resource = AdResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = $data['updated_by'] = auth('wordpress')->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        AdResource::changed('ad.created', $this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
