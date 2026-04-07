/**
 * Shared helpers for scripted teaching steps (speech, spotlight, interact)
 * used in Classroom playback and Studio playback preview.
 */

export function normalizeActions(raw) {
    if (!Array.isArray(raw)) {
        return [];
    }
    return raw
        .filter((a) => a && typeof a === 'object')
        .map((a, i) => {
            const type = typeof a.type === 'string' ? a.type : 'cue';
            const target =
                a.target && typeof a.target === 'object'
                    ? {
                          kind: typeof a.target.kind === 'string' ? a.target.kind : 'element',
                          elementId: typeof a.target.elementId === 'string' ? a.target.elementId : '',
                          rect: a.target.rect && typeof a.target.rect === 'object' ? a.target.rect : undefined,
                      }
                    : { kind: 'element', elementId: '' };
            return {
                id: typeof a.id === 'string' ? a.id : `act-${i}`,
                type,
                label: typeof a.label === 'string' ? a.label : '',
                text: typeof a.text === 'string' ? a.text : '',
                personaId: typeof a.personaId === 'string' ? a.personaId : '',
                narrationUrl: typeof a.narrationUrl === 'string' ? a.narrationUrl : '',
                ttsVoice: typeof a.ttsVoice === 'string' ? a.ttsVoice : '',
                ttsSpeed: typeof a.ttsSpeed === 'number' && !Number.isNaN(a.ttsSpeed) ? a.ttsSpeed : 1,
                mode: typeof a.mode === 'string' ? a.mode : 'pause',
                prompt: typeof a.prompt === 'string' ? a.prompt : '',
                durationMs: typeof a.durationMs === 'number' && !Number.isNaN(a.durationMs) ? a.durationMs : 4000,
                target,
            };
        });
}

function normalizeSpotlightRect(rect) {
    if (!rect || typeof rect !== 'object') {
        return null;
    }
    const x = typeof rect.x === 'number' ? rect.x : null;
    const y = typeof rect.y === 'number' ? rect.y : null;
    const width =
        typeof rect.width === 'number' ? rect.width : typeof rect.w === 'number' ? rect.w : null;
    const height =
        typeof rect.height === 'number' ? rect.height : typeof rect.h === 'number' ? rect.h : null;
    if (x === null || y === null || width === null || height === null) {
        return null;
    }
    return { x, y, width, height };
}

export function spotlightFromAction(a) {
    if (!a || a.type !== 'spotlight') {
        return { elementId: undefined, rect: undefined };
    }
    if (a.target?.kind === 'region' && a.target.rect) {
        const r = normalizeSpotlightRect(a.target.rect);
        return { elementId: undefined, rect: r || undefined };
    }
    const el = typeof a.target?.elementId === 'string' ? a.target.elementId : '';
    return { elementId: el || undefined, rect: undefined };
}

/**
 * @param {object | null | undefined} currentAction
 * @param {object[]} actions
 * @param {number} safeActionIndex
 */
export function getEffectiveSpotlight(currentAction, actions, safeActionIndex) {
    const a = currentAction;
    if (a?.type === 'spotlight') {
        return spotlightFromAction(a);
    }
    if (
        (a?.type === 'speech' || a?.type === 'narration') &&
        actions[safeActionIndex + 1]?.type === 'spotlight'
    ) {
        return spotlightFromAction(actions[safeActionIndex + 1]);
    }
    return { elementId: undefined, rect: undefined };
}
