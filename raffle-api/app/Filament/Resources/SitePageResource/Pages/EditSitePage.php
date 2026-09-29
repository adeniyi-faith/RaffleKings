<?php

namespace App\Filament\Resources\SitePageResource\Pages;

use App\Filament\Resources\SitePageResource;
use Filament\Resources\Pages\EditRecord;

class EditSitePage extends EditRecord
{
    protected static string $resource = SitePageResource::class;

    protected function afterSave(): void
    {
        SitePageResource::changed($this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
