<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\PromoCodeResource\Pages;
use App\Models\Growth\Affiliate;
use App\Models\Growth\PromoCode;
use App\Services\AdminAuditLogService;
use App\Services\Growth\PromoCodeService;
use App\Support\Features;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * Growth → Promo codes (Settings → On / off → New features). Each code
 * shows what it achieved: sign-ups it brought, how many of those played,
 * what they spent, and what the code gave away.
 */
class PromoCodeResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = PromoCode::class;

    protected static ?string $slug = 'promo-codes';

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Growth';

    protected static ?string $navigationLabel = 'Promo codes';

    protected static ?string $modelLabel = 'promo code';

    public static function getNavigationBadge(): ?string
    {
        return Features::navigationBadge('promo_codes');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    /** @var array<int, array<string, mixed>> */
    private static array $stats = [];

    public static function stats(PromoCode $promo): array
    {
        return static::$stats[$promo->id] ??= app(PromoCodeService::class)->stats($promo);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('The code')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->required()
                        ->maxLength(40)
                        ->regex('/^[A-Za-z0-9_-]+$/')
                        ->helperText('Letters and numbers, e.g. TOBI10. Customers can type it in any case.')
                        ->dehydrateStateUsing(fn ($state) => PromoCodeService::normalise($state))
                        ->rule(fn (?PromoCode $record) => Rule::unique('promo_codes', 'code')->ignore($record?->id)),
                    Forms\Components\TextInput::make('campaign')
                        ->maxLength(80)
                        ->placeholder('e.g. Christmas 2026')
                        ->helperText('Group codes to compare campaigns.'),
                    Forms\Components\Select::make('affiliate_id')
                        ->label('Belongs to affiliate')
                        ->options(fn () => Affiliate::query()->orderBy('name')->pluck('name', 'id'))
                        ->placeholder('Nobody')
                        ->helperText('Customers who use it count as that affiliate\'s.'),
                    Forms\Components\TextInput::make('description')->maxLength(200)->placeholder('Staff note, e.g. "For Tobi\'s Instagram"'),
                ]),
            Forms\Components\Section::make('What it gives')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('kind')
                        ->options(PromoCode::KINDS)
                        ->required()
                        ->live()
                        ->default('ticket_discount'),
                    Forms\Components\TextInput::make('percent_off')
                        ->label('% off')
                        ->numeric()->minValue(1)->maxValue(90)
                        ->visible(fn (Forms\Get $get) => $get('kind') === 'ticket_discount')
                        ->required(fn (Forms\Get $get) => $get('kind') === 'ticket_discount')
                        ->helperText('Taken off after any bulk or Golden Box discount.'),
                    Forms\Components\TextInput::make('max_discount')
                        ->label('Most it can take off (₦)')
                        ->numeric()->minValue(1)
                        ->visible(fn (Forms\Get $get) => $get('kind') === 'ticket_discount')
                        ->helperText('Empty = no cap.'),
                    Forms\Components\TextInput::make('min_order')
                        ->label('Smallest order (₦)')
                        ->numeric()->minValue(0)->default(0)
                        ->visible(fn (Forms\Get $get) => $get('kind') === 'ticket_discount'),
                    Forms\Components\TextInput::make('bonus_amount')
                        ->label(fn (Forms\Get $get) => $get('kind') === 'welcome_points' ? 'Points' : 'Naira (spending wallet, can\'t be withdrawn)')
                        ->numeric()->minValue(1)
                        ->visible(fn (Forms\Get $get) => in_array($get('kind'), ['welcome_bonus', 'welcome_points'], true))
                        ->required(fn (Forms\Get $get) => in_array($get('kind'), ['welcome_bonus', 'welcome_points'], true)),
                    Forms\Components\Toggle::make('new_customers_only')
                        ->label('First orders only')
                        ->visible(fn (Forms\Get $get) => $get('kind') === 'ticket_discount')
                        ->helperText('Welcome codes are always for new sign-ups.'),
                ]),
            Forms\Components\Section::make('Limits')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('max_uses')->label('Total uses allowed')->numeric()->minValue(1)->helperText('Empty = unlimited.'),
                    Forms\Components\TextInput::make('max_uses_per_user')->label('Uses per customer')->numeric()->minValue(1)->default(1)->required(),
                    Forms\Components\DateTimePicker::make('starts_at')->label('Starts')->seconds(false)->helperText('Empty = now.'),
                    Forms\Components\DateTimePicker::make('ends_at')->label('Ends')->seconds(false)->after('starts_at')->helperText('Empty = no end.'),
                    Forms\Components\Toggle::make('is_active')->label('Live')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->weight('bold')
                    ->copyable()
                    ->searchable()
                    ->description(fn (PromoCode $record) => $record->summary()),
                Tables\Columns\TextColumn::make('campaign')->placeholder('—')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('affiliate.name')->label('Affiliate')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('uses')
                    ->label('Used')
                    ->state(fn (PromoCode $record) => static::stats($record)['uses'].($record->max_uses ? ' / '.$record->max_uses : '')),
                Tables\Columns\TextColumn::make('customers')
                    ->label('Customers brought')
                    ->state(fn (PromoCode $record) => static::stats($record)['customers'])
                    ->description(fn (PromoCode $record) => static::stats($record)['buyers'].' played'),
                Tables\Columns\TextColumn::make('spend')
                    ->label('They spent')
                    ->state(fn (PromoCode $record) => static::naira(static::stats($record)['spend']))
                    ->description(fn (PromoCode $record) => 'Given away '.static::naira(static::stats($record)['given'])),
                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Status')
                    ->state(fn (PromoCode $record) => match (true) {
                        ! $record->is_active => 'Switched off',
                        $record->ends_at?->isPast() => 'Ended',
                        $record->starts_at?->isFuture() => 'Starts '.$record->starts_at->diffForHumans(),
                        default => 'Live',
                    })
                    ->badge()
                    ->color(fn (string $state) => $state === 'Live' ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('campaign')->options(fn () => PromoCode::query()->whereNotNull('campaign')->distinct()->orderBy('campaign')->pluck('campaign', 'campaign')),
                Tables\Filters\SelectFilter::make('kind')->options(PromoCode::KINDS),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->emptyStateHeading('No promo codes yet')
            ->emptyStateDescription('Create a code for an influencer or a promotion, then share it or a sign-up link: '.url('/register?promo=YOURCODE'));
    }

    /** Audit log for every change (Pages\CreatePromoCode / EditPromoCode). */
    public static function changed(string $action, PromoCode $promo): void
    {
        app(AdminAuditLogService::class)->record(static::admin(), $action, PromoCode::class, $promo->id, [
            'code' => $promo->code,
            'gives' => $promo->summary(),
            'live' => $promo->is_active,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromoCodes::route('/'),
            'create' => Pages\CreatePromoCode::route('/create'),
            'edit' => Pages\EditPromoCode::route('/{record}/edit'),
        ];
    }
}
