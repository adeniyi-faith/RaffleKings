<?php

namespace Tests\Feature;

use App\Filament\Resources\HomeSectionResource\Pages\CreateHomeSection;
use App\Filament\Resources\HomeSectionResource\Pages\EditHomeSection;
use App\Filament\Resources\HomeSectionResource\Pages\ListHomeSections;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CreateKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use App\Filament\Resources\SupportTicketResource\Pages\ViewSupportTicket;
use App\Models\HomeSection;
use App\Models\KnowledgeArticle;
use App\Models\SupportTicket;
use App\Services\HomeLayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** The new admin screens open and their forms save. */
class AdminHomepageAndKnowledgeTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    public function test_the_homepage_screen_can_load_the_default_layout(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(ListHomeSections::class)
            ->callAction('defaults')
            ->assertHasNoActionErrors();

        $this->assertSame(4, HomeSection::count());
    }

    public function test_a_block_with_cards_can_be_created_and_edited(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(CreateHomeSection::class)
            ->fillForm([
                'type' => 'cards',
                'title' => 'Extras',
                'items' => [['title' => 'New card', 'style' => 'tile', 'theme' => 'green', 'size' => 'half', 'is_visible' => true, 'audience' => 'all']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $section = HomeSection::firstOrFail();
        $this->assertSame('New card', $section->items()->first()->title);

        Livewire::test(EditHomeSection::class, ['record' => $section->getKey()])
            ->assertFormSet(['title' => 'Extras'])
            ->fillForm(['title' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed', $section->refresh()->title);
    }

    public function test_a_bad_link_is_refused(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(CreateHomeSection::class)
            ->fillForm([
                'type' => 'cards',
                'items' => [['title' => 'Bad', 'link_url' => 'javascript:alert(1)', 'style' => 'tile', 'theme' => 'green', 'size' => 'half', 'is_visible' => true, 'audience' => 'all']],
            ])
            ->call('create')
            ->assertHasFormErrors();
    }

    public function test_knowledge_entries_can_be_written_and_listed(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(CreateKnowledgeArticle::class)
            ->fillForm(['title' => 'Deposits', 'body' => 'Deposits show in seconds.', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(ListKnowledgeArticles::class)->assertCanSeeTableRecords(KnowledgeArticle::all());
    }

    public function test_a_ticket_page_opens_with_the_ai_draft_button(): void
    {
        $admin = $this->actingAsAdministrator();
        $ticket = SupportTicket::create(['user_id' => $admin->ID, 'subject' => 'Hi', 'status' => 'open']);

        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])->assertSuccessful();
    }

    public function test_suggestions_learned_from_tickets_are_listed_for_checking(): void
    {
        $this->actingAsAdministrator();
        $entry = KnowledgeArticle::create(['title' => 'Prize delivery', 'body' => 'Within 7 days.', 'is_active' => false, 'suggested_from_ticket_id' => 5]);
        KnowledgeArticle::create(['title' => 'Deposits', 'body' => 'Instant.', 'is_active' => true]);

        Livewire::test(ListKnowledgeArticles::class)
            ->assertSee('Learned from ticket #5')
            ->filterTable('to_check')
            ->assertCanSeeTableRecords([$entry])
            ->assertCountTableRecords(1);

        $this->assertSame('1', \App\Filament\Resources\KnowledgeArticleResource::getNavigationBadge());
    }
}
