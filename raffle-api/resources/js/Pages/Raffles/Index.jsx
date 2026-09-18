import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Flame, Search, SlidersHorizontal } from 'lucide-react';
import { TextInput } from '../../Components/ui/TextInput';
import RaffleCard from '../../Components/raffles/RaffleCard';
import Header from '../../Components/layout/Header';
import BottomNav from '../../Components/layout/BottomNav';

const SORT_OPTIONS = [
    { value: 'newest', label: 'Newest' },
    { value: 'closing_soon', label: 'Closing soon' },
    { value: 'price_asc', label: 'Price: low to high' },
    { value: 'price_desc', label: 'Price: high to low' },
];

const PRIZE_TYPE_LABELS = { cash: 'Cash', gadgets: 'Gadgets', vouchers: 'Vouchers', other: 'Other' };

export default function RafflesIndex({ initial }) {
    const [result, setResult] = useState(initial);
    const [search, setSearch] = useState('');
    const [prizeType, setPrizeType] = useState('all');
    const [minPrice, setMinPrice] = useState('');
    const [maxPrice, setMaxPrice] = useState('');
    const [sort, setSort] = useState('newest');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(false);
    const debounceRef = useRef(null);

    useEffect(() => {
        // Skip the very first render — `initial` already came from the
        // server render with these (default) filter values.
        if (debounceRef.current === null) {
            debounceRef.current = true;
            return;
        }

        const timeout = setTimeout(fetchRaffles, 300);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, prizeType, minPrice, maxPrice, sort, page]);

    async function fetchRaffles() {
        setLoading(true);

        const params = new URLSearchParams({
            sort,
            page: String(page),
            ...(search ? { search } : {}),
            ...(prizeType !== 'all' ? { prize_type: prizeType } : {}),
            ...(minPrice ? { min_price: minPrice } : {}),
            ...(maxPrice ? { max_price: maxPrice } : {}),
        });

        try {
            const response = await fetch(`/api/raffles?${params}`);
            setResult(await response.json());
        } finally {
            setLoading(false);
        }
    }

    function updateFilter(setter) {
        return (value) => {
            setPage(1);
            setter(value);
        };
    }

    const totalPages = Math.max(1, Math.ceil(result.total / result.per_page));

    // Same "> 50% sold, not closed" hot-pick rule as raffles.php's
    // renderHotPicks(), capped at 5 — a derived list, no extra fetch needed.
    const hotPicks = useMemo(() => {
        return result.raffles
            .filter((r) => {
                const progress = r.max_tickets > 0 ? (r.sold_tickets / r.max_tickets) * 100 : 0;
                return progress > 50 && !r.is_closed;
            })
            .slice(0, 5);
    }, [result.raffles]);

    return (
        <>
            <Head title="Raffles" />
            <div className="flex min-h-screen w-full flex-col bg-app-bg text-gray-900 dark:bg-dark-bg dark:text-white">
                <Header />

                <div className="mx-auto w-full max-w-6xl flex-1 px-4 pb-28 pt-6">
                    <h1 className="mb-6 text-2xl font-bold text-gray-900 dark:text-white">Raffles</h1>

                {hotPicks.length > 0 && (
                    <div className="mb-6">
                        <div className="mb-2 flex items-center gap-2">
                            <Flame className="h-3 w-3 animate-pulse fill-current text-orange-500" />
                            <h2 className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                Ending Soon
                            </h2>
                        </div>
                        <div className="flex gap-3 overflow-x-auto pb-2">
                            {hotPicks.map((raffle) => {
                                const progress =
                                    raffle.max_tickets > 0 ? Math.round((raffle.sold_tickets / raffle.max_tickets) * 100) : 0;

                                return (
                                    <Link
                                        key={raffle.id}
                                        href={`/raffles/${raffle.id}`}
                                        className="relative min-w-[140px] overflow-hidden rounded-xl border border-green-500/30 bg-gradient-to-br from-green-600 to-emerald-900 p-3 shadow-md shadow-green-900/10 transition-transform active:scale-95"
                                    >
                                        <div className="absolute right-0 top-0 h-8 w-8 rounded-full bg-white/10 blur-xl" />
                                        <h4 className="relative z-10 mb-1 truncate text-xs font-bold text-white">{raffle.title}</h4>
                                        <div className="relative z-10 mb-1.5 h-1.5 w-full rounded-full bg-black/20 backdrop-blur-sm">
                                            <div
                                                className="h-1.5 rounded-full bg-white shadow-[0_0_5px_rgba(255,255,255,0.5)]"
                                                style={{ width: `${progress}%` }}
                                            />
                                        </div>
                                        <div className="relative z-10 flex items-center justify-between text-[9px] font-medium text-green-100">
                                            <span>{progress}% Sold</span>
                                            <span className="flex items-center gap-1 text-white">
                                                <Flame className="h-2 w-2 fill-current" /> Hot
                                            </span>
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                )}

                <div className="mb-6 space-y-4">
                    <TextInput
                        icon={Search}
                        placeholder="Search prizes..."
                        value={search}
                        onChange={(e) => updateFilter(setSearch)(e.target.value)}
                    />

                    <div className="flex flex-wrap gap-2">
                        {['all', ...result.prize_types].map((type) => (
                            <button
                                key={type}
                                onClick={() => updateFilter(setPrizeType)(type)}
                                className={[
                                    'rounded-full px-4 py-1.5 text-sm font-semibold transition-colors',
                                    prizeType === type
                                        ? 'bg-app-primary text-white'
                                        : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
                                ].join(' ')}
                            >
                                {type === 'all' ? 'All' : PRIZE_TYPE_LABELS[type] || type}
                            </button>
                        ))}
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <SlidersHorizontal className="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />
                        <TextInput
                            type="number"
                            placeholder="Min price"
                            value={minPrice}
                            onChange={(e) => updateFilter(setMinPrice)(e.target.value)}
                            className="w-32"
                        />
                        <TextInput
                            type="number"
                            placeholder="Max price"
                            value={maxPrice}
                            onChange={(e) => updateFilter(setMaxPrice)(e.target.value)}
                            className="w-32"
                        />

                        <select
                            value={sort}
                            onChange={(e) => updateFilter(setSort)(e.target.value)}
                            className="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm font-bold text-gray-800 outline-none dark:border-gray-700 dark:bg-dark-bg dark:text-white"
                        >
                            {SORT_OPTIONS.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {loading && <p className="mb-4 text-sm text-gray-500">Loading…</p>}

                {result.raffles.length === 0 ? (
                    <p className="text-sm text-gray-500 dark:text-gray-400">No raffles match your filters.</p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {result.raffles.map((raffle) => (
                            <RaffleCard key={raffle.id} raffle={raffle} />
                        ))}
                    </div>
                )}

                {totalPages > 1 && (
                    <div className="mt-8 flex items-center justify-center gap-4">
                        <button
                            disabled={page <= 1}
                            onClick={() => setPage((p) => p - 1)}
                            className="text-sm font-medium text-app-primary disabled:cursor-not-allowed disabled:text-gray-300 dark:disabled:text-gray-700"
                        >
                            Previous
                        </button>
                        <span className="text-sm text-gray-500 dark:text-gray-400">
                            Page {result.page} of {totalPages}
                        </span>
                        <button
                            disabled={page >= totalPages}
                            onClick={() => setPage((p) => p + 1)}
                            className="text-sm font-medium text-app-primary disabled:cursor-not-allowed disabled:text-gray-300 dark:disabled:text-gray-700"
                        >
                            Next
                        </button>
                    </div>
                )}
                </div>

                <BottomNav />
            </div>
        </>
    );
}
