import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { TextInput } from '../../Components/ui/TextInput';
import RaffleCard from '../../Components/raffles/RaffleCard';

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

    return (
        <>
            <Head title="Raffles" />
            <div className="mx-auto min-h-screen max-w-6xl bg-app-bg px-4 py-8 dark:bg-dark-bg">
                <h1 className="mb-6 text-2xl font-bold">Raffles</h1>

                <div className="mb-6 space-y-4">
                    <TextInput
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
        </>
    );
}
