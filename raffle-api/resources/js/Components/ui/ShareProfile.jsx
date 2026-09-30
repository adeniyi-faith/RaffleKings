import { useState } from 'react';
import { Check, Copy, Share2 } from 'lucide-react';
import { profileShareText, shareLink } from '../../lib/share';
import { track } from '../../lib/analytics';

// "Share my profile" and "Copy link" for a player's own public card.
// `url` is the full address of the card; `from` says which page the buttons are on.
export default function ShareProfile({ username, url, from, className = '' }) {
    const [copied, setCopied] = useState(false);

    function share() {
        track('profile_link_shared', { method: navigator.share ? 'share_sheet' : 'whatsapp', from });
        shareLink(profileShareText({ username, url }));
    }

    function copy() {
        track('profile_link_shared', { method: 'copy', from });
        navigator.clipboard?.writeText(url).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    }

    return (
        <div className={`flex gap-2 ${className}`}>
            <button onClick={share} className="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-white px-3 py-2.5 text-xs font-bold text-indigo-700 shadow active:scale-95">
                <Share2 className="h-4 w-4" /> Share my profile
            </button>
            <button onClick={copy} className="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-white/20 px-3 py-2.5 text-xs font-bold text-white active:scale-95">
                {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />} {copied ? 'Copied' : 'Copy link'}
            </button>
        </div>
    );
}
