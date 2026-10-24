<?php

namespace App\Services;

use App\Models\DeliveryDocument;
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
            if ($from === 'DRIVER_HANDOVER' && ! $doc->driver_signed_at) {
                throw ValidationException::withMessages(['action' => 'The driver must submit a signature before MKM verification.']);
            }
            $doc->status = $next;
            if ($action === 'submit' && ! $doc->security_code_hash) {
                $doc->issueSecurityCode();
            }
            if ($action === 'return') {
                $doc->fill([
                    'handover_path' => null, 'receiver_name' => null, 'pickup_actual_name' => null,
                    'driver_signed_name' => null, 'driver_signature_path' => null, 'driver_signed_at' => null,
                    'driver_token_hash' => null, 'driver_token_encrypted' => null,
                    'seal_actual_name' => null, 'sealed_at' => null,
                ]);
            }
            if ($action === 'handover') {
                $doc->fill($handover);
            }
            if ($action === 'handover' && $next === 'SECURITY') {
                $doc->issueSecurityCode();
            }
            if ($next === 'DRIVER_HANDOVER') {
                $doc->issueDriverToken();
            }
            if ($from === 'DRIVER_HANDOVER' && $action === 'release') {
                $doc->seal_actual_name = $context['security_officer_name'] ?? $context['actual_actor_name'] ?? null;
                $doc->sealed_at = now();
                $doc->handover_path = $doc->driver_signature_path;
                $doc->receiver_name = $doc->driver_signed_name;
            }
            if ($next === 'COMPLETED') {
                $doc->completed_at = now();
            }
            $doc->save();
            $doc->events()->create(['user_id' => $actor->id, 'actor_role' => $actor->role, 'action' => $action, 'action_label' => $actions[$action], 'status_label' => $doc->statusLabel(), 'from_status' => $from, 'to_status' => $next, 'notes' => $notes, 'evidence_path' => $action === 'handover' ? $doc->handover_path : null, ...array_intersect_key($context, array_flip(['security_device_id', 'security_device_name', 'security_officer_name', 'actual_actor_name']))]);
            $actor->unreadNotifications->filter(fn ($notification) => (int) ($notification->data['document_id'] ?? 0) === $doc->id)->each->markAsRead();
            $target = $definition['steps'][$next] ?? null;
            $message = $actor->name.' · '.$actions[$action].' · '.$doc->statusLabel();
            $approvalRoles = ($target['actor_mode'] ?? '') === 'role' ? [$target['role_code']] : [];
            $approvalIds = ($target['actor_mode'] ?? '') === 'users' ? ($target['user_ids'] ?? []) : (($target['actor_mode'] ?? '') === 'owner' ? [$doc->created_by] : []);
            $approvers = User::where('access_status', 'approved')->where('is_active', true)->whereHas('roleRecord', fn ($q) => $q->where('is_active', true))->where(fn ($q) => $q->whereIn('role', $approvalRoles)->orWhereIn('id', $approvalIds))->whereKeyNot($actor->id)->get();
            if ($next !== 'DRIVER_HANDOVER') {
                app(DocumentNotifications::class)->send($approvers, $doc, $message, $notes);
            }
            $noticeRoles = array_unique([...($target['notice_roles'] ?? []), ...($action === 'delegate' ? ($step['delegate_notice_roles'] ?? []) : [])]);
            $informational = User::where('access_status', 'approved')->where('is_active', true)->whereHas('roleRecord', fn ($q) => $q->where('is_active', true))->where(fn ($q) => $q->whereIn('role', $noticeRoles)->orWhere('id', $doc->created_by))->whereKeyNot($actor->id)->whereNotIn('id', $approvers->pluck('id'))->get();
            app(DocumentNotifications::class)->sendInformational($informational, $doc, $message, $notes);
            $yasin = User::where('username', 'mkm01478')->where('access_status', 'approved')->where('is_active', true)->first();
            if ($yasin && $yasin->id !== $actor->id && ! $approvers->contains('id', $yasin->id) && ! $informational->contains('id', $yasin->id)) {
                app(DocumentNotifications::class)->sendInformational(collect([$yasin]), $doc, 'Notifikasi Yasin · '.$message, $notes);
            }
        });
    }
}
