<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
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
