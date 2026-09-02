<?php

namespace App\Models;

use App\Policies\MeetingPolicy;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['title', 'description', 'password', 'scheduled_at', 'waiting_room_enabled', 'allow_guests'])]
#[Hidden(['password'])]
#[Appends(['has_password'])]
#[RouteKey('meeting_uuid')]
#[UsePolicy(MeetingPolicy::class)]
class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_LIVE = 'live';

    public const STATUS_ENDED = 'ended';

    public const STATUS_CANCELLED = 'cancelled';

    protected static function booted(): void
    {
        static::creating(function (Meeting $meeting) {
            $meeting->meeting_uuid ??= (string) Str::uuid();
            $meeting->meeting_code ??= self::generateUniqueCode();
            $meeting->status ??= self::STATUS_SCHEDULED;
        });
    }

    /**
     * Generate a short, human-friendly join code (e.g. ABC-123-XYZ), retrying on collision.
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = collect(range(1, 3))
                ->map(fn () => Str::upper(Str::random(3)))
                ->implode('-');
        } while (self::where('meeting_code', $code)->exists());

        return $code;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'waiting_room_enabled' => 'boolean',
            'allow_guests' => 'boolean',
            'is_locked' => 'boolean',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected function hasPassword(): Attribute
    {
        return Attribute::make(
            get: fn () => ! is_null($this->password),
        )->shouldCache();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /**
     * @return HasMany<MeetingParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }
}
