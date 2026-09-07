<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    // Eloquent's convention pluralizes the class's snake_case name for the
    // table ("order_status_history" -> "order_status_histories"), but the
    // migration (Milestone 3, Task 3) created the singular
    // "order_status_history" — override explicitly rather than rename the
    // table out from under an existing migration.
    protected $table = 'order_status_history';

    // UPDATED_AT = null (not $timestamps = false) keeps created_at
    // auto-populated on create while genuinely disabling the column this
    // table doesn't have — append-only.
    const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'status',
        'caused_by',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
