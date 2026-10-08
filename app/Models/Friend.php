<?php

namespace App\Models;

use Database\Factories\FriendFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'name', 'phone', 'notes'])]
class Friend extends Model
{
    /** @use HasFactory<FriendFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function subscriptionMembers(): HasMany
    {
        return $this->hasMany(SubscriptionMember::class);
    }
}
