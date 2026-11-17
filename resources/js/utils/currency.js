const SCALE = 9;

/**
 * Normalize a numeric/decimal value into an integer-scaled BigInt (10^9) without floating point.
 */
function toScaledBigInt(value) {
    if (value === null || value === undefined || value === '') {
        return 0n;
    }

    const str = String(value).trim();
    const match = str.match(/^([+-])?(\d+)(?:\.(\d+))?$/);
    if (!match) {
        return 0n;
    }

    const sign = match[1] === '-' ? -1n : 1n;
    const whole = match[2];
    const frac = (match[3] || '').padEnd(SCALE, '0').slice(0, SCALE);

    return sign * BigInt(`${whole}${frac}`);
}

/**
 * Format a 9-decimal BigInt back into a fixed-scale decimal string.
 */
function fromScaledBigInt(scaled) {
    const negative = scaled < 0n;
    const abs = negative ? -scaled : scaled;
    const str = abs.toString().padStart(SCALE + 1, '0');
    const whole = str.slice(0, -SCALE);
    const frac = str.slice(-SCALE);

    return `${negative ? '-' : ''}${whole}.${frac}`;
}

/**
 * Format a decimal string as USD with readable precision (keeps significant decimals, min 2).
 */
export function formatUsd(value, minDecimals = 2) {
    if (value === null || value === undefined || value === '') {
        return '$0.00';
    }

    const str = String(value).trim();
    const match = str.match(/^([+-])?(\d+)(?:\.(\d+))?$/);
    if (!match) {
        return `$${str}`;
    }

    const sign = match[1] === '-' ? '-' : '';
    const whole = match[2];
    let frac = match[3] || '';

    // Trim trailing zeros while preserving at least minDecimals digits
    frac = frac.replace(/0+$/, '');
    if (frac.length < minDecimals) {
        frac = frac.padEnd(minDecimals, '0');
    }

    const formattedWhole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `${sign}$${formattedWhole}.${frac}`;
}

/**
 * Compare two decimal strings without floating-point rounding.
 * Returns -1 if a < b, 0 if a === b, 1 if a > b.
 */
export function compareDecimalStrings(a, b) {
    const ba = toScaledBigInt(a);
    const bb = toScaledBigInt(b);

    if (ba < bb) return -1;
    if (ba > bb) return 1;
    return 0;
}

/**
 * Subtract two decimal strings (a - b) with exact 9-decimal precision.
 */
export function subtractDecimalStrings(a, b) {
    return fromScaledBigInt(toScaledBigInt(a) - toScaledBigInt(b));
}

/**
 * Compute absolute difference |a - b| as a 9-decimal string.
 */
export function absDiffDecimalStrings(a, b) {
    const diff = toScaledBigInt(a) - toScaledBigInt(b);
    return fromScaledBigInt(diff < 0n ? -diff : diff);
}

