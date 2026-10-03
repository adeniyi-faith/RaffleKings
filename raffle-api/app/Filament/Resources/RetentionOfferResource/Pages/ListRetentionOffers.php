<?php

namespace App\Filament\Resources\RetentionOfferResource\Pages;

use App\Filament\Pages\MemberSegments;
use App\Filament\Resources\RetentionOfferResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRetentionOffers extends ListRecords
{
    protected static string $resource = RetentionOfferResource::class;

    public function getSubheading(): ?string
    {
        return 'Personal, time-limited gifts sent automatically to customers who are slipping away. Money is paid only when they claim, as ticket credit.';
    }

    protected function getHeaderActions(): array
    {
        return [Actions\Action::make('segments')->label('Member segments')->icon('heroicon-o-user-group')->color('gray')->url(MemberSegments::getUrl())];
    }
}
