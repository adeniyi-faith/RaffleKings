// The bulk/custom-quantity input from raffle-details.php's "Golden Box"
// bulk-mode card — dark/indigo themed, distinct from the light-theme
// TextInput used elsewhere, so it keeps its own skin here rather than
// forcing one input to cover both.
export default function NumberStepper({ value, onChange, min = 1, max, className = '', ...props }) {
    return (
        <input
            type="number"
            value={value}
            min={min}
            max={max}
            onChange={(e) => onChange(Number(e.target.value))}
            onClick={(e) => e.stopPropagation()}
            className={[
                'w-full rounded-lg border border-indigo-700 bg-indigo-900/50 px-3 py-2.5 font-mono text-lg font-bold text-white placeholder-indigo-500/50 outline-none focus:border-yellow-400',
                className,
            ]
                .filter(Boolean)
                .join(' ')}
            {...props}
        />
    );
}
