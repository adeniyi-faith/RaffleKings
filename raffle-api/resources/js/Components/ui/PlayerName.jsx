import { Link } from '@inertiajs/react';

// A player's name that opens their public profile card when they have one.
// `profile` is the card's path from the server (null = private, so plain text).
// Use this everywhere a player's name is shown, so profiles are clickable from every page.
export default function PlayerName({ name, profile = null, className = '', children = null }) {
    if (! profile) {
        return <span className={className}>{children ?? name}</span>;
    }

    return (
        <Link href={profile} className={`underline decoration-dotted underline-offset-2 hover:decoration-solid ${className}`}>
            {children ?? name}
        </Link>
    );
}
