<?php

namespace App\Filament\Resources\LiveChatResource\Pages;

use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\LiveChatResource;
use App\Models\Raffle;
use App\Services\AdminAuditLogService;
use App\Services\Engagement\RedEnvelopes;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;
use RuntimeException;

class ListLiveChat extends ListRecords
{
    use RunsAdminActions;

    protected static string $resource = LiveChatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Phase 11: a red envelope of points from the site, split between the first viewers to tap.
            Actions\Action::make('dropEnvelope')
                ->label('Drop a red envelope 🧧')
                ->color('danger')
                ->form([
                    Forms\Components\Select::make('raffle_id')
                        ->label('Live draw')
                        ->options(fn () => Raffle::query()->where('is_live_draw_enabled', true)->latest('id')->limit(30)->pluck('title', 'id'))
                        ->required(),
                    Forms\Components\TextInput::make('points')->label('Total points')->numeric()->minValue(1)->maxValue(1000000)->default(1000)->required(),
                    Forms\Components\TextInput::make('slots')->label('How many people can grab a share')->numeric()->minValue(1)->maxValue(50)->default(20)->required(),
                    Forms\Components\TextInput::make('message')->maxLength(80)->placeholder('e.g. Good luck everyone!'),
                ])
                ->modalDescription('Viewers of that live draw see it pop up in the chat. The first to tap split the points at random. Unclaimed points simply expire.')
                ->action(fn (array $data) => static::attempt(function () use ($data) {
                    $raffle = Raffle::findOrFail($data['raffle_id']);

                    try {
                        $envelope = app(RedEnvelopes::class)->send(null, $raffle, (int) $data['points'], (int) $data['slots'], $data['message'] ?? null);
                    } catch (InvalidArgumentException $e) {
                        throw new RuntimeException($e->getMessage());
                    }

                    app(AdminAuditLogService::class)->record(static::admin(), 'red_envelope.dropped', Raffle::class, $raffle->id, ['points' => $envelope->total_points, 'slots' => $envelope->slots]);
                }, 'Red envelope dropped.')),
        ];
    }
}
