<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'inventory_item_id',
        'is_custom_item',
        'stock_category_id',
        'name',
        'brand',
        'category_name',
        'category_color',
        'quantity',
        'unit',
        'min_quantity',
        'estimated_price',
        'actual_price',
        'expiration_date',
        'is_checked',
        'notes',
        'added_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_custom_item' => 'boolean',
            'is_checked' => 'boolean',
            'quantity' => 'float',
            'min_quantity' => 'float',
            'estimated_price' => 'float',
            'actual_price' => 'float',
            'expiration_date' => 'date:Y-m-d',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function stockCategory(): BelongsTo
    {
        return $this->belongsTo(StockCategory::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }
}
