<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores payment records and their gateway responses.
 *
 * Money is held as integer minor units in `amount_minor` (kobo, cents).
 * `amount` is an exact decimal-string view of it ("5000.00"): reading and
 * writing it never goes through floating point.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'amount_minor',
        'currency',
        'reference',
        'provider',
        'status',
        'payment_method',
        'gateway_response',
    ];

    protected $casts = [
        'gateway_response' => 'array',
        'amount_minor' => 'integer',
    ];

    /**
     * The amount as an exact two-decimal string, backed by `amount_minor`.
     *
     * @return Attribute<string|null, string|int|float>
     */
    protected function amount(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): ?string => isset($attributes['amount_minor'])
                ? Money::fromMinor((int) $attributes['amount_minor'])
                : null,
            set: fn (string|int|float $value): array => ['amount_minor' => Money::toMinor($value)],
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
