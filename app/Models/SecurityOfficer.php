<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityOfficer extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'pin_changed_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function eligible(): bool
    {
        return $this->is_active && $this->user?->is_active && $this->user->access_status === 'approved' && $this->user->role === 'SECURITY' && $this->user->roleRecord?->is_active;
    }
}
