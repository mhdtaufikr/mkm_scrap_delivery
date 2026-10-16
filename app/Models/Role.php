<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean'];
    }

    public const PERMISSIONS = [
        'documents.create' => 'Create SMB and manage own drafts',
        'users.manage' => 'Manage users and password resets',
        'roles.manage' => 'Manage roles and permissions',
        'approvals.manage' => 'Manage and publish approval workflows',
    ];

    public static function options(): array
    {
        return array_map(fn ($name) => __($name), static::where('is_active', true)->orderBy('name')->pluck('name', 'code')->all());
    }
}
