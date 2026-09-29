<?php

namespace App\Filament\Resources\TutorialResource\Pages;

use App\Filament\Resources\TutorialResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTutorials extends ListRecords
{
    protected static string $resource = TutorialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New tutorial'),
            Actions\Action::make('viewHub')->label('View Learning Hub')->color('gray')->icon('heroicon-o-arrow-top-right-on-square')
                ->url(url('/support/tutorials'))->openUrlInNewTab(),
        ];
    }
}
