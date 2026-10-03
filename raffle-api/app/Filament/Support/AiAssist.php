<?php

namespace App\Filament\Support;

use App\Exceptions\AiUnavailableException;
use App\Services\Ai\AiWriter;
use App\Services\Ai\GeminiClient;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * The small "Write with AI" button next to a text box. Opens a box asking
 * what it should say (optional), fills the text box with the result, and
 * can be pressed again to regenerate. It never saves anything by itself:
 * staff read the text and save the form as usual.
 */
final class AiAssist
{
    /**
     * @param  string  $purpose  What the text is, e.g. "a short announcement shown to every customer".
     * @param  bool  $html  True for rich-text editors.
     * @param  (\Closure(Forms\Get): ?string)|null  $context  Facts from the form to give the AI (title, prize...).
     */
    public static function action(string $purpose, bool $html = false, ?\Closure $context = null): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('aiWrite')
            ->label('Write with AI')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->visible(fn () => app(GeminiClient::class)->switchedOn())
            ->modalHidden(fn () => ! app(GeminiClient::class)->available())
            ->modalHeading('Write with AI')
            ->modalDescription('Tell it what to say, or leave this empty for a first draft. If the box already has text, the AI improves it. You can press the button again to get a new version.')
            ->modalSubmitActionLabel('Write it')
            ->form([
                Forms\Components\Textarea::make('instruction')
                    ->label('What should it say? (optional)')
                    ->rows(3)
                    ->maxLength(500)
                    ->placeholder('e.g. Friendly, short, mention the draw is on Friday'),
            ])
            ->action(function (array $data, Forms\Components\Component $component, Forms\Get $get, Forms\Set $set) use ($purpose, $html, $context) {
                $name = $component->getName();

                if (! app(GeminiClient::class)->available()) {
                    Notification::make()->title('AI could not write this')->body(GeminiClient::NO_KEY_MESSAGE)->danger()->send();

                    return;
                }

                try {
                    $text = app(AiWriter::class)->write(
                        $purpose,
                        (string) $get($name),
                        (string) ($data['instruction'] ?? ''),
                        $html,
                        $context ? $context($get) : null,
                    );
                } catch (AiUnavailableException $e) {
                    Notification::make()->title('AI could not write this')->body($e->getMessage())->danger()->send();

                    return;
                }

                $set($name, $html ? $text : strip_tags($text));
                Notification::make()->title('Written. Read it, then save.')->body('Press "Write with AI" again for a different version.')->success()->send();
            });
    }
}
