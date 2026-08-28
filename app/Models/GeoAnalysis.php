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
        if (!$this->started_at) {
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
        if (!empty($this->image_url)) {
            return $this->image_url;
        }

        if (!empty($this->image_path)) {
            if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
                return $this->image_path;
            }
            return '/storage/' . $this->image_path;
        }

        return null;
    }

    // ============================================
    // CACHE CLEARING
    // ============================================

    private function clearCache(): void
    {
        if ($this->user_id) {
            Cache::forget('dashboard_' . $this->user_id);
        }
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

    public function toArray()
    {
        $array = parent::toArray();

        // Ensure computed attributes are included
        $array['landmark_name'] = $this->landmark_name;
        $array['city'] = $this->city;
        $array['country'] = $this->country;
        $array['confidence'] = $this->confidence;
        $array['latitude'] = $this->latitude;
        $array['longitude'] = $this->longitude;
        $array['reasoning'] = $this->reasoning;
        $array['tags'] = $this->tags;
        $array['description'] = $this->description;
        $array['type'] = $this->type;
        $array['continent'] = $this->continent;
        $array['timezone'] = $this->timezone;
        $array['confidence_level'] = $this->confidence_level;
        $array['elapsed'] = $this->elapsed;
        $array['image_url_display'] = $this->image_url_display;

        // Optionally remove the raw `result` field to avoid duplication
        // unset($array['result']);

        return $array;
    }

    public function getImageUrlDisplayAttribute()
    {
        return $this->image_url_display;
    }
}