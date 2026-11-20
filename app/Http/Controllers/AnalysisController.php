<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAnalysisImageRequest;
use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Services\Gemini\SecretSanitizer;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AnalysisController extends Controller
{
    /**
     * The only failure text ever shown to the user through the API.
     * Details stay in storage/logs (sanitized).
     */
    public const FAILED_USER_MESSAGE = 'The AI service did not respond. Please retry.';

    /**
     * Show the analysis page
     */
    public function index()
    {
        $googleMapsEmbedKey = config('services.google_maps.embed_key');
        // Ensure it's a string
        if (! is_string($googleMapsEmbedKey)) {
            $googleMapsEmbedKey = '';
        }

        return view('analysis', ['googleMapsEmbedKey' => $googleMapsEmbedKey]);
    }

    /**
     * Show history page (filtered by logged-in user)
     */
    public function history()
    {
        $analyses = GeoAnalysis::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('history', ['analyses' => $analyses]);
    }

    /**
     * ✅ ASYNC UPLOAD – Stores file, creates record, dispatches job
     */
    public function store(StoreAnalysisImageRequest $request)
    {
        if (! auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to upload images.',
            ], 401);
        }

        try {
            $file = $request->file('image');
            $filename = time().'_'.$file->getClientOriginalName();

            // Repeat-upload cache: identical bytes from the SAME user return
            // the existing completed analysis instantly (zero Gemini cost).
            $imageHash = hash_file('sha256', $file->getRealPath());
            $cached = GeoAnalysis::findCachedForUser($imageHash, (int) auth()->id());
            if ($cached) {
                Log::info('Repeat upload served from cache', [
                    'user_id' => auth()->id(),
                    'analysis_id' => $cached->id,
                ]);

                return response()->json([
                    'success' => true,
                    'id' => $cached->id,
                    'cached' => true,
                    'message' => 'Analysis already completed.',
                    'data' => [
                        'id' => $cached->id,
                        'status' => 'completed',
                        'progress' => 100,
                        'image_url' => $cached->image_url,
                    ],
                ]);
            }

            Log::info('🚀 Starting async upload', [
                'user_id' => auth()->id(),
                'filename' => $filename,
                'size' => $file->getSize(),
            ]);

            // Local-disk friendly storage: use Cloudinary in production, or when
            // it is configured, and the local public disk otherwise. This keeps
            // the whole flow working offline on a dev machine.
            if (! $this->usingCloudinary()) {
                $storedPath = $file->store('analyses', 'public');
                $path = $storedPath;
                $imageUrl = Storage::disk('public')->url($storedPath);
                $fullPath = Storage::disk('public')->path($storedPath);

                Log::info('Stored upload on local public disk', [
                    'path' => $storedPath,
                    'url' => $imageUrl,
                ]);
            } else {
                // Upload to Cloudinary FIRST — the worker container has no local disk
                try {
                    $cloudinary = new Cloudinary([
                        'cloud' => [
                            'cloud_name' => config('services.cloudinary.cloud_name'),
                            'api_key' => config('services.cloudinary.api_key'),
                            'api_secret' => config('services.cloudinary.api_secret'),
                        ],
                        'url' => ['secure' => true],
                    ]);

                    $uploadResult = $cloudinary->uploadApi()->upload(
                        $file->getRealPath(),
                        [
                            'folder' => 'tracegeo/analyses',
                            'resource_type' => 'image',
                        ]
                    );

                    $imageUrl = $uploadResult['secure_url'];
                    $path = $imageUrl;
                    $fullPath = $imageUrl;  // keep variable name so downstream code doesn't break

                    Log::info('Uploaded to Cloudinary', [
                        'public_id' => $uploadResult['public_id'] ?? null,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Cloudinary upload failed: '.$e->getMessage());

                    return response()->json([
                        'success' => false,
                        'message' => 'Image storage unavailable. The upload service is not configured correctly (missing CLOUDINARY_* values).',
                    ], 503);
                }
            }
            // Create the record -- 'pending' until a worker picks the job up
            // (the job flips it to 'processing' when it starts).
            $analysis = GeoAnalysis::create([
                'user_id' => auth()->id(),
                'status' => 'pending',
                'stage' => 0,
                'stage_label' => 'Uploaded',
                'progress' => 10,
                'image_path' => $path,
                'image_url' => $imageUrl,
                'image_hash' => $imageHash,
                'started_at' => now(),
            ]);

            Log::info('✅ GeoAnalysis created with ID: '.$analysis->id);

            // ============================================================
            // ✅ DISPATCH JOB WITH TRY-CATCH
            // ============================================================
            try {
                AnalyzeImageJob::dispatch($analysis->id, $fullPath, $filename);
                Log::info('✅ Job dispatched successfully for analysis ID: '.$analysis->id);
            } catch (\Exception $jobException) {
                Log::error('❌ Job dispatch FAILED: '.$jobException->getMessage());
                Log::error('Stack trace: '.$jobException->getTraceAsString());

                // Update the analysis to failed since the job couldn't be dispatched
                $analysis->update([
                    'status' => 'failed',
                    'error' => SecretSanitizer::redact('Job dispatch failed: '.$jobException->getMessage()),
                    'finished_at' => now(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => self::FAILED_USER_MESSAGE,
                ], 500);
            }

            return response()->json([
                'success' => true,
                'id' => $analysis->id,
                'message' => 'Analysis started successfully',
                'data' => [
                    'id' => $analysis->id,
                    'status' => 'pending',
                    'progress' => 10,
                    'image_url' => $imageUrl,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Analysis store error: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => self::FAILED_USER_MESSAGE,
            ], 500);
        }
    }

    /**
     * ✅ POLLING ENDPOINT – Scoped to the authenticated user (IDOR-safe).
     */
    public function status($id)
    {
        try {
            $analysis = GeoAnalysis::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (! $analysis) {
                return response()->json([
                    'success' => false,
                    'message' => 'Analysis not found',
                ], 404);
            }

            $elapsed = 0;
            if ($analysis->started_at) {
                $end = $analysis->finished_at ?? now();
                $elapsed = round($analysis->started_at->diffInSeconds($end), 1);
            }

            $response = [
                'success' => true,
                'status' => $analysis->status,
                'stage' => $analysis->stage ?? 0,
                'stage_label' => $analysis->stage_label ?? 'Processing',
                'progress' => $analysis->progress ?? 0,
                'elapsed' => $elapsed,
                'image_url' => $analysis->image_url,
            ];

            if ($analysis->status === 'completed' && $analysis->result) {
                $result = $analysis->result;
                if (is_string($result)) {
                    $result = json_decode($result, true);
                }
                $response['result'] = $result;
                $response['data'] = [
                    'id' => $analysis->id,
                    'landmark_name' => $result['landmark_name'] ?? 'Unknown',
                    'city' => $result['city'] ?? null,
                    'country' => $result['country'] ?? null,
                    'latitude' => $result['latitude'] ?? null,
                    'longitude' => $result['longitude'] ?? null,
                    'confidence' => $result['confidence'] ?? 0,
                    'description' => $result['description'] ?? null,
                    'image_url' => $analysis->image_url,
                    'result_image_url' => $result['image_url'] ?? null, // ✅ add this line
                    'tags' => $result['tags'] ?? [],
                    'reasoning' => $result['reasoning'] ?? null,
                ];
            }

            if ($analysis->status === 'failed') {
                // User-facing failure text is always generic; the sanitized
                // details live in the log (and in the DB error column).
                $response['error'] = self::FAILED_USER_MESSAGE;
            }

            // Queued too long without the worker picking it up?
            // "pending", or still sitting at stage 0 of "processing".
            $queuedSince = $analysis->started_at ?? $analysis->created_at;
            $looksQueued = $analysis->status === 'pending'
                || ($analysis->status === 'processing'
                    && (int) ($analysis->stage ?? 0) === 0
                    && (int) ($analysis->progress ?? 0) <= 10);

            if ($looksQueued && $queuedSince) {
                // Carbon 3 diffInSeconds() is signed (negative for past dates).
                $queuedSeconds = (int) abs(now()->diffInSeconds($queuedSince));
                if ($queuedSeconds >= 20) {
                    $response['queued_seconds'] = $queuedSeconds;

                    if (app()->environment(['local', 'testing'])) {
                        $response['hint'] = 'queue worker not running? run composer dev';
                    }
                }
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('❌ Status fetch error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Could not load analysis status. Please retry.',
            ], 500);
        }
    }

    /**
     * 🗑️ Delete an analysis (ownership check)
     */
    public function destroy($id)
    {
        if (! auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to delete analysis.',
            ], 401);
        }

        try {
            $analysis = GeoAnalysis::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (! $analysis) {
                return response()->json([
                    'success' => false,
                    'message' => 'Analysis not found or does not belong to you.',
                ], 404);
            }

            // Delete local file if exists
            if ($analysis->image_path && Storage::disk('public_uploads')->exists($analysis->image_path)) {
                Storage::disk('public_uploads')->delete($analysis->image_path);
            }

            $analysis->delete();

            return response()->json([
                'success' => true,
                'message' => 'Analysis deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Delete error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Could not delete analysis. Please retry.',
            ], 500);
        }
    }

    /**
     * 🔄 Retry a failed analysis (ownership check)
     */
    public function retry($id)
    {
        if (! auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to retry analysis.',
            ], 401);
        }

        try {
            $analysis = GeoAnalysis::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (! $analysis) {
                return response()->json([
                    'success' => false,
                    'message' => 'Analysis not found or does not belong to you.',
                ], 404);
            }

            if ($analysis->status !== 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only failed analyses can be retried.',
                ], 400);
            }

            // Reset and dispatch again
            $analysis->update([
                'status' => 'processing',
                'stage' => 0,
                'stage_label' => 'Retrying',
                'progress' => 10,
                'error' => null,
                'finished_at' => null,
                'started_at' => now(),
            ]);

            AnalyzeImageJob::dispatch($analysis->id);

            return response()->json([
                'success' => true,
                'id' => $analysis->id,
                'message' => 'Analysis retry started',
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Retry error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Could not retry analysis. Please retry.',
            ], 500);
        }
    }

    /**
     * 🖼️ Fetch image from URL
     */
    public function fetchImage(Request $request)
    {
        if (! auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to fetch images.',
            ], 401);
        }

        $request->validate(['url' => 'required|url']);

        try {
            // ⚠️ SSRF guard: resolve the host and block private/reserved IPs (fail closed)
            $url = $request->input('url');
            if ($this->isPrivateHost($url)) {
                return response()->json([
                    'success' => false,
                    'message' => 'That URL points to a private/internal address and cannot be fetched.',
                ], 400);
            }

            // Wikimedia + most CDNs require a real User-Agent, or they return 403
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'TraceGeo/1.0 (https://lookforgeo.onrender.com; chheangsamnang.wu@gmail.com)',
                    'Accept' => 'image/*,*/*;q=0.8',
                    'Referer' => 'https://lookforgeo.onrender.com/',
                ])
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);

            // ⚠️ SSRF guard: re-check the effective URL after redirects (DNS rebinding protection)
            $effectiveUri = (string) ($response->effectiveUri() ?? '');
            if ($effectiveUri !== '' && $this->isPrivateHost($effectiveUri)) {
                return response()->json([
                    'success' => false,
                    'message' => 'That URL points to a private/internal address and cannot be fetched.',
                ], 400);
            }

            if (! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not fetch image from URL: HTTP '.$response->status(),
                ], 400);
            }

            $content = $response->body();
            $contentType = $response->header('Content-Type');

            if (! str_starts_with($contentType, 'image/')) {
                return response()->json([
                    'success' => false,
                    'message' => 'URL does not point to an image. Content-Type: '.$contentType,
                ], 400);
            }

            $extension = match ($contentType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg'
            };

            $filename = 'url_'.time().'.'.$extension;

            return response()->json([
                'success' => true,
                'image_data' => base64_encode($content),
                'mime_type' => $contentType,
                'filename' => $filename,
            ]);

        } catch (\Exception $e) {
            Log::error('URL fetch error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Could not fetch that image. Please retry.',
            ], 500);
        }
    }

    /**
     * Storage decision for uploads: Cloudinary only when it is configured
     * (and we're not explicitly in local dev); otherwise the local public disk.
     */
    private function usingCloudinary(): bool
    {
        if (app()->environment('local')) {
            return false;
        }

        return ! empty(config('services.cloudinary.cloud_name'))
            && ! empty(config('services.cloudinary.api_key'))
            && ! empty(config('services.cloudinary.api_secret'));
    }

    /**
     * SSRF guard: resolve the host and fail closed when it points to a
     * private/reserved IP (IPv4 or IPv6) or cannot be resolved.
     */
    private function isPrivateHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (empty($host)) {
            return true;
        }

        $ip = gethostbyname($host);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Get analysis results by ID (scoped to the authenticated user — IDOR-safe).
     */
    public function getResults($id)
    {
        try {
            $analysis = GeoAnalysis::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (! $analysis) {
                return response()->json([
                    'success' => false,
                    'message' => 'Analysis not found',
                ], 404);
            }

            $result = is_string($analysis->result) ? json_decode($analysis->result, true) : $analysis->result;

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $analysis->id,
                    'landmark_name' => $result['landmark_name'] ?? 'Unknown',
                    'local_name' => $result['local_name'] ?? null,
                    'city' => $result['city'] ?? null,
                    'country' => $result['country'] ?? null,
                    'latitude' => $result['latitude'] ?? null,
                    'longitude' => $result['longitude'] ?? null,
                    'confidence' => $result['confidence'] ?? 0,
                    'description' => $result['description'] ?? null,
                    'type' => $result['type'] ?? null,
                    'image_url' => $analysis->image_url,
                    'tags' => $result['tags'] ?? [],
                    'reasoning' => $result['reasoning'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Analysis not found',
            ], 404);
        }
    }

    /**
     * Get session analysis data
     */
    public function getSessionData(Request $request)
    {
        $result = $request->session()->get('analysis_result');

        return $result ? response()->json(['success' => true, 'data' => $result])
                       : response()->json(['success' => false, 'message' => 'No data found'], 404);
    }
}
