/** Decimal strings stay exact, including values above Number's safe integer range. */
export function formatPrice(value: string, currency: string): string {
    const [whole, fraction = ''] = value.split('.');
    const locale = currency === 'INR' ? 'en-IN' : 'en-GB';
    const symbols: Record<string, string> = {
        INR: '₹',
        USD: '$',
        EUR: '€',
        GBP: '£',
    };
    const amount = new Intl.NumberFormat(locale).format(BigInt(whole));
    return `${symbols[currency] ?? currency + ' '}${amount}.${fraction.padEnd(2, '0')}`;
}
