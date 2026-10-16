<?php

namespace App\Services;

use App\Models\DeliveryDocument;
use App\Models\SecurityOfficer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentWorkflow
{
    public function transition(DeliveryDocument $document, User $actor, string $action, ?string $notes = null, array $handover = [], array $context = []): void
    {
        DB::transaction(function () use ($document, $actor, $action, $notes, $handover, $context) {
            $doc = DeliveryDocument::lockForUpdate()->findOrFail($document->id);
            $actor = User::findOrFail($actor->id);
            $config = app(ApprovalConfiguration::class);
            $definition = $config->definition($doc);
            if (! $definition) {
                throw ValidationException::withMessages(['action' => 'No active approval workflow is available. Contact your administrator.']);
            }
            $doc->approval_snapshot = $definition;
            abort_unless($doc->actionableBy($actor), 403);
            if ($action === 'release' && SecurityOfficer::where('user_id', $actor->id)->exists()) {
                abort_unless(isset($context['security_device_id'], $context['security_officer_name']), 403, 'Use Security Gate and your personal PIN to approve goods release.');
            }
            $actions = $doc->actions();
            if (! isset($actions[$action])) {
                throw ValidationException::withMessages(['action' => 'The step has changed or the action is unavailable. Reload the page.']);
            }
            $from = $doc->status;
            $step = $definition['steps'][$from] ?? null;
            $next = match ($action) {
                'submit' => $definition['start_code'],
                'return' => 'DRAFT',
                'delegate' => $step['delegate_to'],
                default => $step['next_code'],
            };
            if (in_array($action, ['delegate', 'return']) && mb_strlen(trim($notes ?? '')) < 10) {
                throw ValidationException::withMessages(['notes' => 'Provide a reason of at least 10 characters.']);
            }
            if ($action === 'handover' && empty($handover['handover_path'])) {
                throw ValidationException::withMessages(['evidence' => 'Signed handover evidence is required.']);
            }
            if ($action === 'release' && (! $doc->handover_path || ! Storage::disk('local')->exists($doc->handover_path))) {
                throw ValidationException::withMessages(['evidence' => 'Valid handover evidence is required before goods release.']);
            }
            $doc->status = $next;
            if ($action === 'return') {
                $doc->fill(['handover_path' => null, 'receiver_name' => null]);
            }
            if ($action === 'handover') {
                $doc->fill($handover);
            }
            if ($next === 'COMPLETED') {
                $doc->completed_at = now();
            }
            $doc->save();
            $doc->events()->create(['user_id' => $actor->id, 'actor_role' => $actor->role, 'action' => $action, 'action_label' => $actions[$action], 'status_label' => $doc->statusLabel(), 'from_status' => $from, 'to_status' => $next, 'notes' => $notes, 'evidence_path' => $action === 'handover' ? $doc->handover_path : null, ...array_intersect_key($context, array_flip(['security_device_id', 'security_device_name', 'security_officer_name']))]);
            $target = $definition['steps'][$next] ?? null;
            $roles = array_unique([...($target['notice_roles'] ?? []), ...($action === 'delegate' ? ($step['delegate_notice_roles'] ?? []) : []), ...(($target['actor_mode'] ?? '') === 'role' ? [$target['role_code']] : [])]);
            $ids = array_unique([$doc->created_by, ...(($target['actor_mode'] ?? '') === 'users' ? ($target['user_ids'] ?? []) : [])]);
            $recipients = User::where('access_status', 'approved')->where('is_active', true)->whereHas('roleRecord', fn ($q) => $q->where('is_active', true))->where(fn ($q) => $q->whereIn('role', $roles)->orWhereIn('id', $ids))->get();
            $message = $actor->name.' · '.$actions[$action].' · '.$doc->statusLabel();
            app(DocumentNotifications::class)->send($recipients, $doc, $message, $notes);
        });
    }
}
