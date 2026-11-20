<?php

namespace App\Console\Commands;

use App\Services\Gemini\SecretSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * List flash models currently usable for generateContent. The key is read
 * from config (.env) and is never printed.
 */
class GeminiModels extends Command
{
    protected $signature = 'gemini:models';

    protected $description = 'List usable flash models from GET /models';

    public function handle(): int
    {
        $key = (string) config('services.gemini.key');
        if ($key === '') {
            $this->error('No Gemini key configured in .env.');

            return self::FAILURE;
        }

        $url = rtrim((string) config('services.gemini.base_url'), '/').'/models';

        try {
            $response = Http::timeout(15)
                ->withHeaders(['x-goog-api-key' => $key])
                ->get($url);
        } catch (\Throwable $e) {
            $this->error('Could not reach /models: '.SecretSanitizer::redact($e->getMessage()));

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('GET /models -> HTTP '.$response->status());

            return self::FAILURE;
        }

        $models = $response->json('models') ?? [];
        $flash = [];

        foreach ($models as $model) {
            $name = (string) ($model['name'] ?? '');
            $methods = $model['supportedGenerationMethods'] ?? [];
            if ($name === '' || ! str_contains(strtolower($name), 'flash')) {
                continue;
            }
            if (is_array($methods) && ! in_array('generateContent', $methods, true)) {
                continue;
            }
            $flash[] = str_starts_with($name, 'models/') ? substr($name, 7) : $name;
        }

        sort($flash);
        foreach ($flash as $name) {
            $this->line($name);
        }

        $this->info(count($flash).' flash model(s) usable for generateContent.');

        return self::SUCCESS;
    }
}
