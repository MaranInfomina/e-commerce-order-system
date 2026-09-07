<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'product_sku',
        'product_image_path',
        'unit_price_cents',
        'quantity',
        'line_total_cents',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'quantity' => 'integer',
            'line_total_cents' => 'integer',
        ];
    }

    /**
     * Same key-not-URL derivation as Product::getImageUrlAttribute(), applied
     * to the snapshotted path instead of the live one — this can 404 if the
     * product's image was later replaced or deleted, and that is deliberate:
     * only the path is snapshotted, not the bytes.
     */
    public function getImageUrlAttribute(): ?string
    {
        if ($this->product_image_path === null) {
            return null;
        }

        return Storage::disk('s3')->url($this->product_image_path);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
