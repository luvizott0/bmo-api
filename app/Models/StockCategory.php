<?php

namespace App\Models;

use Database\Factories\StockCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'name', 'color_hex', 'icon', 'is_active'])]
class StockCategory extends Model
{
    /** @use HasFactory<StockCategoryFactory> */
    use HasFactory;

    /**
     * Get attributes casted to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
