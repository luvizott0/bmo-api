<?php

namespace App\Models;

use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'stock_category_id',
    'name',
    'brand',
    'quantity',
    'unit',
    'min_quantity',
    'last_price',
    'average_price',
    'expiration_date',
    'duration_days',
    'last_purchased_at',
    'is_regular_expense',
    'notes',
])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'min_quantity' => 'decimal:2',
            'last_price' => 'decimal:2',
            'average_price' => 'decimal:2',
            'expiration_date' => 'date',
            'last_purchased_at' => 'date',
            'duration_days' => 'integer',
            'is_regular_expense' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(StockCategory::class, 'stock_category_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class)
            ->orderBy('purchased_at', 'desc')
            ->orderBy('id', 'desc');
    }

    /**
     * Recalculate average price and last purchase info from recorded purchases.
     */
    public function recalculateMetrics(): void
    {
        $purchases = $this->purchases()->get();

        if ($purchases->isNotEmpty()) {
            $this->average_price = round((float) $purchases->avg('unit_price'), 2);
            $latest = $purchases->first();
            if ($latest) {
                $this->last_price = $latest->unit_price;
                $this->last_purchased_at = $latest->purchased_at;
            }

            $durations = $purchases->pluck('duration_days')->filter();
            if ($durations->isNotEmpty()) {
                $this->duration_days = (int) round($durations->avg());
            }

            $this->save();
        }
    }
}
