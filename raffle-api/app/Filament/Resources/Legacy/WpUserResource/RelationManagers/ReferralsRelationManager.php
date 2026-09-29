<?php

namespace App\Filament\Resources\Legacy\WpUserResource\RelationManagers;

use App\Filament\Resources\Legacy\WpUserResource;
use App\Filament\Support\MobileCard;
use App\Models\ReferralCommission;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Commission this customer earned from friends they brought. */
class ReferralsRelationManager extends RelationManager
{
    protected static string $relationship = 'referralCommissions';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Referrals';

    protected static ?string $icon = 'heroicon-o-user-plus';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('referee'))
            ->recordUrl(fn (ReferralCommission $r) => $r->referee ? WpUserResource::getUrl('view', ['record' => $r->referee]) : null)
            ->columns([
                MobileCard::make(fn (ReferralCommission $r) => [
                    'title' => $r->referee?->display_name ?: $r->referee?->user_login,
                    'amount' => '+₦'.number_format((float) $r->commission_amount),
                    'lines' => ['First top-up ₦'.number_format((float) $r->deposit_amount)],
                    'meta' => $r->created_at?->format('j M Y'),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('referee.display_name')->label('Friend'),
                    Tables\Columns\TextColumn::make('deposit_amount')->label('Friend\'s first top-up')->money('NGN'),
                    Tables\Columns\TextColumn::make('commission_amount')->label('Earned')->money('NGN')->weight('bold'),
                    Tables\Columns\TextColumn::make('created_at')->label('Paid')->date('j M Y'),
                ]),
            ])
            ->emptyStateHeading('No referral commission yet');
    }
}
