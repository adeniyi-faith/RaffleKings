<?php

namespace App\Filament\Resources\TutorialResource\Pages;

use App\Filament\Resources\TutorialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTutorial extends EditRecord
{
    protected static string $resource = TutorialResource::class;

    protected function afterSave(): void
    {
        TutorialResource::changed('tutorial.updated', $this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->after(fn () => TutorialResource::changed('tutorial.deleted', $this->getRecord()))];
    }
}
