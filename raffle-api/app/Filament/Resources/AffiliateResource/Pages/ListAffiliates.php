<?php

namespace App\Filament\Resources\AffiliateResource\Pages;

use App\Filament\Resources\AffiliateResource;
use App\Support\Features;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAffiliates extends ListRecords
{
    protected static string $resource = AffiliateResource::class;

    public function getSubheading(): ?string
    {
        return Features::offNotice('affiliates') ?? 'Earnings are held a few days, then paid into the affiliate\'s winnings automatically. Ones that look like self-referrals wait on Users → Fraud watch.';
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New affiliate')];
    }
}
