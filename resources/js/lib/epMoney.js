// Integer micro-USD remains a string across HTTP; never round it through Number.
export function money(value) {
    if (value === null || value === undefined || !/^\d+$/.test(String(value))) return 'Not recorded';
    const units = BigInt(value), whole = units / 1000000n;
    const fraction = (units % 1000000n).toString().padStart(6, '0').replace(/0+$/, '');
    return `US$${whole.toLocaleString('en-US')}.${fraction ? fraction.padEnd(2, '0') : '00'}`;
}

export function runMoney(value, currency) {
    if (value === null || value === undefined) return 'Not recorded';
    if (!currency) return 'Currency not recorded';
    return currency === 'USD' ? money(value) : `Unsupported currency (${currency})`;
}
