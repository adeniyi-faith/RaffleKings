<?php

namespace Tests\Feature;

use App\Filament\Resources\RaffleResource\Pages\CreateRaffle;
use App\Filament\Resources\SupportTicketResource\Pages\ViewSupportTicket;
use App\Models\AppSetting;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** The "Write with AI" / "Draft with AI" buttons show whenever AI helpers are on. */
class AiWritingButtonsTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    public function test_the_buttons_show_when_ai_is_on_even_without_a_key(): void
    {
        $this->actingAsAdministrator();
        config(['ai.enabled' => true, 'services.gemini.api_key' => null]);

        Livewire::test(CreateRaffle::class)
            ->assertFormComponentActionVisible('title', 'aiWrite')
            ->assertFormComponentActionVisible('excerpt', 'aiWrite');
    }

    public function test_the_buttons_are_hidden_when_ai_is_off(): void
    {
        $this->actingAsAdministrator();
        config(['ai.enabled' => false, 'services.gemini.api_key' => 'test-key']);

        Livewire::test(CreateRaffle::class)
            ->assertFormComponentActionHidden('title', 'aiWrite')
            ->assertFormComponentActionHidden('excerpt', 'aiWrite');
    }

    public function test_pressing_it_without_a_key_explains_what_is_missing(): void
    {
        $this->actingAsAdministrator();
        config(['ai.enabled' => true, 'services.gemini.api_key' => null]);
        Http::fake();

        Livewire::test(CreateRaffle::class)
            ->callFormComponentAction('title', 'aiWrite')
            ->assertNotified('AI could not write this')
            ->assertFormSet(['title' => null]);

        Http::assertNothingSent();
    }

    public function test_the_title_button_writes_a_title(): void
    {
        $this->actingAsAdministrator();
        config(['ai.enabled' => true, 'ai.daily_limit' => 50, 'services.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Win ₦500,000 This Friday']]]]],
        ])]);

        Livewire::test(CreateRaffle::class)
            ->fillForm(['grand_prize' => '₦500,000', 'price' => 200])
            ->callFormComponentAction('title', 'aiWrite', ['instruction' => ''])
            ->assertFormSet(['title' => 'Win ₦500,000 This Friday']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'gemini-3.8-flash:generateContent'));
    }

    public function test_the_ticket_reply_box_offers_an_ai_draft_without_a_key(): void
    {
        $admin = $this->actingAsAdministrator();
        config(['ai.enabled' => true, 'services.gemini.api_key' => null]);
        $ticket = SupportTicket::create(['user_id' => $admin->ID, 'subject' => 'Hi', 'status' => 'open']);

        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->mountAction('reply')
            ->assertSee('Draft with AI');
    }

    public function test_an_old_saved_model_is_cleared_so_the_new_default_applies(): void
    {
        AppSetting::query()->create(['key' => 'services.gemini.model', 'value' => json_encode('gemini-2.5-flash-preview-09-2025')]);
        AppSetting::query()->create(['key' => 'services.gemini.assistant_model', 'value' => json_encode('gemini-3.5-flash-lite')]);

        (require database_path('migrations/2026_10_21_000001_move_saved_gemini_models_to_flash_3.php'))->up();

        $this->assertFalse(AppSetting::query()->where('key', 'services.gemini.model')->exists());
        $this->assertTrue(AppSetting::query()->where('key', 'services.gemini.assistant_model')->exists());
    }
}
