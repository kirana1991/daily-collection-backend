<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $guarded = [];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CollectionEntry::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
