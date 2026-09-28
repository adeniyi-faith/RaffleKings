<?php

namespace App\Filament\Resources\SiteNoticeResource\Pages;

use App\Filament\Resources\SiteNoticeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSiteNotice extends CreateRecord
{
    protected static string $resource = SiteNoticeResource::class;

    protected function afterCreate(): void
    {
        SiteNoticeResource::changed('site_notice.created', $this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
