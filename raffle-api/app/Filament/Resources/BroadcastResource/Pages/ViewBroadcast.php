<?php

namespace App\Filament\Resources\BroadcastResource\Pages;

use App\Filament\Resources\BroadcastResource;
use Filament\Resources\Pages\ViewRecord;

class ViewBroadcast extends ViewRecord
{
    protected static string $resource = BroadcastResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->title;
    }
}
