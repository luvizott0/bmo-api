<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'owner_id', 'is_personal', 'stock_workspace_id'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function primaryBankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class)->where('is_primary', true);
    }

    public function getPrimaryBankAccount(): ?BankAccount
    {
        return $this->bankAccounts()->where('is_primary', true)->first() ?? $this->bankAccounts()->first();
    }

    public function creditCards(): HasMany
    {
        return $this->hasMany(CreditCard::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function fixedBills(): HasMany
    {
        return $this->hasMany(FixedBill::class);
    }

    public function stockCategories(): HasMany
    {
        return $this->hasMany(StockCategory::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function stockWorkspace(): BelongsTo
    {
        return $this->belongsTo(self::class, 'stock_workspace_id');
    }

    public function sharedStockWorkspaces(): HasMany
    {
        return $this->hasMany(self::class, 'stock_workspace_id');
    }

    public function stockShareInvitations(): HasMany
    {
        return $this->hasMany(StockShareInvitation::class);
    }

    /**
     * Get the effective workspace that owns and stores inventory for this workspace.
     */
    public function effectiveStockWorkspace(): self
    {
        return $this->stock_workspace_id ? ($this->stockWorkspace ?? $this) : $this;
    }
}
