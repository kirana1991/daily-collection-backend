<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date',
        'document_verification_details' => 'array',
        'field_verification_details' => 'array',
        'document_verified_at' => 'datetime',
        'field_verified_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CollectionEntry::class);
    }
}
