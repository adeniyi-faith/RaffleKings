<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\HomeSectionResource\Pages;
use App\Models\HomeItem;
use App\Models\HomeSection;
use App\Services\AdminAuditLogService;
use App\Filament\Support\AiAssist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Site → Homepage: the blocks down the customer homepage, in order (drag
 * to rearrange), and the slides / cards inside each block. Each card can
 * be locked (optionally unlocking itself on a date), limited to certain
 * customers, or scheduled. See App\Services\HomeLayoutService.
 */
class HomeSectionResource extends Resource
{
    use GuardedByStaffRole;

    protected static ?string $model = HomeSection::class;

    protected static ?string $slug = 'homepage';

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Homepage';

    protected static ?string $modelLabel = 'homepage section';

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    private static function linkRule(): \Closure
    {
        return fn () => function (string $attribute, $value, \Closure $fail) {
            if (filled($value) && ! HomeItem::isSafeLink($value)) {
                $fail('Use a page on this site (starting with /) or a full https:// address.');
            }
        };
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('This block')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('What kind of block')
                        ->options(HomeSection::TYPES)
                        ->required()
                        ->live()
                        ->helperText('Slides swipe sideways at the top. Cards make a grid of tiles. Trending shows the raffles closing soon.'),
                    Forms\Components\Toggle::make('is_visible')->label('Show on the homepage')->default(true)->inline(false),
                    Forms\Components\TextInput::make('title')->label('Heading')->maxLength(100)
                        ->helperText('Shown above the block (not used for slides). Only staff see it for a Golden Box block.'),
                    Forms\Components\TextInput::make('subtitle')->label('Small text under the heading')->maxLength(160)
                        ->visible(fn (Forms\Get $get) => $get('type') === 'trending'),
                    Forms\Components\TextInput::make('badge')->label('Little tag next to the heading')->maxLength(40)->placeholder('e.g. Updated Today')
                        ->visible(fn (Forms\Get $get) => $get('type') === 'cards'),
                    Forms\Components\TextInput::make('link_label')->label('"See all" button text')->maxLength(40)
                        ->visible(fn (Forms\Get $get) => $get('type') === 'trending'),
                    Forms\Components\TextInput::make('link_url')->label('"See all" button link')->maxLength(255)->placeholder('/raffles')
                        ->rule(static::linkRule())
                        ->visible(fn (Forms\Get $get) => $get('type') === 'trending'),
                ]),

            Forms\Components\Section::make('Slides / cards')
                ->description('Drag with the arrows to change the order. Use "Add" for more. Click a row to open it.')
                ->visible(fn (Forms\Get $get) => in_array($get('type'), ['hero', 'cards'], true))
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship('items')
                        ->orderColumn('sort_order')
                        ->reorderable()
                        ->collapsible()
                        ->collapsed()
                        ->cloneable()
                        ->addActionLabel('Add another')
                        ->itemLabel(fn (array $state): ?string => trim(($state['title'] ?? 'New').(! empty($state['is_locked']) ? '  🔒' : '').(empty($state['is_visible']) ? '  (hidden)' : '')))
                        ->columns(2)
                        ->schema([
                            Forms\Components\TextInput::make('title')->required()->maxLength(100),
                            Forms\Components\TextInput::make('badge')->label('Little tag')->maxLength(40)->placeholder('e.g. Daily Payouts'),
                            Forms\Components\Textarea::make('text')->label('Description')->rows(2)->maxLength(200)->columnSpanFull()
                                ->hintAction(AiAssist::action('one short line of homepage card text (under 90 characters)', false, fn (Forms\Get $get) => 'Card title: '.$get('title'))),
                            Forms\Components\Select::make('style')->label('Look')->options(HomeItem::STYLES)->default('featured')->required(),
                            Forms\Components\Select::make('theme')->label('Colour')->options(HomeItem::THEMES)->default('blue')->required(),
                            Forms\Components\Select::make('icon')->label('Icon')->options(HomeItem::ICONS)->placeholder('None'),
                            Forms\Components\Select::make('size')->label('Width (cards only)')->options(['half' => 'Half width', 'full' => 'Full width'])->default('half'),
                            Forms\Components\TextInput::make('link_url')->label('Where it goes')->maxLength(255)->placeholder('/raffles or https://…')
                                ->rule(static::linkRule()),
                            Forms\Components\TextInput::make('link_label')->label('Button text (slides)')->maxLength(40)->placeholder('e.g. Play for Cash'),
                            Forms\Components\TextInput::make('image_url')->label('Background picture address (optional)')->maxLength(255)->placeholder('https://…')
                                ->rule(static::linkRule())->columnSpanFull(),

                            Forms\Components\Fieldset::make('Lock')->columns(2)->schema([
                                Forms\Components\Toggle::make('is_locked')->label('Locked')->default(false)->live()->inline(false)
                                    ->helperText('A locked card is greyed out and can\'t be opened.'),
                                Forms\Components\TextInput::make('locked_label')->label('Words on the lock')->maxLength(40)->placeholder('Coming soon')
                                    ->visible(fn (Forms\Get $get) => (bool) $get('is_locked')),
                                Forms\Components\DateTimePicker::make('unlock_at')->label('Unlock by itself at')->seconds(false)
                                    ->helperText('Optional. Leave empty to unlock it yourself later by switching "Locked" off.')
                                    ->visible(fn (Forms\Get $get) => (bool) $get('is_locked'))->columnSpanFull(),
                            ]),
                            Forms\Components\Fieldset::make('Who and when')->columns(2)->schema([
                                Forms\Components\Toggle::make('is_visible')->label('Showing')->default(true)->inline(false),
                                Forms\Components\Select::make('audience')->label('Who sees it')->options(HomeItem::AUDIENCES)->default('all')->required(),
                                Forms\Components\DateTimePicker::make('starts_at')->label('Start showing')->seconds(false),
                                Forms\Components\DateTimePicker::make('ends_at')->label('Stop showing')->seconds(false)->after('starts_at'),
                            ]),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('type')->label('Block')
                    ->formatStateUsing(fn (string $state) => HomeSection::TYPES[$state] ?? $state)
                    ->description(fn (HomeSection $record) => $record->title),
                Tables\Columns\TextColumn::make('items_count')->label('Slides / cards')->counts('items'),
                Tables\Columns\ToggleColumn::make('is_visible')->label('Showing')
                    ->afterStateUpdated(fn (HomeSection $record) => static::changed('homepage.visibility', $record)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->after(fn (HomeSection $record) => static::changed('homepage.section_deleted', $record)),
            ])
            ->emptyStateHeading('The homepage is using its built-in layout')
            ->emptyStateDescription('Press "Load the default layout" at the top to copy it here, then change anything you like.');
    }

    public static function changed(string $action, HomeSection $section): void
    {
        app(AdminAuditLogService::class)->record(auth('wordpress')->user(), $action, HomeSection::class, (int) $section->id, [
            'type' => $section->type,
            'title' => $section->title,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHomeSections::route('/'),
            'create' => Pages\CreateHomeSection::route('/create'),
            'edit' => Pages\EditHomeSection::route('/{record}/edit'),
        ];
    }
}
