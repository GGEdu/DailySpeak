import { TASK_LABEL } from './aiProviders.js';

const NUMBER = new Intl.NumberFormat('en-GB');

/**
 * A count with thousands separators. A value the server did not measure (null) is a dash, not a zero.
 */
export function formatCount(value) {
    return typeof value === 'number' && Number.isFinite(value) ? NUMBER.format(value) : '—';
}

/**
 * "October 2026" from "2026-10". Anything else is shown as it came.
 */
export function monthLabel(month) {
    const match = /^(\d{4})-(\d{2})$/.exec(month ?? '');

    if (!match) {
        return month ?? '';
    }

    return new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1)).toLocaleDateString('en-GB', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

/**
 * "No limit", or "200 calls a month".
 */
export function capLabel(cap) {
    if (cap === null || cap === undefined) {
        return 'No limit';
    }

    return `${formatCount(cap)} ${cap === 1 ? 'call' : 'calls'} a month`;
}

/**
 * The share of the monthly cap already used, from 0 to 100. Null when there is no cap to measure against.
 */
export function capUsage(used, cap) {
    if (!(cap > 0)) {
        return null;
    }

    return Math.min(100, Math.round(((used ?? 0) / cap) * 100));
}

/**
 * Who paid for a call: the user's own key, or the platform.
 */
export function paidByLabel(paidBy) {
    return paidBy === 'platform' ? 'Platform' : 'Your key';
}

export function taskName(task) {
    return TASK_LABEL[task] ?? task;
}

/**
 * "1,200 in · 350 out", or a dash when neither count is known.
 */
export function tokensLabel(input, output) {
    if (input == null && output == null) {
        return '—';
    }

    return `${formatCount(input)} in · ${formatCount(output)} out`;
}

export function resultLabel(ok) {
    return ok ? 'Done' : 'Failed';
}
