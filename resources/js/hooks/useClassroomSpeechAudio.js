import { useEffect, useRef, useState } from 'react';

function durationMsForSpeechFallback(action) {
    if (!action) {
        return 2200;
    }
    const t = (action.text || action.label || '').trim();
    const n = t.length;
    return Math.min(24000, Math.max(1600, n * 45 + 800));
}

function browserSpeechSupported() {
    return typeof window !== 'undefined' && window.speechSynthesis && window.SpeechSynthesisUtterance;
}

/**
 * Map transport × per-step speed to SpeechSynthesisUtterance.rate (typical ~0.5–2).
 */
function utteranceRate(transportSpeed, ttsSpeed) {
    const base = transportSpeed * (typeof ttsSpeed === 'number' && !Number.isNaN(ttsSpeed) ? ttsSpeed : 1);
    return Math.min(2, Math.max(0.5, base));
}

/**
 * Speech / narration step: narrationUrl → server TTS (if available) → Web Speech API → timed fallback.
 * Pause stops audio / cancels synthesis and in-flight TTS.
 */
export function useClassroomSpeechAudio({
    sceneId,
    actionIndex,
    isPlaying,
    currentAction,
    transportSpeed,
    onAdvance,
    serverTtsAvailable = true,
}) {
    const audioRef = useRef(null);
    const [speechPhase, setSpeechPhase] = useState('idle');
    const loadedKeyRef = useRef('');
    const abortRef = useRef(null);
    const fallbackTimerRef = useRef(null);
    const browserCleanupRef = useRef(null);
    const onAdvanceRef = useRef(onAdvance);
    const actionRef = useRef(currentAction);
    const isPlayingRef = useRef(isPlaying);
    onAdvanceRef.current = onAdvance;
    actionRef.current = currentAction;
    isPlayingRef.current = isPlaying;

    const isSpeech =
        currentAction &&
        (currentAction.type === 'speech' || currentAction.type === 'narration');

    const speechKey =
        isSpeech && sceneId
            ? `${sceneId}:${actionIndex}:${currentAction.id}:${currentAction.type}`
            : '';

    useEffect(() => {
        const clearNetwork = () => {
            if (abortRef.current) {
                abortRef.current.abort();
                abortRef.current = null;
            }
        };
        const clearFallback = () => {
            if (fallbackTimerRef.current) {
                clearTimeout(fallbackTimerRef.current);
                fallbackTimerRef.current = null;
            }
        };
        const clearBrowserSpeech = () => {
            if (browserCleanupRef.current) {
                browserCleanupRef.current();
                browserCleanupRef.current = null;
            } else if (browserSpeechSupported()) {
                window.speechSynthesis.cancel();
            }
        };
        const clearAll = () => {
            clearNetwork();
            clearFallback();
            clearBrowserSpeech();
        };

        const audio = audioRef.current;
        if (!audio) {
            return undefined;
        }

        if (!speechKey) {
            clearAll();
            audio.onended = null;
            audio.pause();
            audio.removeAttribute('src');
            audio.load();
            loadedKeyRef.current = '';
            setSpeechPhase('idle');
            return undefined;
        }

        const act = actionRef.current;
        const rate = Math.min(
            4,
            Math.max(0.25, transportSpeed * (typeof act?.ttsSpeed === 'number' ? act.ttsSpeed : 1)),
        );

        if (!isPlaying) {
            clearAll();
            audio.pause();
            setSpeechPhase('idle');
            return undefined;
        }

        const text = (act?.text || act?.label || '').trim();
        if (!text) {
            clearAll();
            queueMicrotask(() => onAdvanceRef.current());
            return undefined;
        }

        const startFallbackTimer = () => {
            clearFallback();
            clearBrowserSpeech();
            const base = durationMsForSpeechFallback(act);
            const ms = Math.max(200, base / transportSpeed);
            fallbackTimerRef.current = window.setTimeout(() => {
                setSpeechPhase('idle');
                onAdvanceRef.current();
            }, ms);
            setSpeechPhase('fallback');
        };

        const startBrowserSpeech = () => {
            clearFallback();
            clearBrowserSpeech();
            if (!browserSpeechSupported()) {
                startFallbackTimer();
                return;
            }
            const raw = (act?.text || act?.label || '').trim();
            const text = raw.length > 9000 ? `${raw.slice(0, 8997)}…` : raw;
            const u = new SpeechSynthesisUtterance(text);
            u.rate = utteranceRate(transportSpeed, act?.ttsSpeed);
            u.onend = () => {
                browserCleanupRef.current = null;
                if (!isPlayingRef.current) {
                    return;
                }
                setSpeechPhase('idle');
                onAdvanceRef.current();
            };
            u.onerror = () => {
                browserCleanupRef.current = null;
                if (!isPlayingRef.current) {
                    return;
                }
                startFallbackTimer();
            };
            browserCleanupRef.current = () => {
                window.speechSynthesis.cancel();
            };
            setSpeechPhase('loading');
            try {
                window.speechSynthesis.cancel();
                window.speechSynthesis.speak(u);
                setSpeechPhase('playing');
            } catch {
                browserCleanupRef.current = null;
                startFallbackTimer();
            }
        };

        const playLoaded = async () => {
            if (!isPlayingRef.current) {
                return;
            }
            audio.onended = () => {
                setSpeechPhase('idle');
                onAdvanceRef.current();
            };
            audio.playbackRate = rate;
            try {
                await audio.play();
                if (isPlayingRef.current) {
                    setSpeechPhase('playing');
                }
            } catch {
                startBrowserSpeech();
            }
        };

        if (loadedKeyRef.current === speechKey && audio.src) {
            clearAll();
            playLoaded();
            return () => {
                clearAll();
                audio.onended = null;
            };
        }

        clearAll();
        audio.onended = null;
        audio.pause();
        loadedKeyRef.current = '';
        setSpeechPhase('loading');

        const url = typeof act?.narrationUrl === 'string' ? act.narrationUrl.trim() : '';
        if (url !== '') {
            audio.src = url;
            loadedKeyRef.current = speechKey;
            playLoaded();
            return () => {
                clearAll();
                audio.onended = null;
            };
        }

        if (!serverTtsAvailable) {
            startBrowserSpeech();
            return () => {
                clearAll();
                audio.onended = null;
            };
        }

        const ac = new AbortController();
        abortRef.current = ac;
        const voice =
            typeof act?.ttsVoice === 'string' && act.ttsVoice.trim() !== '' ? act.ttsVoice.trim() : undefined;
        const speed =
            typeof act?.ttsSpeed === 'number' && !Number.isNaN(act.ttsSpeed)
                ? Math.min(4, Math.max(0.25, act.ttsSpeed))
                : 1;

        window.axios
            .post(
                '/api/generate/tts',
                {
                    text,
                    voice,
                    speed,
                    requiresApiKey: false,
                },
                { signal: ac.signal },
            )
            .then((res) => {
                if (ac.signal.aborted) {
                    return;
                }
                if (!isPlayingRef.current) {
                    setSpeechPhase('idle');
                    return;
                }
                if (res.data?.success && res.data?.url) {
                    audio.src = res.data.url;
                    loadedKeyRef.current = speechKey;
                    return playLoaded();
                }
                startBrowserSpeech();
            })
            .catch(() => {
                if (ac.signal.aborted) {
                    return;
                }
                if (!isPlayingRef.current) {
                    return;
                }
                startBrowserSpeech();
            });

        return () => {
            clearAll();
            audio.onended = null;
        };
    }, [speechKey, isPlaying, transportSpeed, serverTtsAvailable]);

    return { audioRef, speechPhase, isSpeech };
}
