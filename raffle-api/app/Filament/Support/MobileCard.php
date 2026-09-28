<?php

namespace App\Filament\Support;

use Closure;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ViewColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * Phone layout for admin tables. On a phone a wide table needs sideways
 * scrolling, and the buttons are cut off. So each table gets one extra
 * "card" column that only shows on small screens. The card is the row's
 * key facts, stacked. The normal columns only show from tablet width up.
 * The admin stylesheet (filament/hooks/head) then turns every row that
 * has a card into a card, with its buttons along the bottom.
 *
 * The build closure returns any of:
 *   title   the main line (customer, raffle, message author)
 *   amount  shown bold on the right of the title (₦ figure)
 *   body    longer text, up to three lines (a chat message, a subject)
 *   lines   small grey lines under the title
 *   copy    ['label' => shown text, 'value' => copied] — a tap-to-copy
 *           chip, e.g. a bank account number
 *   badges  [[text, colour], ...] — colour is a Filament colour name
 *   meta    small text after the badges (usually "3 hours ago")
 */
final class MobileCard
{
    public static function make(Closure $build): ViewColumn
    {
        return ViewColumn::make('mobile_card')
            ->label('')
            ->view('filament.tables.mobile-card')
            ->state(fn (Model $record): array => array_filter($build($record), fn ($v) => filled($v)))
            ->hiddenFrom('md');
    }

    /**
     * The table's normal columns, shown from tablet width up.
     *
     * @param  array<Column>  $columns
     * @return array<Column>
     */
    public static function desktop(array $columns): array
    {
        return array_map(fn (Column $column) => $column->visibleFrom('md'), $columns);
    }
}
