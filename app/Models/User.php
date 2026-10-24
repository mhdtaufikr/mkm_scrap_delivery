<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    protected $attributes = ['local_login_enabled' => true, 'is_active' => true, 'must_change_password' => false, 'access_status' => 'approved', 'role' => 'VIEWER'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'access_status',
        'name',
        'email',
        'password',
        'username',
        'role',
        'is_active',
        'must_change_password',
        'sso_tenant_id',
        'sso_object_id',
        'local_login_enabled',
        'last_login_at',
        'sso_link_pending',
        'job_title',
        'department',
        'avatar_path',
        'profile_synced_at',
        'section',
        'section_source',
        'organization_attributes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'organization_attributes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'local_login_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'sso_link_pending' => 'boolean',
            'profile_synced_at' => 'datetime',
            'organization_attributes' => 'array',
        ];
    }

    public const ROLES = [
        'ADMIN' => 'Administrator', 'PPC' => 'PPC Receiving',
        'PROC_STAFF' => 'Staff Procurement', 'PROC_DH' => 'Dept. Head Procurement',
        'PROC_SH' => 'Section Head Procurement', 'HRD' => 'Dept. Head HRD',
        'SECURITY' => 'Security', 'VIEWER' => 'Viewer',
        'PPC_SH' => 'PPC Section Head', 'PPC_DH' => 'PPC Department Head',
        'WAREHOUSE_PICKUP' => 'Warehouse Pickup PIC', 'SALES' => 'Sales Approver',
        'SALES_SH' => 'Procurement & Sales Section Head', 'SALES_DH' => 'Procurement & Sales Department Head',
        'HRA_DH' => 'HRA Department Head', 'HRA_SH' => 'HRA Section Head',
    ];

    public function roleLabel(): string
    {
        return __($this->roleRecord?->name ?? self::ROLES[$this->role] ?? $this->role);
    }

    public function roleRecord(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'code');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->access_status === 'approved' && $this->is_active && $this->roleRecord?->is_active && in_array($permission, $this->roleRecord->permissions ?? [], true);
    }

    public function routeNotificationForMail(): string
    {
        $trialRecipient = config('workflow.trial_recipient');

        return config('workflow.trial_mode') && filter_var($trialRecipient, FILTER_VALIDATE_EMAIL)
            ? $trialRecipient
            : $this->email;
    }
}
