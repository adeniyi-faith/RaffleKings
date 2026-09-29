import { useEffect, useState } from 'react';
import { Heart } from 'lucide-react';
import { isLiked, setLiked } from '../../lib/tutorialLikes';

// The "helpful" heart on a tutorial: fills red when tapped, tap again to
// take it back. Counts once per person (lib/tutorialLikes.js).
export default function HeartButton({ tutorial, size = 'sm', className = '' }) {
    const [liked, setLikedState] = useState(false);
    const [count, setCount] = useState(tutorial.helpful_count ?? 0);

    // Read after mount: the remembered hearts live in this browser only.
    useEffect(() => {
        setLikedState(isLiked(tutorial));
        setCount(tutorial.helpful_count ?? 0);
    }, [tutorial.id, tutorial.helpful_count, tutorial.liked]);

    async function toggle(e) {
        e.preventDefault();
        e.stopPropagation();

        const next = ! liked;
        setLikedState(next);
        setCount((c) => Math.max(0, c + (next ? 1 : -1)));

        const serverCount = await setLiked(tutorial.id, next);
        if (serverCount !== null) setCount(serverCount);
    }

    const big = size === 'lg';

    return (
        <button
            type="button"
            onClick={toggle}
            aria-pressed={liked}
            aria-label={liked ? 'Remove your heart' : 'Mark as helpful'}
            className={[
                'inline-flex items-center gap-1.5 transition-all active:scale-90',
                big ? 'rounded-full border px-4 py-2 text-xs font-bold' : 'text-xs',
                liked
                    ? big
                        ? 'border-red-200 bg-red-50 text-red-500 dark:border-red-900/50 dark:bg-red-900/20'
                        : 'text-red-500'
                    : big
                      ? 'border-gray-200 text-gray-600 hover:border-red-200 hover:text-red-500 dark:border-gray-700 dark:text-gray-300'
                      : 'text-gray-400 hover:text-red-500',
                className,
            ].join(' ')}
        >
            <Heart className={`${big ? 'h-4 w-4' : 'h-3.5 w-3.5'} ${liked ? 'fill-current' : ''}`} />
            {big ? `${liked ? 'You found this helpful' : 'Helpful'} (${count})` : count}
        </button>
    );
}
