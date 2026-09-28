<?php

namespace App\Filament\Resources\SiteNoticeResource\Pages;

use App\Filament\Resources\SiteNoticeResource;
use Filament\Resources\Pages\EditRecord;

class EditSiteNotice extends EditRecord
{
    protected static string $resource = SiteNoticeResource::class;

    protected function afterSave(): void
    {
        SiteNoticeResource::changed('site_notice.updated', $this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
