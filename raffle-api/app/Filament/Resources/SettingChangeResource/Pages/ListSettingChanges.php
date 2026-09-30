<?php

namespace App\Filament\Resources\SettingChangeResource\Pages;

use App\Filament\Resources\SettingChangeResource;
use Filament\Resources\Pages\ListRecords;

class ListSettingChanges extends ListRecords
{
    protected static string $resource = SettingChangeResource::class;

    protected static ?string $title = 'Settings history';
}
