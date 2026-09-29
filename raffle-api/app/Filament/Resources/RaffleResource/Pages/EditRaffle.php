<?php

namespace App\Filament\Resources\RaffleResource\Pages;

use App\Filament\Resources\RaffleResource;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Services\AdminAuditLogService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRaffle extends EditRecord
{
    protected static string $resource = RaffleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Only a raffle with no tickets and no draw can be deleted (item 45).
            Actions\DeleteAction::make()->visible(fn () => $this->getRecord()->canBeDeleted()),
        ];
    }

    /** Show the rules actually in force (older raffles have none saved, so the site defaults apply). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $rules = $this->getRecord()->drawRules()->toArray();
        $data['draw_rules'] = ['min_tier' => $rules['min_tier'] ?? ''] + $rules;

        return $data;
    }

    /** Switching "Flash raffle" off also removes its exact end time. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('is_flash', $data) && ! $data['is_flash']) {
            $data['sales_end_at'] = null;
        }

        return $data;
    }

    /** Raffle Rules Engine: every change to a raffle's draw rules is audited. */
    protected function afterSave(): void
    {
        $raffle = $this->getRecord();

        if ($raffle->wasChanged('draw_rules') && ($admin = auth('wordpress')->user()) instanceof WpUser) {
            app(AdminAuditLogService::class)->record($admin, 'raffle.draw_rules_changed', Raffle::class, $raffle->id, ['rules' => $raffle->drawRules()->toArray()]);
        }
    }
}
