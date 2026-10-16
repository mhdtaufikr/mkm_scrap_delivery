<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityDevice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['activation_hash', 'token_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'activation_expires_at' => 'datetime', 'token_expires_at' => 'datetime', 'paired_at' => 'datetime', 'last_used_at' => 'datetime'];
    }
}
