// Public program costs: omit the whole item when no amount has been entered.
export function hasProgramCost(value) {
    if (value === null || value === undefined) return false;
    const text = String(value).trim();
    if (text === '' || text === '—') return false;
    const amount = Number(text);
    return Number.isNaN(amount) || (Number.isFinite(amount) && amount !== 0);
}
