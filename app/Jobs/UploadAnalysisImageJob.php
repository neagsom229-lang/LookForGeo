<?php

namespace App\Jobs;

use App\Models\GeoAnalysis;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Configuration\Configuration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Follow-up Cloudinary upload. Runs after the analysis is already completed
 * so a slow or failed upload can never delay or fail the user's result.
 */
class UploadAnalysisImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $analysisId) {}

    public function handle(): void
    {
        $analysis = GeoAnalysis::find($this->analysisId);
        if (! $analysis || $analysis->status !== 'completed') {
            return;
        }
        if ($analysis->image_url && str_contains($analysis->image_url, 'cloudinary.com')) {
            return;
        }

        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        if (! $cloudName || ! $apiKey || ! $apiSecret) {
            return;
        }

        $localFile = null;
        if ($analysis->image_path && ! str_starts_with($analysis->image_path, 'http')) {
            $disk = Storage::disk('public');
            if ($disk->exists($analysis->image_path)) {
                $localFile = $disk->path($analysis->image_path);
            }
        }
        if (! $localFile) {
            return;
        }

        try {
            Configuration::instance([
                'cloud' => ['cloud_name' => $cloudName, 'api_key' => $apiKey, 'api_secret' => $apiSecret],
                'url' => ['secure' => true],
            ]);
            $result = (new UploadApi)->upload($localFile, [
                'folder' => 'tracegeo/analyses',
                'public_id' => pathinfo($analysis->image_path, PATHINFO_FILENAME),
            ]);
            $url = $result['secure_url'] ?? null;
            if (! $url) {
                return;
            }

            $analysis->update(['image_url' => $url]);
            $resultJson = is_string($analysis->result) ? json_decode($analysis->result, true) : $analysis->result;
            if (is_array($resultJson)) {
                $resultJson['image_url'] = $url;
                $analysis->update(['result' => $resultJson]);
            }
        } catch (\Throwable $e) {
            // Best effort: the completed analysis keeps its local/CDN URL.
            Log::warning('Cloudinary follow-up upload failed: '.$e->getMessage());
        }
    }
}
