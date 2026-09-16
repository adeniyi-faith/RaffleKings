export function formatNaira(amount) {
    const value = Math.round(Number(amount) || 0);
    return `₦${value.toLocaleString()}`;
}
