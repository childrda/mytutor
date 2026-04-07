<?php

/**
 * Tutor application integration settings (API keys never exposed to clients).
 */
return [
    'app_version' => env('TUTOR_APP_VERSION', '1.0.0'),

    'disk' => env('TUTOR_STORAGE_DISK', 'local'),

    'published_media_path' => 'published-lessons',

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

    'default_chat' => [
        'base_url' => env('TUTOR_DEFAULT_LLM_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('TUTOR_DEFAULT_LLM_MODEL', 'gpt-4o-mini'),
        'api_key' => env('TUTOR_DEFAULT_LLM_API_KEY') ?: env('OPENAI_API_KEY'),
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
    */
    'image_generation' => [
        'base_url' => rtrim((string) env('TUTOR_IMAGE_BASE_URL', env('TUTOR_DEFAULT_LLM_BASE_URL', 'https://api.openai.com/v1')), '/'),
        'api_key' => env('TUTOR_IMAGE_API_KEY') ?: env('TUTOR_DEFAULT_LLM_API_KEY') ?: env('OPENAI_API_KEY'),
        'model' => env('TUTOR_OPENAI_IMAGE_MODEL', 'dall-e-3'),
        'default_size' => env('TUTOR_IMAGE_DEFAULT_SIZE', '1024x1024'),
        'max_prompt_chars' => max(100, min(16_000, (int) env('TUTOR_IMAGE_MAX_PROMPT_CHARS', 4000))),
        'timeout' => max(15.0, (float) env('TUTOR_IMAGE_REQUEST_TIMEOUT', 120)),
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Video generation (Phase 4.4): MiniMax async T2V — POST /api/generate/video, GET poll
    |--------------------------------------------------------------------------
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
    | POST /api/chat, /api/parse-pdf, lesson generation (Phase 6): 0 = off
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
        'publish' => [
            'per_minute' => max(0, (int) env('TUTOR_PUBLISH_THROTTLE_PER_MINUTE', 30)),
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
