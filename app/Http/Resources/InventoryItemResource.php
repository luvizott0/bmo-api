<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = Carbon::today();
        $daysUntilExpiration = null;
        $status = 'in_stock';

        if ($this->expiration_date) {
            $expDate = Carbon::parse($this->expiration_date)->startOfDay();
            $daysUntilExpiration = (int) $today->diffInDays($expDate, false);

            if ($daysUntilExpiration < 0) {
                $status = 'expired';
            } elseif ($daysUntilExpiration <= 30) {
                $status = 'expiring_soon';
            }
        }

        if ($status !== 'expired' && $status !== 'expiring_soon') {
            if ((float) $this->quantity <= 0) {
                $status = 'out_of_stock';
            } elseif ($this->min_quantity !== null && (float) $this->quantity <= (float) $this->min_quantity) {
                $status = 'low_stock';
            }
        }

        $effectivePrice = (float) ($this->average_price ?? $this->last_price ?? 0);
        $estimatedMonthlyCost = null;
        if ($this->duration_days && $this->duration_days > 0 && $effectivePrice > 0) {
            $estimatedMonthlyCost = round(($effectivePrice / $this->duration_days) * 30, 2);
        }

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'stock_category_id' => $this->stock_category_id,
            'category' => new StockCategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'brand' => $this->brand,
            'quantity' => (float) $this->quantity,
            'unit' => $this->unit ?? 'un',
            'min_quantity' => $this->min_quantity !== null ? (float) $this->min_quantity : null,
            'last_price' => $this->last_price !== null ? (float) $this->last_price : null,
            'average_price' => $this->average_price !== null ? (float) $this->average_price : null,
            'expiration_date' => $this->expiration_date?->format('Y-m-d'),
            'duration_days' => $this->duration_days ? (int) $this->duration_days : null,
            'last_purchased_at' => $this->last_purchased_at?->format('Y-m-d'),
            'is_regular_expense' => (bool) $this->is_regular_expense,
            'notes' => $this->notes,
            'status' => $status,
            'days_until_expiration' => $daysUntilExpiration,
            'estimated_monthly_cost' => $estimatedMonthlyCost,
            'purchases' => InventoryPurchaseResource::collection($this->whenLoaded('purchases')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
