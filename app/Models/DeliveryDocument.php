<?php

namespace App\Models;

use App\Services\ApprovalConfiguration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class DeliveryDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'form_details' => 'array', 'document_date' => 'date', 'departure_at' => 'datetime', 'completed_at' => 'datetime', 'approval_snapshot' => 'array', 'pending_user_ids' => 'array', 'pending_owner_only' => 'boolean', 'security_code_issued_at' => 'datetime', 'security_code_used_at' => 'datetime', 'driver_signed_at' => 'datetime', 'sealed_at' => 'datetime'];
    }

    public const STATUSES = [
        'DRAFT' => 'Draft', 'PROC_STAFF' => 'Procurement Staff Review',
        'PROC_DH' => 'Procurement DH Approval', 'HRD' => 'HRD Approval',
        'HANDOVER' => 'Signed Handover', 'SECURITY' => 'Security Inspection',
        'SALES_APPROVAL' => 'Procurement & Sales Approval', 'HRA_APPROVAL' => 'HRA Approval',
        'WAREHOUSE_PICKUP' => 'Warehouse Pickup', 'DRIVER_HANDOVER' => 'Driver Handover & Signature',
        'COMPLETED' => 'Completed',
    ];

    public static function normalizeSecurityCode(string $code): string
    {
        $code = preg_replace('/^\s*MKM[-\s]*SMB\s*:\s*/i', '', $code);

        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
    }

    public static function securityCodeHash(string $code): string
    {
        return hash_hmac('sha256', self::normalizeSecurityCode($code), (string) config('app.key'));
    }

    public function issueSecurityCode(): string
    {
        do {
            $raw = strtoupper(Str::random(12));
            $hash = self::securityCodeHash($raw);
        } while (self::where('security_code_hash', $hash)->exists());

        $code = implode('-', str_split($raw, 4));
        $this->forceFill([
            'security_code_hash' => $hash,
            'security_code_encrypted' => Crypt::encryptString($code),
            'security_code_issued_at' => now(),
            'security_code_used_at' => null,
        ])->save();

        return $code;
    }

    public function securityCode(): ?string
    {
        return $this->security_code_encrypted ? Crypt::decryptString($this->security_code_encrypted) : null;
    }

    public static function driverTokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public function issueDriverToken(): string
    {
        do {
            $token = Str::random(64);
            $hash = self::driverTokenHash($token);
        } while (self::where('driver_token_hash', $hash)->exists());

        $this->forceFill([
            'driver_token_hash' => $hash,
            'driver_token_encrypted' => Crypt::encryptString($token),
            'driver_signed_name' => null,
            'driver_signature_path' => null,
            'driver_signed_at' => null,
            'seal_actual_name' => null,
            'sealed_at' => null,
        ])->save();

        return $token;
    }

    public function driverToken(): ?string
    {
        return $this->driver_token_encrypted ? Crypt::decryptString($this->driver_token_encrypted) : null;
    }

    public function driverSignatureUrl(): ?string
    {
        $token = $this->driverToken();

        return $token ? route('driver-signature.show', ['token' => $token]) : null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->orderBy('id');
    }

    public function inspectionVersion(): string
    {
        return hash('sha256', $this->id.'|'.$this->getRawOriginal('updated_at').'|'.$this->events()->max('id'));
    }

    /** Only approvals from the current submission cycle belong on the printed form. */
    public function printedApproval(string $role): ?DocumentEvent
    {
        if ($this->status === 'DRAFT') {
            return null;
        }
        $events = $this->events->sortBy('id');
        $lastReturn = $events->where('action', 'return')->last()?->id ?? 0;
        $candidates = $events->filter(function (DocumentEvent $event) use ($role, $lastReturn) {
            if ($event->id <= $lastReturn) {
                return false;
            }
            if ($role === 'PROC_DH' && $event->actor_role === 'PROC_STAFF' && $event->action === 'delegate') {
                return true;
            }
            if ($role === 'PROC_DH' && $event->from_status === 'SALES_APPROVAL' && $event->action === 'approve') {
                return true;
            }
            if ($role === 'HRD' && $event->from_status === 'HRA_APPROVAL' && $event->action === 'approve') {
                return true;
            }

            return $event->actor_role === $role && in_array($event->action, ['approve', 'release'], true);
        });

        return $candidates->last();
    }

    public function statusLabel(): string
    {
        return $this->labelFor($this->status);
    }

    public function editableBy(User $user): bool
    {
        return $this->status === 'DRAFT' && $user->hasPermission('documents.create') && $this->created_by === $user->id;
    }

    public function actionableBy(User $user): bool
    {
        return app(ApprovalConfiguration::class)->canAct($this, $user);
    }

    public function labelFor(string $status): string
    {
        return __($this->approval_snapshot['steps'][$status]['name'] ?? self::STATUSES[$status] ?? ApprovalStep::where('code', $status)->latest('id')->value('name') ?? $status);
    }

    public static function statusOptions(): array
    {
        $options = [...self::STATUSES, ...ApprovalStep::orderBy('id')->pluck('name', 'code')->all()];
        foreach (self::whereNotIn('status', array_keys($options))->distinct()->pluck('status') as $code) {
            $document = self::where('status', $code)->latest('id')->first();
            $options[$code] = $document?->approval_snapshot['steps'][$code]['name'] ?? $code;
        }

        return array_map(fn ($name) => __($name), $options);
    }

    public function actions(): array
    {
        return app(ApprovalConfiguration::class)->actions($this);
    }

    public function requiresHandover(): bool
    {
        return isset($this->actions()['handover']);
    }

    protected static function booted(): void
    {
        static::saving(function (self $doc) {
            $step = app(ApprovalConfiguration::class)->definition($doc)['steps'][$doc->status] ?? null;
            $doc->pending_owner_only = $doc->status === 'DRAFT' || ($step['actor_mode'] ?? null) === 'owner';
            $doc->pending_role_code = ($step['actor_mode'] ?? null) === 'role' ? $step['role_code'] : null;
            $doc->pending_user_ids = ($step['actor_mode'] ?? null) === 'users' ? $step['user_ids'] : [];
        });
    }
}
