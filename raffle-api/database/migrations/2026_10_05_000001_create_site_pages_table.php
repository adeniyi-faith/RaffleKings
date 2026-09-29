<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pages admins can edit (Site → Pages, item 48): the Terms of Service and
 * About page. Filled with the starting text in resources/content/pages,
 * written for RKS DIGITAL INNOVATIONS. Never overwrites an edited page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_pages')) {
            Schema::create('site_pages', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 40)->unique();
                $table->string('title', 120);
                $table->string('summary', 300)->nullable();
                $table->longText('body');
                $table->timestamps();
            });
        }

        $pages = [
            'terms' => ['Terms of Service', 'The rules for using RaffleKings: who can play, tickets, draws, prizes, withdrawals and rewards.'],
            'about' => ['About RaffleKings', 'What RaffleKings is, how raffles and draws work, and how we keep them fair.'],
        ];

        foreach ($pages as $slug => [$title, $summary]) {
            if (DB::table('site_pages')->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table('site_pages')->insert([
                'slug' => $slug,
                'title' => $title,
                'summary' => $summary,
                'body' => file_get_contents(resource_path("content/pages/{$slug}.html")),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_pages');
    }
};
