<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\SitePageResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\SitePage;
use App\Services\AdminAuditLogService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Site → Pages (item 48): edit the Terms of Service and About pages
 * without a developer. They can be edited, not added or deleted, since
 * the site links to exactly these.
 */
class SitePageResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    protected static ?string $model = SitePage::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Pages';

    protected static ?string $modelLabel = 'page';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('title')->required()->maxLength(120),
                Forms\Components\Textarea::make('summary')->label('Short description')->rows(2)->maxLength(300)
                    ->helperText('Shown under the title, and in link previews when the page is shared.'),
                Forms\Components\RichEditor::make('body')
                    ->label('Text')
                    ->required()
                    ->disableToolbarButtons(['attachFiles', 'codeBlock'])
                    ->helperText('Anything unsafe is removed when you save. Write {support_email_sentence} where the support email should appear (it shows as ", or by email at …" when a support email is set in Settings). Customers see "Last updated" with the date you save.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                MobileCard::make(fn (SitePage $p) => [
                    'title' => $p->title,
                    'lines' => [$p->path()],
                    'meta' => 'Edited '.$p->updated_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('title')->weight('bold')->description(fn (SitePage $p) => $p->summary),
                    Tables\Columns\TextColumn::make('slug')->label('Address')->formatStateUsing(fn ($state, SitePage $p) => $p->path()),
                    Tables\Columns\TextColumn::make('updated_at')->label('Edited')->since(),
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('open')
                    ->label('View')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (SitePage $p) => url($p->path()), shouldOpenInNewTab: true),
            ]);
    }

    public static function changed(SitePage $page): void
    {
        app(AdminAuditLogService::class)->record(static::admin(), 'site_page.updated', SitePage::class, (int) $page->id, ['title' => $page->title]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSitePages::route('/'),
            'edit' => Pages\EditSitePage::route('/{record}/edit'),
        ];
    }
}
