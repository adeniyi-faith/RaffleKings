<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** The new screens really appear in the admin's menu. */
class AdminMenuTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    public function test_the_menu_lists_the_homepage_builder_and_knowledge_base(): void
    {
        $this->actingAsAdministrator();

        $this->get('/admin')->assertOk()->assertSee('Knowledge base')->assertSee('Homepage');
    }
}
