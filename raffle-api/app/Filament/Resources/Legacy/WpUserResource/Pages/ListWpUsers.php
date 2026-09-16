<?php

namespace App\Filament\Resources\Legacy\WpUserResource\Pages;

use App\Filament\Resources\Legacy\WpUserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No create action — accounts are still created through the legacy
 * WordPress registration flow, not this admin panel.
 */
class ListWpUsers extends ListRecords
{
    protected static string $resource = WpUserResource::class;
}
