<?php

namespace App\Filament\Resources\DailyDropResource\Pages;

use App\Filament\Resources\DailyDropResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDailyDrops extends ListRecords
{
    protected static string $resource = DailyDropResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New Daily Drop')];
    }
}
