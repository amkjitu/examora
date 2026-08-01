<?php

namespace App\Services\Ai;

use App\Services\Ai\Providers\MockProvider;

/**
 * Every generator embeds its retrieved, database-sourced context as a delimited
 * JSON block inside the prompt. Real models read it as ordinary grounding data;
 * the mock provider parses it to produce realistic offline output. One prompt,
 * both paths.
 */
class PromptContext
{
    public static function block(array $context): string
    {
        return MockProvider::CONTEXT_OPEN."\n"
            .json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n".MockProvider::CONTEXT_CLOSE;
    }
}
