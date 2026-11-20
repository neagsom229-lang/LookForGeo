<?php

namespace App\Jobs;

use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Configuration\Configuration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Retention: when a user deletes an analysis, its Cloudinary asset is
 * deleted too. Runs after the response so the user is not blocked by the
 * remote API call, and is safe to retry (destroy is idempotent-ish).
 */
class DeleteCloudinaryImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $imageUrl) {}

    public function handle(): void
    {
        if (! str_contains($this->imageUrl, 'cloudinary.com')) {
            return;
        }

        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');

        if (! $cloudName || ! $apiKey || ! $apiSecret) {
            Log::warning('Cloudinary delete skipped: credentials not configured.');

            return;
        }

        $path = (string) parse_url($this->imageUrl, PHP_URL_PATH);
        if (! preg_match('#/upload/(?:v\d+/)?(.+?)(?:\.[A-Za-z0-9]+)?$#', $path, $matches)) {
            Log::warning('Cloudinary delete skipped: could not parse public id.');

            return;
        }

        try {
            Configuration::instance([
                'cloud' => [
                    'cloud_name' => $cloudName,
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                ],
                'url' => ['secure' => true],
            ]);

            (new UploadApi)->destroy($matches[1]);
        } catch (\Throwable $e) {
            // Best-effort cleanup; never fail the user's delete request.
            Log::warning('Cloudinary delete failed: '.$e->getMessage());
        }
    }
}
