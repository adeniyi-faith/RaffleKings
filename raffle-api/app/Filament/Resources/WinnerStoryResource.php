<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\WinnerStoryResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Legacy\WpUser;
use App\Models\WinnerStory;
use App\Services\AdminAuditLogService;
use App\Services\Engagement\WinnerStories;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Winner stories (Phase 11): check each photo or video before it goes on
 * the public winners wall. Approving pays the winner their thank-you
 * points; declining tells them why.
 */
class WinnerStoryResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = WinnerStory::class;

    protected static ?string $slug = 'winner-stories';

    protected static ?string $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationGroup = 'Community';

    protected static ?string $navigationLabel = 'Winner stories';

    public static function getNavigationBadge(): ?string
    {
        $pending = WinnerStory::query()->where('status', 'pending')->count();

        return $pending ? (string) $pending : null;
    }

    private static function who(WinnerStory $s): string
    {
        $u = WpUser::find($s->user_id);

        return $u ? ($u->display_name ?: $u->user_login) : 'User #'.$s->user_id;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                MobileCard::make(fn (WinnerStory $record) => [
                    'title' => static::who($record),
                    'body' => $record->caption,
                    'lines' => [ucfirst($record->media_type).' · raffle #'.$record->raffle_id],
                    'badges' => [[ucfirst($record->status), match ($record->status) {
                        'approved' => 'success', 'rejected' => 'danger', default => 'warning'
                    }]],
                    'meta' => $record->created_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\ImageColumn::make('media_path')->label('')->disk('public')->height(56)
                        ->visible(true)->getStateUsing(fn (WinnerStory $record) => $record->media_type === 'image' ? $record->media_path : null),
                    Tables\Columns\TextColumn::make('who')->label('Winner')->state(fn (WinnerStory $record) => static::who($record))
                        ->description(fn (WinnerStory $record) => 'Raffle #'.$record->raffle_id),
                    Tables\Columns\TextColumn::make('caption')->wrap()->limit(80)->placeholder('No caption'),
                    Tables\Columns\TextColumn::make('media_type')->label('Type')->badge(),
                    Tables\Columns\TextColumn::make('status')->badge()
                        ->color(fn (string $state) => match ($state) {
                            'approved' => 'success', 'rejected' => 'danger', default => 'warning'
                        }),
                    Tables\Columns\TextColumn::make('created_at')->label('Posted')->since(),
                ]),
            ])
            ->filters([
                Tables\Filters\Filter::make('pending')->label('Waiting for review')
                    ->query(fn (Builder $query) => $query->where('status', 'pending'))->default(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')->label('Open')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (WinnerStory $record) => $record->mediaUrl(), shouldOpenInNewTab: true),
                Tables\Actions\Action::make('approve')->label('Approve')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (WinnerStory $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalDescription('It goes on the public winners wall and the winner gets their thank-you points.')
                    ->action(fn (WinnerStory $record) => static::attempt(function () use ($record) {
                        app(WinnerStories::class)->approve($record);
                        app(AdminAuditLogService::class)->record(static::admin(), 'winner_story.approved', WinnerStory::class, $record->id);
                    }, 'Approved. It\'s on the winners wall.')),
                Tables\Actions\Action::make('reject')->label('Decline')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (WinnerStory $record) => $record->status !== 'rejected')
                    ->form([Forms\Components\TextInput::make('reason')->label('Reason (sent to the winner)')->required()->maxLength(200)
                        ->placeholder('e.g. The photo doesn\'t show the prize')])
                    ->action(fn (WinnerStory $record, array $data) => static::attempt(function () use ($record, $data) {
                        app(WinnerStories::class)->reject($record, $data['reason']);
                        app(AdminAuditLogService::class)->record(static::admin(), 'winner_story.rejected', WinnerStory::class, $record->id, ['reason' => $data['reason']]);
                    }, 'Declined. The winner has been told why.')),
            ])
            ->emptyStateHeading('No stories waiting')
            ->emptyStateDescription('Winners\' photos and videos appear here for you to check first.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWinnerStories::route('/')];
    }
}
