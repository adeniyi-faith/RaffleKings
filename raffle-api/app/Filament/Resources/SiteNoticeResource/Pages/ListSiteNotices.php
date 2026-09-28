<?php

namespace App\Filament\Resources\SiteNoticeResource\Pages;

use App\Filament\Resources\SiteNoticeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSiteNotices extends ListRecords
{
    protected static string $resource = SiteNoticeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New announcement')];
    }
}
