<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Foundation (Build 9)
    |--------------------------------------------------------------------------
    |
    | Topbar staff chat + lead health analysis, backed by OpenRouter. Read
    | these via config('ai.*') — never env() in app code (config is cached in
    | deployed envs, so env() returns null there).
    |
    | NOTE: `enabled` here is the *config-level* kill switch. There is also a
    | tenant-level toggle in the database (Setting::get('ai_enabled')). Both
    | must be true for any AI call to run — see App\Services\AIService::isEnabled().
    |
    */

    'enabled' => env('AI_ENABLED', true),

    // Enable only after approving private passport processing and staging review.
    'passport_prefill_enabled' => env('PASSPORT_PREFILL_ENABLED', false),
    'passport_prefill_processing_approved' => env('PASSPORT_PREFILL_PROCESSING_APPROVED', false),

    'provider' => 'openrouter',
    'api_key'  => env('OPENROUTER_API_KEY'),
    'base_url' => 'https://openrouter.ai/api/v1',

    'default_model' => env('AI_DEFAULT_MODEL', 'google/gemini-2.5-flash'),

    'timeout_seconds' => 30,
    'max_tokens'      => 1500,
    'temperature'     => 0.7,

    'chat_history_limit'  => 20,
    'analysis_cache_hours' => 24,

    'system_prompt' => 'You are the EP CRM Assistant, helping staff at EP (an Auckland-based consultancy spanning sales, education advising, immigration consulting, and English language training). You assist with drafting messages, summarising leads/cases/students, answering questions about CRM data, and providing operational help. You speak in clear, professional, friendly New Zealand English. You never invent facts about leads — if you do not know, you say so. You never give legal, financial, or immigration advice — you draft communications but always remind staff to review before sending.',

];
