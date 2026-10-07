const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

const UNITS = [
    ['year', 60 * 60 * 24 * 365],
    ['month', 60 * 60 * 24 * 30],
    ['day', 60 * 60 * 24],
    ['hour', 60 * 60],
    ['minute', 60],
];

/**
 * "3 hours ago", "yesterday"…
 */
export function timeAgo(date) {
    const seconds = (new Date(date).getTime() - Date.now()) / 1000;

    for (const [unit, size] of UNITS) {
        if (Math.abs(seconds) >= size) {
            return relative.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}

/**
 * Section heading for a day: "Today", "Yesterday" or "Monday 5 October".
 */
export function dayLabel(date) {
    const day = new Date(date);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (day.toDateString() === today.toDateString()) {
        return 'Today';
    }

    if (day.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return day.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
}

/**
 * mm:ss for recording timers.
 */
export function clock(seconds) {
    const whole = Math.floor(seconds);

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}
