<?php

namespace App\Filament\Resources\PredictionResource\Pages;

use App\Exceptions\AiUnavailableException;
use App\Filament\Resources\PredictionResource;
use App\Models\Legacy\WpUser;
use App\Models\Prediction;
use App\Services\AdminAuditLogService;
use App\Services\Ai\ExaSearch;
use App\Services\Ai\GeminiClient;
use App\Services\Engagement\PredictionWriter;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPredictions extends ListRecords
{
    protected static string $resource = PredictionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('aiDraft')
                ->label('Write with AI')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn () => app(GeminiClient::class)->switchedOn())
                ->modalHeading('Write questions with AI')
                ->modalDescription(fn () => app(ExaSearch::class)->available()
                    ? 'The AI looks up real upcoming matches and news on the web, then writes questions. They are saved as drafts: customers only see them after you check each one and press "Publish".'
                    : 'The AI writes quiz questions. Add an Exa key in Settings → AI → Exa web search to let it look up real matches and news. Questions are saved as drafts until you publish them.')
                ->modalSubmitActionLabel('Write them')
                ->form([
                    Forms\Components\Select::make('category')->label('Kind of question')
                        ->options([PredictionWriter::ANY => 'Let the AI choose'] + Prediction::CATEGORIES)
                        ->default(PredictionWriter::ANY)->required(),
                    Forms\Components\TextInput::make('count')->label('How many')->numeric()->minValue(1)->maxValue(10)->default(3)->required(),
                    Forms\Components\Textarea::make('focus')->label('What about? (optional)')->rows(2)->maxLength(300)
                        ->placeholder('e.g. This weekend\'s Premier League games, or Super Eagles qualifiers')
                        ->default(fn () => config('engagement.predictions.ai_focus')),
                    Forms\Components\Toggle::make('use_web')->label('Look things up on the web first (Exa)')->default(true)
                        ->visible(fn () => app(ExaSearch::class)->available()),
                ])
                ->action(function (array $data) {
                    if (! app(GeminiClient::class)->available()) {
                        Notification::make()->title('AI could not write these')->body(GeminiClient::NO_KEY_MESSAGE)->danger()->send();

                        return;
                    }

                    try {
                        $drafts = app(PredictionWriter::class)->draft((string) $data['category'], (int) $data['count'], $data['focus'] ?? null, (bool) ($data['use_web'] ?? true));
                    } catch (AiUnavailableException $e) {
                        Notification::make()->title('AI could not write these')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    if ($drafts === []) {
                        Notification::make()->title('No usable questions this time')->body('Try again, or tell it what to write about.')->warning()->send();

                        return;
                    }

                    if (($admin = auth('wordpress')->user()) instanceof WpUser) {
                        app(AdminAuditLogService::class)->record($admin, 'prediction.ai_drafted', Prediction::class, $drafts[0]->id, ['count' => count($drafts)]);
                    }

                    Notification::make()->title(count($drafts).' draft question(s) written')
                        ->body('Open each one, check the facts and closing time, then press "Publish".')->success()->send();
                }),
            Actions\CreateAction::make()->label('New question'),
        ];
    }
}
