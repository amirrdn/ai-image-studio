<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Generation extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    public const SIZES = [
        '1024x1024',
        '1536x1024',
        '1024x1536',
    ];

    public const QUALITIES = [
        'low',
        'medium',
        'high',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'pixelforge_id',
        'idempotency_key',
        'prompt',
        'size',
        'quality',
        'reference_urls',
        'status',
        'pricing_snapshot',
        'estimated_provider_cost_min',
        'estimated_provider_cost_max',
        'estimated_user_cost_min',
        'estimated_user_cost_max',
        'actual_provider_cost',
        'actual_user_cost',
        'token_usage',
        'image_url',
        'image_expires_at',
        'error_code',
        'error_message',
    ];

    /**
     * Bootstrap the model and ensure idempotency_key defaults to a UUID.
     */
    protected static function booted(): void
    {
        static::creating(function (Generation $generation): void {
            if (empty($generation->idempotency_key)) {
                $generation->idempotency_key = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_urls' => 'array',
            'pricing_snapshot' => 'array',
            'token_usage' => 'array',
            'estimated_provider_cost_min' => 'decimal:9',
            'estimated_provider_cost_max' => 'decimal:9',
            'estimated_user_cost_min' => 'decimal:9',
            'estimated_user_cost_max' => 'decimal:9',
            'actual_provider_cost' => 'decimal:9',
            'actual_user_cost' => 'decimal:9',
            'image_expires_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns this generation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
