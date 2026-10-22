/**
 * Pure helpers for the debate transcript. Messages keep the id the server gave them; the placeholder
 * for a turn still being transcribed has no server id, and always sorts last.
 */

const isServerId = (message) => /^\d+$/.test(String(message.id));
const rank = (message) => (isServerId(message) ? Number(message.id) : Number.MAX_SAFE_INTEGER);

/**
 * The highest server id in the transcript, or 0 when there is none.
 */
export function latestId(messages) {
    return messages.reduce((latest, message) => (isServerId(message) ? Math.max(latest, Number(message.id)) : latest), 0);
}

/**
 * Replace the message with the same id, or append it.
 */
export function upsertMessage(messages, message) {
    const index = messages.findIndex((existing) => existing.id === message.id);

    if (index === -1) {
        return [...messages, message];
    }

    return messages.map((existing, position) => (position === index ? { ...existing, ...message } : existing));
}

/**
 * Append the message unless one with the same id is already there.
 */
export function addMissing(messages, message) {
    return messages.some((existing) => existing.id === message.id) ? messages : [...messages, message];
}

export function withoutPending(messages) {
    return messages.some((message) => message.pending) ? messages.filter((message) => !message.pending) : messages;
}

/**
 * The corrections of a learner message, merged with a fresh copy. A copy that has none yet (null: the review is still
 * running) keeps what is shown. A dismissal is never undone by a copy: once the learner said "I didn't say that", the
 * correction stays hidden, even if the copy was fetched before the dismissal reached the server.
 */
export function mergeCorrections(local, incoming) {
    if (incoming == null) {
        return local ?? null;
    }

    if (local == null) {
        return incoming;
    }

    const dismissed = new Set(local.filter((correction) => correction.dismissed).map((correction) => correction.index));

    return incoming.map((correction) => (dismissed.has(correction.index) ? { ...correction, dismissed: true } : correction));
}

/**
 * One message merged with a fresh copy of it: the server's fields win, except the corrections, which keep the rules above.
 */
export function mergeMessage(local, incoming) {
    if (!local) {
        return { ...incoming };
    }

    const merged = { ...local, ...incoming };

    if ('corrections' in local || 'corrections' in incoming) {
        merged.corrections = mergeCorrections(local.corrections, incoming.corrections);
    }

    return merged;
}

/**
 * Merge a fresh copy of the transcript: unknown messages are added, known ones take the server's fields
 * (a replay link renewed by the server, for instance), and the result is in server order.
 */
export function mergeMessages(messages, incoming) {
    const byId = new Map(messages.map((message) => [message.id, message]));

    for (const message of incoming) {
        byId.set(message.id, mergeMessage(byId.get(message.id), message));
    }

    return [...byId.values()].sort((a, b) => rank(a) - rank(b));
}

/**
 * The transcript with one correction of a message marked as dismissed (or back). Other messages are kept as they are.
 */
export function setCorrectionDismissed(messages, messageId, index, dismissed) {
    if (!messages.some((message) => message.id === messageId && message.corrections?.some((item) => item.index === index))) {
        return messages;
    }

    return messages.map((message) =>
        message.id === messageId
            ? { ...message, corrections: message.corrections.map((item) => (item.index === index ? { ...item, dismissed } : item)) }
            : message,
    );
}

/**
 * The corrections to show under a message: the ones the learner has not dismissed.
 */
export function visibleCorrections(message) {
    return (message.corrections ?? []).filter((correction) => !correction.dismissed);
}

/**
 * The turn's messages in a fresh transcript: its user message, which the server marks with the voice turn it belongs
 * to, and the tutor's reply to that message (`reply_to_id`). Ids say nothing about the turn: a reply to an earlier turn
 * that arrives late has an id after this turn started, and it is still not this turn's.
 */
export function findTurnMessages(messages, turnId) {
    if (turnId == null) {
        return { user: null, reply: null };
    }

    const user = messages.find((message) => message.role === 'user' && message.turn === turnId) ?? null;
    const reply = user ? (messages.find((message) => message.role === 'assistant' && message.reply_to_id === user.id) ?? null) : null;

    return { user, reply };
}
