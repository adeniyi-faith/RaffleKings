<?php

namespace App\Filament\Resources;

use App\Exceptions\AiUnavailableException;
use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\KnowledgeArticleResource\Pages;
use App\Filament\Support\AiAssist;
use App\Models\KnowledgeArticle;
use App\Services\Ai\GeminiClient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Support → Knowledge base: what the platform is and how it works, in
 * plain words. The AI support agent answers ONLY from this (plus the
 * customer's own account), so if it isn't written here, the agent will
 * hand the ticket to a person instead of guessing.
 */
class KnowledgeArticleResource extends Resource
{
    use GuardedByStaffRole;

    protected static ?string $model = KnowledgeArticle::class;

    protected static ?string $slug = 'knowledge-base';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Knowledge base';

    protected static ?string $modelLabel = 'knowledge entry';

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    /** Suggestions learned from solved tickets, waiting for a person to check them. */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = KnowledgeArticle::query()->whereNotNull('suggested_from_ticket_id')->where('is_active', false)->count();
        } catch (\Throwable) {
            return null; // column not added yet (mid-deploy)
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Suggested entries learned from solved tickets. Check them, then switch on "AI may use".';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('title')->required()->maxLength(150)
                    ->placeholder('e.g. How long do withdrawals take?'),
                Forms\Components\Toggle::make('is_active')->label('The AI may use this')->default(true)->inline(false),
                Forms\Components\FileUpload::make('upload')
                    ->label('Or upload a file to fill the text below')
                    ->helperText('A text file (.txt, .md, .csv) or a PDF. The words are copied into the box so you can check and edit them. The file itself is not kept.')
                    ->acceptedFileTypes(['text/plain', 'text/markdown', 'text/csv', 'application/pdf'])
                    ->maxSize(4096)
                    ->disk('local')
                    ->directory('knowledge-uploads')
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        static::readUpload($state, $set);
                    })
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('body')
                    ->label('The answer / information')
                    ->required()
                    ->rows(12)
                    ->maxLength(30000)
                    ->helperText('Write it the way you would explain it to a customer: rules, amounts, times, steps. One topic per entry works best.')
                    ->hintAction(AiAssist::action('a clear help entry about how the platform works, for the support knowledge base (facts only: use what is already written or what the team says)', false, fn (Forms\Get $get) => 'Topic: '.$get('title')))
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    /** Copy an uploaded text file (or PDF) into the body box. */
    private static function readUpload(mixed $state, Forms\Set $set): void
    {
        $file = is_array($state) ? reset($state) : $state;
        if (! $file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
            return;
        }

        $mime = (string) $file->getMimeType();
        $text = '';

        try {
            if ($mime === 'application/pdf') {
                $text = app(GeminiClient::class)->generate(
                    'knowledge-pdf',
                    'You copy the text out of documents accurately. Reply with the document text only, no comments.',
                    'Copy all the readable text of this document, keeping headings and lists as plain text.',
                    files: [['mime_type' => 'application/pdf', 'data' => base64_encode((string) file_get_contents($file->getRealPath()))]],
                );
            } else {
                $text = (string) file_get_contents($file->getRealPath());
                if (! mb_check_encoding($text, 'UTF-8')) {
                    $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
                }
            }
        } catch (AiUnavailableException $e) {
            Notification::make()->title('Could not read the PDF')->body($e->getMessage())->danger()->send();

            return;
        }

        $set('body', Str::limit(trim($text), 30000, ''));
        Notification::make()->title('File copied into the box. Check it, then save.')->success()->send();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->weight('bold')->wrap()
                    ->description(fn (KnowledgeArticle $r) => Str::limit(preg_replace('/\s+/', ' ', $r->body), 100)),
                Tables\Columns\TextColumn::make('suggested_from_ticket_id')->label('Source')
                    ->formatStateUsing(fn ($state) => "Learned from ticket #{$state}")
                    ->url(fn (KnowledgeArticle $r) => $r->suggested_from_ticket_id ? SupportTicketResource::getUrl('view', ['record' => $r->suggested_from_ticket_id]) : null)
                    ->badge()->color('info')
                    ->placeholder('Written by staff'),
                Tables\Columns\ToggleColumn::make('is_active')->label('AI may use'),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated')->since(),
            ])
            ->filters([
                Tables\Filters\Filter::make('to_check')->label('Suggestions to check')
                    ->query(fn ($query) => $query->whereNotNull('suggested_from_ticket_id')->where('is_active', false)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('The Knowledge base is empty')
            ->emptyStateDescription('Add entries about deposits, withdrawals, draws and rules. Until then the AI support agent will not answer anything by itself.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKnowledgeArticles::route('/'),
            'create' => Pages\CreateKnowledgeArticle::route('/create'),
            'edit' => Pages\EditKnowledgeArticle::route('/{record}/edit'),
        ];
    }
}
