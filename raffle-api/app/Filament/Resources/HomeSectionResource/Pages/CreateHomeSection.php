<?php

namespace App\Filament\Resources\HomeSectionResource\Pages;

use App\Filament\Resources\HomeSectionResource;
use App\Models\HomeSection;
use Filament\Resources\Pages\CreateRecord;

class CreateHomeSection extends CreateRecord
{
    protected static string $resource = HomeSectionResource::class;

    /** New blocks go to the bottom of the page. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sort_order'] = (int) HomeSection::query()->max('sort_order') + 1;

        return $data;
    }

    protected function afterCreate(): void
    {
        HomeSectionResource::changed('homepage.section_created', $this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
