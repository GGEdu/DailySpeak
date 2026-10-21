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
 * Merge a fresh copy of the transcript: unknown messages are added, known ones take the server's fields
 * (a replay link renewed by the server, for instance), and the result is in server order.
 */
export function mergeMessages(messages, incoming) {
    const byId = new Map(messages.map((message) => [message.id, message]));

    for (const message of incoming) {
        byId.set(message.id, { ...byId.get(message.id), ...message });
    }

    return [...byId.values()].sort((a, b) => rank(a) - rank(b));
}

/**
 * The turn's messages in a fresh transcript: the user message written after `baseline` (its transcript is
 * in once the server has transcribed the recording) and, once it exists, the tutor's reply to it.
 */
export function findTurnMessages(messages, baseline) {
    const fresh = messages.filter((message) => isServerId(message) && Number(message.id) > baseline);
    const reply = fresh.findLast((message) => message.role === 'assistant') ?? null;
    const user = fresh.findLast((message) => message.role === 'user' && (!reply || Number(message.id) < Number(reply.id))) ?? null;

    return { user, reply };
}
