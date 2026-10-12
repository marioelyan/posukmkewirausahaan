<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessUser extends Model
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_STAFF = 'staff';

    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_MANAGER,
        self::ROLE_STAFF,
    ];

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_INVITED,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_REVOKED,
    ];

    protected $table = 'business_user';

    protected $fillable = [
        'business_id',
        'user_id',
        'role',
        'status',
        'default_outlet_id',
        'invited_by_user_id',
        'invited_at',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'default_outlet_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }
}
