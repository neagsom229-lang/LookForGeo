<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class GeoAnalysis extends Model
{
    use HasFactory;

    protected $table = 'geo_analyses';

    protected $fillable = [
        'user_id',          // ✅ Added for proper user relation
        'status',
        'stage',
        'stage_label',
        'progress',
        'image_path',
        'image_url',
        'image_hash',
        'result',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'progress' => 'integer',
    ];

    protected $appends = [
        'landmark_name',
        'city',
        'country',
        'confidence',
        'latitude',
        'longitude',
        'reasoning',
        'tags',
        'description',
        'type',
        'continent',
        'timezone',
        'confidence_level',
        'elapsed',
        'image_url_display',
    ];

    // ============================================
    // CONSTANTS
    // ============================================

    public const STATUSES = ['pending', 'processing', 'completed', 'failed'];

    public const STAGES = [
        0 => 'Input',
        1 => 'Upload',
        2 => 'Features',
        3 => 'Reasoning',
        4 => 'Locate',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Repeat-upload cache lookup. Scoped by user_id: identical bytes from a
     * different user never share a result (no privacy cross-talk).
     */
    public static function findCachedForUser(string $hash, int $userId): ?self
    {
        return self::where('user_id', $userId)
            ->where('image_hash', $hash)
            ->where('status', 'completed')
            ->whereNotNull('result')
            ->latest('finished_at')
            ->first();
    }

    // ============================================
    // ACCESSORS (from result JSON)
    // ============================================

    public function getResultAttribute($value)
    {
        if (is_null($value)) {
            return [];
        }
        if (is_string($value)) {
            return json_decode($value, true) ?? [];
        }

        return $value;
    }

    public function getLandmarkNameAttribute()
    {
        return $this->result['landmark_name'] ?? 'Unknown Location';
    }

    public function getCityAttribute()
    {
        return $this->result['city'] ?? null;
    }

    public function getCountryAttribute()
    {
        return $this->result['country'] ?? null;
    }

    public function getRegionAttribute()
    {
        return $this->result['region'] ?? null;
    }

    public function getConfidenceAttribute()
    {
        return $this->result['confidence'] ?? 0;
    }

    public function getLatitudeAttribute()
    {
        return $this->result['latitude'] ?? null;
    }

    public function getLongitudeAttribute()
    {
        return $this->result['longitude'] ?? null;
    }

    public function getReasoningAttribute()
    {
        return $this->result['reasoning'] ?? null;
    }

    public function getTagsAttribute()
    {
        return $this->result['tags'] ?? [];
    }

    public function getDescriptionAttribute()
    {
        return $this->result['description'] ?? null;
    }

    public function getTypeAttribute()
    {
        return $this->result['type'] ?? 'unknown';
    }

    public function getContinentAttribute()
    {
        return $this->result['continent'] ?? 'Unknown';
    }

    public function getTimezoneAttribute()
    {
        return $this->result['timezone'] ?? 'Unknown';
    }

    public function getConfidenceLevelAttribute()
    {
        return $this->result['confidence_level'] ?? 'None';
    }

    public function getElapsedAttribute()
    {
        return $this->elapsedSeconds();
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    public function markStage(int $stage, int $progress): void
    {
        $this->update([
            'status' => 'processing',
            'stage' => $stage,
            'stage_label' => self::STAGES[$stage] ?? 'Processing',
            'progress' => $progress,
            'error' => null,
        ]);
    }

    public function elapsedSeconds(): float
    {
        if (! $this->started_at) {
            return 0.0;
        }
        $end = $this->finished_at ?? now();

        return round($this->started_at->diffInSeconds($end), 1);
    }

    public function markAsCompleted(array $result): void
    {
        $this->update([
            'status' => 'completed',
            'stage' => 4,
            'stage_label' => self::STAGES[4],
            'progress' => 100,
            'result' => $result,
            'image_url' => $result['image_url'] ?? $this->image_url, // ✅ must be present
            'finished_at' => now(),
            'error' => null,
        ]);
        $this->clearCache();
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
            'finished_at' => now(),
        ]);
        $this->clearCache();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Get the image URL for display (handles local/cloudinary)
     */
    public function getImageUrlAttribute(): ?string
    {
        // Read the raw column: accessing $this->image_url here would recurse
        // back into this accessor and lose stored Cloudinary URLs.
        $stored = $this->getRawOriginal('image_url');
        if (! empty($stored)) {
            return (string) $stored;
        }

        if (! empty($this->image_path)) {
            if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
                return $this->image_path;
            }

            return '/storage/'.$this->image_path;
        }

        return null;
    }

    // ============================================
    // CACHE CLEARING
    // ============================================

    private function clearCache(): void
    {
        if ($this->user_id) {
            Cache::forget('dashboard_'.$this->user_id);
        }

        // Popular-landmarks cache is shared globally; any write can change it
        Cache::forget('dashboard_popular_global');
    }

    // ============================================
    // MODEL EVENTS
    // ============================================

    protected static function booted()
    {
        static::saved(function ($analysis) {
            $analysis->clearCache();
        });

        static::deleted(function ($analysis) {
            $analysis->clearCache();
        });
    }

    // ============================================
    // ARRAY / JSON SERIALIZATION
    // ============================================

    // Appended attributes ($appends) are included automatically on
    // serialization; no manual toArray() override needed.

    public function getImageUrlDisplayAttribute()
    {
        // 1. FIRST: Check the result JSON for a Cloudinary URL
        // (result is always an array via getResultAttribute())
        $result = $this->result;
        if (! empty($result['image_url']) && filter_var($result['image_url'], FILTER_VALIDATE_URL)) {
            return $result['image_url'];
        }

        // 2. If the model has a direct image_url column (Cloudinary or full asset URL)
        if (! empty($this->image_url) && filter_var($this->image_url, FILTER_VALIDATE_URL)) {
            return $this->image_url;
        }

        // 3. Fallback to image_path
        if (! empty($this->image_path)) {
            if (filter_var($this->image_path, FILTER_VALIDATE_URL)) {
                return $this->image_path;
            }

            return asset('storage/'.$this->image_path);
        }

        // 4. If everything fails, return null
        return null;
    }
}
