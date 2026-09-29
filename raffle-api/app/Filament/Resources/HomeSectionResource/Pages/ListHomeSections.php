<?php

namespace App\Filament\Resources\HomeSectionResource\Pages;

use App\Filament\Resources\HomeSectionResource;
use App\Models\HomeSection;
use App\Services\HomeLayoutService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListHomeSections extends ListRecords
{
    protected static string $resource = HomeSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add a block'),
            Actions\Action::make('defaults')
                ->label('Load the default layout')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Load the default layout?')
                ->modalDescription(fn () => HomeSection::query()->exists()
                    ? 'This throws away everything you have set up here and puts back the original homepage.'
                    : 'This copies the original homepage here so you can change it.')
                ->action(function () {
                    app(HomeLayoutService::class)->installDefaults();
                    Notification::make()->title('Default layout loaded')->success()->send();
                }),
        ];
    }
}
