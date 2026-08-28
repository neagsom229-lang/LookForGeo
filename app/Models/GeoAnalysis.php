<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeoAnalysis extends Model
{
    use HasFactory;

    protected $table = 'geo_analyses';

    protected $fillable = [
        'status',
        'stage',
        'stage_label',
        'progress',
        'image_path',  // ✅ Added this
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
    ];

    public const STAGES = [
        0 => 'Input',
        1 => 'Upload',
        2 => 'Features',
        3 => 'Reasoning',
        4 => 'Locate',
    ];

    public function markStage(int $stage, int $progress): void
    {
        $this->update([
            'status' => 'processing',
            'stage' => $stage,
            'stage_label' => self::STAGES[$stage] ?? 'Processing',
            'progress' => $progress,
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
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
            'finished_at' => now(),
        ]);
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
}