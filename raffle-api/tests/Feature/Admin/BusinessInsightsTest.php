<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\BusinessInsights;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Services\Reports\BusinessInsights as Insights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** Item 36: the old admin's "AI Insights" page, rebuilt as Business insights. */
class BusinessInsightsTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function sale(int $userId, float $amount, string $when): void
    {
        $t = RaffleTransaction::create(['user_id' => $userId, 'claimed_amount' => $amount, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        $t->forceFill(['created_at' => $when])->save();
    }

    public function test_the_figures_compare_this_period_with_the_one_before(): void
    {
        $a = WpUser::create(['user_login' => 'amaka', 'user_pass' => 'x', 'user_email' => 'a@example.com', 'display_name' => 'Amaka']);
        $this->sale($a->ID, 500, now()->subDays(2));
        $this->sale($a->ID, 300, now()->subDays(3));
        $this->sale($a->ID, 1000, now()->subDays(10)); // the week before

        $f = app(Insights::class)->figures('week');

        $this->assertSame(800.0, $f['current']['sales']);
        $this->assertSame(2, $f['current']['purchases']);
        $this->assertSame(1, $f['current']['buyers']);
        $this->assertSame(1000.0, $f['previous']['sales']);
    }

    public function test_the_ai_summary_gets_totals_only_and_its_html_is_cleaned(): void
    {
        config(['ai.enabled' => true, 'services.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '<h3>Summary</h3><p>Sales rose.</p><script>alert(1)</script>']]]]],
        ])]);
        $a = WpUser::create(['user_login' => 'secretname', 'user_pass' => 'x', 'user_email' => 'secret@example.com', 'display_name' => 'Secret Person']);
        $this->sale($a->ID, 500, now()->subDay());

        $insights = app(Insights::class);
        $html = $insights->summary($insights->figures('week'));

        $this->assertStringContainsString('Sales rose.', $html);
        $this->assertStringNotContainsString('<script', $html);
        Http::assertSent(function (Request $request) {
            $body = json_encode($request->data());

            return ! str_contains($body, 'secretname') && ! str_contains($body, 'secret@example.com') && str_contains($body, 'sales');
        });
    }

    public function test_the_page_opens_for_admins_and_works_without_a_gemini_key(): void
    {
        config(['services.gemini.api_key' => null]);
        Raffle::create(['public_id' => 9, 'title' => 'Big Raffle', 'price' => 100, 'max_tickets' => 100, 'grand_prize' => 'Car', 'status' => 'published']);
        $this->actingAsAdministrator();

        Livewire::test(BusinessInsights::class)
            ->assertOk()
            ->assertSee('Ticket sales')
            ->assertSee('Add a Gemini key')
            ->set('period', 'month')
            ->assertSet('figures.period', 'month');
    }

    public function test_customers_cannot_open_it(): void
    {
        $this->actingAsWordPressUser();

        $this->get('/admin/business-insights')->assertForbidden();
    }
}
