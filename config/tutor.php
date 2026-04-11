<?php

/**
 * Tutor application integration settings (API keys never exposed to clients).
 */
$llmCompletionLimitRaw = env('TUTOR_LLM_COMPLETION_LIMIT_PARAM');
$llmCompletionLimitParam = ($llmCompletionLimitRaw === null || trim((string) $llmCompletionLimitRaw) === '')
    ? 'max_completion_tokens'
    : (strtolower(trim((string) $llmCompletionLimitRaw)) === 'max_tokens' ? 'max_tokens' : 'max_completion_tokens');

return [
    'app_version' => env('TUTOR_APP_VERSION', '1.0.0'),

    'disk' => env('TUTOR_STORAGE_DISK', 'local'),

    'published_media_path' => 'published-lessons',

    /*
    |--------------------------------------------------------------------------
    | Model list JSON (Phase 4 — Settings catalog API)
    |--------------------------------------------------------------------------
    | Default: config/models.json. Override for tests or alternate deploy layout.
    */
    'models_json_path' => env('TUTOR_MODELS_JSON_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Provider env maps → public API id (metadata only via /api/integrations)
    |--------------------------------------------------------------------------
    */
    'llm' => [
        'OPENAI' => 'openai',
        'ANTHROPIC' => 'anthropic',
        'GOOGLE' => 'google',
        'DEEPSEEK' => 'deepseek',
        'QWEN' => 'qwen',
        'KIMI' => 'kimi',
        'MINIMAX' => 'minimax',
        'GLM' => 'glm',
        'SILICONFLOW' => 'siliconflow',
        'DOUBAO' => 'doubao',
        'GROK' => 'grok',
    ],

    'tts' => [
        'TTS_OPENAI' => 'openai-tts',
        'TTS_AZURE' => 'azure-tts',
        'TTS_GLM' => 'glm-tts',
        'TTS_QWEN' => 'qwen-tts',
        'TTS_DOUBAO' => 'doubao-tts',
        'TTS_ELEVENLABS' => 'elevenlabs-tts',
        'TTS_MINIMAX' => 'minimax-tts',
    ],

    'asr' => [
        'ASR_OPENAI' => 'openai-whisper',
        'ASR_QWEN' => 'qwen-asr',
    ],

    'pdf' => [
        'PDF_UNPDF' => 'unpdf',
        'PDF_MINERU' => 'mineru',
    ],

    'image' => [
        'IMAGE_SEEDREAM' => 'seedream',
        'IMAGE_QWEN_IMAGE' => 'qwen-image',
        'IMAGE_NANO_BANANA' => 'nano-banana',
        'IMAGE_MINIMAX' => 'minimax-image',
        'IMAGE_GROK' => 'grok-image',
    ],

    'video' => [
        'VIDEO_SEEDANCE' => 'seedance',
        'VIDEO_KLING' => 'kling',
        'VIDEO_VEO' => 'veo',
        'VIDEO_SORA' => 'sora',
        'VIDEO_MINIMAX' => 'minimax-video',
        'VIDEO_GROK' => 'grok-video',
    ],

    'web_search' => [
        'TAVILY' => 'tavily',
    ],

    /*
    |--------------------------------------------------------------------------
    | Active model registry keys (Phase 4 + B4)
    |--------------------------------------------------------------------------
    | Values must match a model id under the same capability in config/models.json
    | (e.g. TUTOR_ACTIVE_LLM=openai → llm.openai). Leave empty to use legacy paths or a value saved in Settings
    | (tutor_registry_actives table, global for all users).
    |
    | Resolution order: non-empty env-driven config here wins; otherwise the database value from Settings;
    | otherwise null (legacy executor paths only).
    |
    | After changing .env: php artisan config:clear; restart queue workers so jobs pick up env changes.
    | DB-only changes from Settings apply on the next request (no config:clear).
    */
    'active' => [
        'llm' => env('TUTOR_ACTIVE_LLM'),
        'image' => env('TUTOR_ACTIVE_IMAGE'),
        'tts' => env('TUTOR_ACTIVE_TTS'),
        'asr' => env('TUTOR_ACTIVE_ASR'),
        'web_search' => env('TUTOR_ACTIVE_WEB_SEARCH'),
        'pdf' => env('TUTOR_ACTIVE_PDF'),
        'video' => env('TUTOR_ACTIVE_VIDEO'),
    ],

    'default_chat' => [
        'base_url' => env('TUTOR_DEFAULT_LLM_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('TUTOR_DEFAULT_LLM_MODEL', 'gpt-4o-mini'),
        'api_key' => env('TUTOR_DEFAULT_LLM_API_KEY') ?: env('OPENAI_API_KEY'),
    ],

    /*
    | When true, persist LLM request/response rows to llm_exchange_logs (TUTOR_LOG_LLM in .env).
    | Lives at tutor.log_llm (not under lesson_generation) so every LLM caller uses the same switch.
    */
    'log_llm' => filter_var(env('TUTOR_LOG_LLM', 'false'), FILTER_VALIDATE_BOOL),
    'log_llm_max_payload_bytes' => max(10_000, (int) env('TUTOR_LOG_LLM_MAX_PAYLOAD_BYTES', 2_000_000)),
    /*
    | When set, persist image API exchanges to llm_exchange_logs (endpoint /v1/images/generations).
    | When unset, follows TUTOR_LOG_LLM. Never stores base64 image bytes — only redacted summaries.
    */
    'log_image_generation' => env('TUTOR_LOG_IMAGE_GENERATION') !== null
        ? filter_var(env('TUTOR_LOG_IMAGE_GENERATION'), FILTER_VALIDATE_BOOL)
        : filter_var(env('TUTOR_LOG_LLM', 'false'), FILTER_VALIDATE_BOOL),

    /*
    | POST /chat/completions token ceiling. When TUTOR_LLM_COMPLETION_LIMIT_PARAM is unset, we use
    | max_completion_tokens (OpenAI’s current API for GPT-5+ etc.). Set to max_tokens for Ollama/LM Studio.
    */
    'llm_completion_limit_param' => $llmCompletionLimitParam,

    /*
    |--------------------------------------------------------------------------
    | Lesson generation pipeline (Phase 7.3): sequential LLM calls in one job
    | Empty *model envs fall back to default_chat.model
    |--------------------------------------------------------------------------
    */
    'lesson_generation' => [
        'roles_model' => env('TUTOR_LESSON_GEN_ROLES_MODEL'),
        'outline_model' => env('TUTOR_LESSON_GEN_OUTLINE_MODEL'),
        'content_model' => env('TUTOR_LESSON_GEN_CONTENT_MODEL'),
        'roles_max_tokens' => max(256, (int) env('TUTOR_LESSON_GEN_ROLES_MAX_TOKENS', 2048)),
        'outline_max_tokens' => max(512, (int) env('TUTOR_LESSON_GEN_OUTLINE_MAX_TOKENS', 4096)),
        /*
        | Outline scene count: enforced in prompts plus up to two regeneration attempts if the
        | model returns too few items. Completion token budget scales with outline_max_scenes so
        | long outlines are not cut off mid-JSON (you can still raise TUTOR_LESSON_GEN_OUTLINE_MAX_TOKENS).
        */
        'outline_min_scenes' => max(1, (int) env('TUTOR_LESSON_GEN_OUTLINE_MIN_SCENES', 1)),
        'outline_max_scenes' => max(1, (int) env('TUTOR_LESSON_GEN_OUTLINE_MAX_SCENES', 20)),
        'content_max_tokens' => max(1024, (int) env('TUTOR_LESSON_GEN_CONTENT_MAX_TOKENS', 8192)),
        /*
        | When true (default), each outline scene gets its own LLM completion with
        | content_max_tokens_per_scene — similar to OpenMAIC’s parallel per-scene generation.
        | When false, one batched call returns all scenes (uses content_max_tokens only).
        */
        'content_per_scene' => filter_var(env('TUTOR_LESSON_GEN_CONTENT_PER_SCENE', 'true'), FILTER_VALIDATE_BOOL),
        'content_max_tokens_per_scene' => max(1024, (int) env('TUTOR_LESSON_GEN_CONTENT_PER_SCENE_MAX_TOKENS', 6144)),
        'content_scene_max_concurrent' => max(1, min(12, (int) env('TUTOR_LESSON_GEN_CONTENT_CONCURRENT', 4))),
        /*
        | After slide content exists, one LLM call per scene generates teaching actions
        | (voiceover-style speech + spotlights)—mirrors OpenMAIC’s separate actions step.
        */
        'content_actions_llm' => filter_var(env('TUTOR_LESSON_GEN_ACTIONS_LLM', 'true'), FILTER_VALIDATE_BOOL),
        'actions_model' => env('TUTOR_LESSON_GEN_ACTIONS_MODEL'),
        'actions_max_tokens_per_scene' => max(512, (int) env('TUTOR_LESSON_GEN_ACTIONS_MAX_TOKENS', 3072)),
        'actions_scene_max_concurrent' => max(1, min(12, (int) env('TUTOR_LESSON_GEN_ACTIONS_CONCURRENT', 4))),
        'placeholder_narration_actions' => filter_var(env('TUTOR_LESSON_GEN_PLACEHOLDER_ACTIONS', 'true'), FILTER_VALIDATE_BOOL),
        'stream_outline' => filter_var(env('TUTOR_LESSON_GEN_STREAM_OUTLINE', 'true'), FILTER_VALIDATE_BOOL),
        // When outline_min_scenes > 1, the pipeline forces non-streaming outline regardless of stream_outline.
        'content_use_pdf_page_images' => filter_var(env('TUTOR_LESSON_GEN_CONTENT_VISION', 'true'), FILTER_VALIDATE_BOOL),
        'max_pdf_page_images' => max(0, min(4, (int) env('TUTOR_LESSON_GEN_MAX_PDF_IMAGES', 4))),
        'max_pdf_image_data_url_chars' => max(10_000, (int) env('TUTOR_LESSON_GEN_MAX_PDF_IMAGE_CHARS', 700_000)),
        /*
        | When false, SlideVisualFallback does nothing (no curated Commons diagrams, no Wikipedia
        | image search). Use with “Image generation” on so slides rely on AI-resolved gen_img only.
        */
        'slide_visual_fallback' => filter_var(env('TUTOR_SLIDE_VISUAL_FALLBACK', 'true'), FILTER_VALIDATE_BOOL),
        /*
        | When slide_visual_fallback is true: if no keyword diagram matches, optionally query
        | en.wikipedia.org for a Commons thumbnail. Set false to skip that HTTP call only.
        */
        'slide_visual_fallback_wikimedia' => filter_var(env('TUTOR_SLIDE_FALLBACK_WIKIMEDIA', 'true'), FILTER_VALIDATE_BOOL),
        /*
        | When true, download curated upload.wikimedia.org fallback diagrams server-side and store
        | under the public disk so the UI loads /storage/... instead of hotlinking (avoids browser
        | privacy tools / referrer blocks that show Network “failed” for cross-origin images).
        */
        'mirror_wikimedia_fallback_images' => filter_var(env('TUTOR_MIRROR_WIKIMEDIA_FALLBACK', 'true'), FILTER_VALIDATE_BOOL),
        'ai_slide_images_max' => max(1, min(32, (int) env('TUTOR_LESSON_GEN_AI_SLIDE_IMAGES_MAX', 12))),
    ],

    /*
    |--------------------------------------------------------------------------
    | POST /api/chat streaming (Phase 3.5): limits, PHP max time, Guzzle timeouts
    |--------------------------------------------------------------------------
    */
    'chat_stream' => [
        'max_execution_seconds' => max(30, (int) env('TUTOR_CHAT_MAX_EXECUTION', 120)),
        'guzzle_timeout' => max(10.0, (float) env('TUTOR_CHAT_GUZZLE_TIMEOUT', 115)),
        'guzzle_connect_timeout' => max(1.0, (float) env('TUTOR_CHAT_CONNECT_TIMEOUT', 15)),
        'max_messages' => max(1, (int) env('TUTOR_CHAT_MAX_MESSAGES', 200)),
        'max_total_content_bytes' => max(4096, (int) env('TUTOR_CHAT_MAX_CONTENT_BYTES', 500_000)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Studio scene generation (Phase 4.5): POST /api/generate/scene-* , agent-profiles
    |--------------------------------------------------------------------------
    */
    'studio_generation' => [
        'temperature' => (float) env('TUTOR_STUDIO_GEN_TEMPERATURE', 0.35),
        'max_tokens' => max(512, (int) env('TUTOR_STUDIO_GEN_MAX_TOKENS', 4096)),
        'max_instruction_chars' => max(100, (int) env('TUTOR_STUDIO_GEN_MAX_INSTRUCTION_CHARS', 8000)),
        'sse_chunk_chars' => max(8, (int) env('TUTOR_STUDIO_GEN_SSE_CHUNK_CHARS', 120)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-agent routing (Phase 3.2): id → display name + persona line
    |--------------------------------------------------------------------------
    */
    'agents' => [
        'tutor' => [
            'name' => 'Tutor',
            'persona' => 'You are the primary tutor. Explain clearly, stay aligned with the lesson context, and keep answers concise unless the learner asks for depth.',
        ],
        'socratic' => [
            'name' => 'Socratic guide',
            'persona' => 'You ask short, targeted questions that help the learner reason. Do not lecture; guide with hints and checks for understanding.',
        ],
        'lecturer' => [
            'name' => 'Lecturer',
            'persona' => 'You give structured mini-lectures: define terms, give a short example, then one takeaway. Stay within the lesson context.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat tools (Phase 3.3): allowlisted handlers, sent to the LLM when non-empty
    | Tool `name` must match OpenAI function naming: ^[a-zA-Z0-9_-]{1,64}$ (no dots).
    |--------------------------------------------------------------------------
    */
    'chat_tools' => [
        'max_argument_bytes' => (int) env('TUTOR_CHAT_TOOL_MAX_ARG_BYTES', 8192),
        'handler_timeout_seconds' => (int) env('TUTOR_CHAT_TOOL_HANDLER_TIMEOUT', 5),
        'tools' => [
            [
                'name' => 'whiteboard_append',
                'description' => 'Record a whiteboard append operation (demo). Use when the learner or you add a short note or stroke label to the shared board.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'label' => [
                            'type' => 'string',
                            'description' => 'Optional short label describing what was appended.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'handler' => 'noop',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image generation (Phase 4.2): OpenAI-compatible /v1/images/generations
    |--------------------------------------------------------------------------
    | After changing image code or image .env vars: `php artisan config:clear` (and config:cache if you
    | use it). Restart queue workers (`php artisan queue:restart` or Horizon terminate) so jobs use it.
    */
    'image_generation' => [
        'base_url' => rtrim((string) env(
            'TUTOR_IMAGE_BASE_URL',
            env(
                'TUTOR_NANO_BANANA_IMAGE_BASE_URL',
                env('TUTOR_DEFAULT_LLM_BASE_URL', 'https://api.openai.com/v1'),
            ),
        ), '/'),
        'api_key' => env('TUTOR_IMAGE_API_KEY')
            ?: env('TUTOR_IMAGE_AI_KEY')
            ?: env('IMAGE_NANO_BANANA_API_KEY')
            ?: env('tutor_image_api_key')
            ?: env('tutor_image_ai_key')
            ?: env('TUTOR_DEFAULT_LLM_API_KEY')
            ?: env('OPENAI_API_KEY'),
        'model' => env('TUTOR_OPENAI_IMAGE_MODEL', 'dall-e-3'),
        'default_size' => env('TUTOR_IMAGE_DEFAULT_SIZE', '1792x1024'),
        'max_prompt_chars' => max(100, min(16_000, (int) env('TUTOR_IMAGE_MAX_PROMPT_CHARS', 4000))),
        'timeout' => max(15.0, (float) env('TUTOR_IMAGE_REQUEST_TIMEOUT', 120)),
        /*
        | Transient HTTP failures (429, 502, 503, 504) and connection errors: total attempts per image.
        */
        'http_max_attempts' => max(1, min(5, (int) env('TUTOR_IMAGE_HTTP_MAX_ATTEMPTS', 2))),
    ],

    /*
    |--------------------------------------------------------------------------
    | TTS generation (Phase 4.3): OpenAI-compatible /v1/audio/speech
    |--------------------------------------------------------------------------
    */
    'tts_generation' => [
        'base_url' => rtrim((string) env('TUTOR_TTS_BASE_URL', env('TUTOR_DEFAULT_LLM_BASE_URL', 'https://api.openai.com/v1')), '/'),
        'api_key' => env('TUTOR_TTS_API_KEY') ?: env('TTS_OPENAI_API_KEY') ?: env('TUTOR_DEFAULT_LLM_API_KEY') ?: env('OPENAI_API_KEY'),
        'model' => env('TUTOR_OPENAI_TTS_MODEL', 'tts-1'),
        'voice' => env('TUTOR_OPENAI_TTS_VOICE', 'alloy'),
        'format' => env('TUTOR_OPENAI_TTS_FORMAT', 'mp3'),
        'max_input_chars' => max(1, min(4096, (int) env('TUTOR_TTS_MAX_INPUT_CHARS', 4096))),
        'timeout' => max(15.0, (float) env('TUTOR_TTS_REQUEST_TIMEOUT', 120)),
        'http_max_attempts' => max(1, min(5, (int) env('TUTOR_TTS_HTTP_MAX_ATTEMPTS', 2))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video generation (Phase 4.4 + B6): MiniMax async T2V — POST /api/generate/video, GET poll
    |--------------------------------------------------------------------------
    | When `tutor.active.video` is `minimax-video`, the submit POST uses models.json + executor;
    | poll/query, file retrieve, and binary download still use the base URL and key below.
    */
    'video_generation' => [
        'base_url' => rtrim((string) env(
            'TUTOR_VIDEO_MINIMAX_BASE_URL',
            env('VIDEO_MINIMAX_BASE_URL', 'https://api.minimax.io'),
        ), '/'),
        'api_key' => env('TUTOR_VIDEO_API_KEY') ?: env('VIDEO_MINIMAX_API_KEY'),
        'model' => env('TUTOR_VIDEO_MINIMAX_MODEL', 'MiniMax-Hailuo-2.3'),
        'max_prompt_chars' => max(100, min(2000, (int) env('TUTOR_VIDEO_MAX_PROMPT_CHARS', 2000))),
        'submit_timeout' => max(15.0, (float) env('TUTOR_VIDEO_SUBMIT_TIMEOUT', 60)),
        'query_timeout' => max(10.0, (float) env('TUTOR_VIDEO_QUERY_TIMEOUT', 30)),
        'retrieve_timeout' => max(15.0, (float) env('TUTOR_VIDEO_RETRIEVE_TIMEOUT', 60)),
        'download_timeout' => max(30.0, (float) env('TUTOR_VIDEO_DOWNLOAD_TIMEOUT', 300)),
        'poll_interval_seconds' => max(0.0, (float) env('TUTOR_VIDEO_POLL_INTERVAL_SECONDS', 5)),
        'poll_interval_ms' => max(500, (int) env('TUTOR_VIDEO_POLL_INTERVAL_MS', 5000)),
        'poll_max_seconds' => max(60.0, (float) env('TUTOR_VIDEO_POLL_MAX_SECONDS', 600)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated media (Phase 4.1): image / TTS / video outputs from /api/generate/*
    |--------------------------------------------------------------------------
    */
    'media_generation' => [
        'disk' => env('TUTOR_MEDIA_GENERATION_DISK', 'public'),
        'path_prefix' => trim(env('TUTOR_MEDIA_GENERATION_PATH_PREFIX', 'generated'), '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | POST /api/generate/* rate limit (Phase 4.6): 0 disables throttle middleware
    |--------------------------------------------------------------------------
    */
    'generate' => [
        'throttle_per_minute' => max(0, (int) env('TUTOR_GENERATE_THROTTLE_PER_MINUTE', 60)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route throttles: POST /api/chat, /api/parse-pdf, lesson generation, poll; 0 = off
    |--------------------------------------------------------------------------
    */
    'throttle' => [
        'chat' => [
            'per_minute' => max(0, (int) env('TUTOR_CHAT_THROTTLE_PER_MINUTE', 60)),
        ],
        'parse_pdf' => [
            'per_minute' => max(0, (int) env('TUTOR_PARSE_PDF_THROTTLE_PER_MINUTE', 30)),
        ],
        'generate_lesson' => [
            'per_minute' => max(0, (int) env('TUTOR_GENERATE_LESSON_THROTTLE_PER_MINUTE', 20)),
        ],
        // Status polling must not share the POST bucket (polls every 1–3s would exhaust 20/min).
        'generate_lesson_poll' => [
            'per_minute' => max(0, (int) env('TUTOR_GENERATE_LESSON_POLL_THROTTLE_PER_MINUTE', 300)),
        ],
        'publish' => [
            'per_minute' => max(0, (int) env('TUTOR_PUBLISH_THROTTLE_PER_MINUTE', 30)),
        ],
        'web_search' => [
            'per_minute' => max(0, (int) env('TUTOR_WEB_SEARCH_THROTTLE_PER_MINUTE', 60)),
        ],
        'quiz_grade' => [
            'per_minute' => max(0, (int) env('TUTOR_QUIZ_GRADE_THROTTLE_PER_MINUTE', 60)),
        ],
        'transcription' => [
            'per_minute' => max(0, (int) env('TUTOR_TRANSCRIPTION_THROTTLE_PER_MINUTE', 30)),
        ],
        'project_tutor_chat' => [
            'per_minute' => max(0, (int) env('TUTOR_PROJECT_TUTOR_CHAT_THROTTLE_PER_MINUTE', 60)),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF text extraction (Phase 5) — POST /api/parse-pdf
    |--------------------------------------------------------------------------
    */
    'pdf_parse' => [
        'max_file_bytes' => max(100_000, (int) env('TUTOR_PDF_MAX_FILE_BYTES', 15_000_000)),
        'max_pages' => max(1, (int) env('TUTOR_PDF_MAX_PAGES', 200)),
        'max_output_chars' => max(5_000, (int) env('TUTOR_PDF_MAX_OUTPUT_CHARS', 500_000)),
        'max_execution_seconds' => max(15, (int) env('TUTOR_PDF_MAX_EXECUTION', 60)),
        'page_images_enabled' => filter_var(env('TUTOR_PDF_PAGE_IMAGES', 'true'), FILTER_VALIDATE_BOOL),
        'page_images_max_pages' => max(1, min(5, (int) env('TUTOR_PDF_PAGE_IMAGES_MAX', 3))),
        'page_images_scale_to_px' => max(400, min(1200, (int) env('TUTOR_PDF_PAGE_IMAGES_SCALE', 900))),
        'page_images_dpi' => max(72, min(200, (int) env('TUTOR_PDF_PAGE_IMAGES_DPI', 110))),
        'page_images_max_bytes' => max(50_000, (int) env('TUTOR_PDF_PAGE_IMAGES_MAX_BYTES', 450_000)),
        'page_images_timeout_seconds' => max(15, (int) env('TUTOR_PDF_PAGE_IMAGES_TIMEOUT', 45)),
        'pdftoppm_binary' => env('TUTOR_PDF_TOPPM_PATH', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lesson HTML ZIP export (Phase 5) — GET tutor-api/lessons/{id}/export/html-zip
    |--------------------------------------------------------------------------
    */
    'lesson_export' => [
        'max_scenes' => max(1, (int) env('TUTOR_EXPORT_MAX_SCENES', 500)),
        'max_scene_json_chars' => max(1_000, (int) env('TUTOR_EXPORT_MAX_SCENE_JSON_CHARS', 200_000)),
        'max_uncompressed_bytes' => max(50_000, (int) env('TUTOR_EXPORT_MAX_UNCOMPRESSED_BYTES', 5_000_000)),
    ],
];
