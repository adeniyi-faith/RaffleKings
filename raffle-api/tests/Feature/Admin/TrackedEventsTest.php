<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\TrackedEvents;
use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** System → Tracked Events: one switch per recorded event. */
class TrackedEventsTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    public function test_the_screen_opens_for_an_admin_and_lists_events_in_plain_words(): void
    {
        $this->actingAsAdministrator();

        $this->get('/admin/tracked-events')->assertOk()->assertSee('Tickets bought')->assertSee('Page views');
    }

    public function test_switching_events_off_saves_them_and_applies_at_once(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(TrackedEvents::class)
            ->set('data.'.TrackedEvents::field('tickets_purchased'), false)
            ->set('data.'.TrackedEvents::field('$autocapture'), false)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['tickets_purchased', '$autocapture'], config('services.analytics.disabled_events'));
        $this->assertNotNull(AppSetting::query()->where('key', 'services.analytics.disabled_events')->first());
    }

    public function test_switching_everything_back_on_clears_the_saved_list(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(TrackedEvents::class)
            ->set('data.'.TrackedEvents::field('raffle_won'), false)
            ->call('save')
            ->set('data.'.TrackedEvents::field('raffle_won'), true)
            ->call('save');

        $this->assertSame([], config('services.analytics.disabled_events'));
        $this->assertNull(AppSetting::query()->where('key', 'services.analytics.disabled_events')->first());
    }
}
