import { ChevronRight, Lock } from 'lucide-react';
import { ICONS, SmartLink, themeOf } from './homeTheme';

// One card in a "cards" block. Three looks: a bold colour card, a white
// tile with a small icon, or a simple dashed box. A locked card is greyed
// out and can't be opened.
function Card({ item }) {
    const theme = themeOf(item.theme);
    const Icon = ICONS[item.icon];
    const span = item.size === 'full' ? 'col-span-2' : '';
    const Wrapper = item.link_url && !item.locked ? SmartLink : 'div';
    const wrapperProps = Wrapper === 'div' ? {} : { href: item.link_url };
    const lockOverlay = item.locked && (
        <div className="absolute inset-0 z-20 flex items-center justify-center rounded-2xl bg-gray-50/50 backdrop-blur-[1px] dark:bg-black/50">
            <span className="-rotate-6 transform rounded bg-gray-900 px-2 py-1 text-[10px] font-bold uppercase text-white shadow-lg">{item.locked_label}</span>
        </div>
    );
    const locked = item.locked ? 'cursor-not-allowed opacity-75' : '';

    if (item.style === 'tile') {
        return (
            <Wrapper {...wrapperProps} className={`relative flex h-40 flex-col justify-between rounded-2xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-dark-card ${span} ${locked}`}>
                {lockOverlay}
                <div>
                    {Icon && (
                        <div className={`mb-3 flex h-10 w-10 items-center justify-center rounded-xl shadow-inner ${theme.tile}`}>
                            <Icon className="h-5 w-5" />
                        </div>
                    )}
                    <h3 className="text-lg font-bold leading-none text-gray-800 dark:text-gray-200">{item.title}</h3>
                    {item.text && <p className="mt-1 text-[10px] font-medium text-gray-500 dark:text-gray-400">{item.text}</p>}
                </div>
                {item.locked && (
                    <div className="flex items-center gap-1 self-start rounded bg-gray-100 px-2 py-1 text-[10px] font-bold text-gray-400 dark:bg-gray-800">
                        Locked <Lock className="h-3 w-3" />
                    </div>
                )}
            </Wrapper>
        );
    }

    if (item.style === 'plain') {
        return (
            <Wrapper {...wrapperProps} className={`relative flex h-28 flex-col items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-gray-50 transition-all active:scale-[0.99] hover:bg-gray-100 dark:border-gray-700 dark:bg-dark-card/50 dark:hover:bg-dark-card ${span} ${locked}`}>
                {lockOverlay}
                <div className="flex items-center gap-3">
                    {Icon && (
                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            <Icon className="h-4 w-4" />
                        </div>
                    )}
                    <div className="text-left">
                        <h3 className="text-sm font-bold text-gray-700 dark:text-gray-200">{item.title}</h3>
                        {item.text && <p className="text-[10px] text-gray-500 dark:text-gray-400">{item.text}</p>}
                    </div>
                </div>
            </Wrapper>
        );
    }

    return (
        <Wrapper
            {...wrapperProps}
            className={`group relative overflow-hidden rounded-2xl border p-5 text-white shadow-lg transition-transform active:scale-[0.99] ${theme.card} ${span} ${locked}`}
            style={item.image_url ? { backgroundImage: `linear-gradient(rgba(0,0,0,.45), rgba(0,0,0,.45)), url("${item.image_url}")`, backgroundSize: 'cover', backgroundPosition: 'center' } : undefined}
        >
            {lockOverlay}
            <div className="absolute right-0 top-0 h-32 w-32 -translate-y-1/2 translate-x-1/2 rounded-full bg-white/10 blur-2xl" />
            <div className="relative z-10 flex items-center justify-between">
                <div>
                    <div className="mb-1 flex items-center gap-2">
                        {Icon && (
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-white/20 backdrop-blur-sm">
                                <Icon className="h-4 w-4 text-white" />
                            </div>
                        )}
                        {item.badge && <span className="text-xs font-bold uppercase tracking-wider text-white/80">{item.badge}</span>}
                    </div>
                    <h3 className="text-2xl font-black tracking-tight text-white">{item.title}</h3>
                    {item.text && <p className="mt-1 text-xs font-medium text-white/80">{item.text}</p>}
                </div>
                {item.link_url && !item.locked && (
                    <div className="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-white shadow-lg transition-transform group-hover:scale-110">
                        <ChevronRight className="h-6 w-6 text-gray-800" />
                    </div>
                )}
            </div>
        </Wrapper>
    );
}

export default function HomeCards({ section }) {
    return (
        <section className="px-5 py-6">
            <div className="mb-4 flex items-center justify-between">
                {section.title && <h3 className="text-base font-extrabold tracking-tight text-gray-900 dark:text-white">{section.title}</h3>}
                {section.badge && (
                    <span className="rounded-full bg-blue-50 px-2 py-1 text-[10px] font-bold text-blue-600 dark:bg-blue-900/30 dark:text-blue-300">{section.badge}</span>
                )}
            </div>
            <div className="grid grid-cols-2 gap-4">
                {section.items.map((item, i) => (
                    <Card key={i} item={item} />
                ))}
            </div>
        </section>
    );
}
