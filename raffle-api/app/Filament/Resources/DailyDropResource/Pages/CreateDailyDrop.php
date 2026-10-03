<?php

namespace App\Filament\Resources\DailyDropResource\Pages;

use App\Filament\Resources\DailyDropResource;
use Filament\Resources\Pages\CreateRecord;

/** A new drop starts as a draft: nothing is paid until someone switches it on. */
class CreateDailyDrop extends CreateRecord
{
    protected static string $resource = DailyDropResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['status' => 'draft', 'created_by' => auth('wordpress')->id()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
