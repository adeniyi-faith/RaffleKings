<?php

namespace App\Filament\Resources\PromoCodeResource\Pages;

use App\Filament\Resources\PromoCodeResource;
use App\Support\Features;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPromoCodes extends ListRecords
{
    protected static string $resource = PromoCodeResource::class;

    public function getSubheading(): ?string
    {
        return Features::offNotice('promo_codes') ?? 'Customers type a code at sign-up or checkout, or open a link like '.url('/register?promo=CODE').'.';
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New promo code')];
    }
}
