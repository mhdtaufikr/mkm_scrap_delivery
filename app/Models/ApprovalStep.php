<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['user_ids' => 'array', 'notice_roles' => 'array', 'delegate_notice_roles' => 'array', 'allow_return' => 'boolean'];
    }
}
