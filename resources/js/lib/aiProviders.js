/**
 * The AI tasks, the capability each one needs from a provider, and the choices the AI settings page offers.
 * A provider comes from the server as { id, label, capabilities: ["text"|"stt"|"tts"], key: { set, last4, verified_at } }.
 */

// The platform's own connection (the owner's key), which a user may use only when the owner allows it.
export const PLATFORM = 'platform';

export const TASKS = ['conversation', 'writing', 'stt', 'tts'];

export const TASK_CAPABILITY = {
    conversation: 'text',
    writing: 'text',
    stt: 'stt',
    tts: 'tts',
};

export const TASK_LABEL = {
    conversation: 'Conversation',
    writing: 'Writing',
    stt: 'Speech-to-text',
    tts: 'Text-to-speech',
};

export const CAPABILITY_LABEL = {
    text: 'Text',
    stt: 'Speech-to-text',
    tts: 'Text-to-speech',
};

/**
 * Whether the user has saved a key for this provider.
 */
export function hasKey(provider) {
    return provider?.key?.set === true;
}

/**
 * Whether the saved key was checked against the provider.
 */
export function isVerified(provider) {
    return Boolean(provider?.key?.verified_at);
}

/**
 * The providers that can run a task and have a key saved.
 */
export function providersFor(task, providers = []) {
    const capability = TASK_CAPABILITY[task];

    return providers.filter((provider) => hasKey(provider) && (provider.capabilities ?? []).includes(capability));
}

/**
 * The options of a task's provider select: the providers above, then the platform when the owner allows it.
 */
export function taskChoices(task, providers = [], platformAllowed = false) {
    const choices = providersFor(task, providers).map((provider) => ({ value: provider.id, label: provider.label }));

    return platformAllowed ? [...choices, { value: PLATFORM, label: 'Platform' }] : choices;
}

/**
 * The model a provider suggests for a task, or null. The platform's model is the owner's to set, so it has none here.
 */
export function suggestedModel(suggestions = {}, provider, task) {
    if (!provider || provider === PLATFORM) {
        return null;
    }

    return suggestions?.[provider]?.[task] ?? null;
}

/**
 * The voice a text-to-speech provider suggests, or null.
 */
export function suggestedVoice(suggestions = {}, provider) {
    if (!provider || provider === PLATFORM) {
        return null;
    }

    return suggestions?.[provider]?.voice ?? null;
}

/**
 * "Key ending in ••••abcd" for a saved key, "No key saved" when there is none. The key itself is never sent to the page.
 */
export function keySummary(key) {
    if (!key?.set) {
        return 'No key saved';
    }

    return key.last4 ? `Key ending in ••••${key.last4}` : 'Key saved';
}

/**
 * "Text · Speech-to-text", the capabilities of a provider in words.
 */
export function capabilitiesLabel(capabilities = []) {
    return capabilities.map((capability) => CAPABILITY_LABEL[capability] ?? capability).join(' · ');
}
