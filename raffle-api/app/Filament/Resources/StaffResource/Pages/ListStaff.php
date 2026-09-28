<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    protected static ?string $title = 'Staff & roles';

    public function getSubheading(): string|Htmlable|null
    {
        return StaffResource::roleHelp();
    }
}
