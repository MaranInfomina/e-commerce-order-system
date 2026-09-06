<?php

namespace App\Models;

use App\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[ObservedBy(ProductObserver::class)]
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'sku',
        'description',
        'image_path',
        'price_cents',
        'stock_quantity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A full URL built at serialization time. The column stores only the
     * object key, so changing the storage endpoint needs no data migration.
     */
    public function getImageUrlAttribute(): ?string
    {
        if ($this->image_path === null) {
            return null;
        }

        return Storage::disk('s3')->url($this->image_path);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
