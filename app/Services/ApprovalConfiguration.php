<?php

namespace App\Services;

use App\Models\ApprovalFlow;
use App\Models\DeliveryDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalConfiguration
{
    public function active(string $type): ?array
    {
        return ApprovalFlow::where('type', $type)->where('is_active', true)->first()?->published_definition;
    }

    public function definition(DeliveryDocument $document): ?array
    {
        return $document->approval_snapshot ?: $this->active($document->type);
    }

    public function publish(ApprovalFlow $flow, ?User $actor): void
    {
        DB::transaction(function () use ($flow, $actor) {
            // Serialize publishing for the document type, including activation of another flow.
            ApprovalFlow::where('type', $flow->type)->orderBy('id')->lockForUpdate()->get();
            $flow = ApprovalFlow::findOrFail($flow->id);
            $steps = $flow->steps()->get();
            $fail = fn ($message) => throw ValidationException::withMessages(['flow' => $message]);
            if ($flow->type !== 'SCRAP') {
                $fail('Disposal workflows are not supported yet.');
            }
            $map = [];
            if ($steps->count() > 30) {
                $fail('A workflow supports up to 30 steps.');
            }
            foreach ($steps as $step) {
                if (! in_array($step->actor_mode, ['role', 'users', 'owner']) || ! in_array($step->action, ['next', 'approve', 'handover', 'release'])) {
                    $fail('Invalid assignment or action type.');
                }
                if (in_array($step->code, ['DRAFT', 'COMPLETED'])) {
                    $fail('DRAFT and COMPLETED are reserved system codes.');
                }
                if ($step->actor_mode === 'role' && ! Role::where('code', $step->role_code)->where('is_active', true)->exists()) {
                    $fail('The approver role for '.$step->name.' is inactive.');
                }
                if ($step->actor_mode === 'users' && (! $step->user_ids || User::whereIn('id', $step->user_ids)->where('is_active', true)->whereHas('roleRecord', fn ($q) => $q->where('is_active', true))->count() !== count($step->user_ids))) {
                    $fail('Select active users for '.$step->name.'.');
                }
                $roles = array_unique([...($step->notice_roles ?? []), ...($step->delegate_notice_roles ?? [])]);
                if (Role::whereIn('code', $roles)->where('is_active', true)->count() !== count($roles)) {
                    $fail('A notification role is inactive.');
                }
                if ($step->action === 'release' && $step->next_code !== 'COMPLETED') {
                    $fail('Goods release must lead to Completed.');
                }
                if ($step->action !== 'release' && $step->next_code === 'COMPLETED') {
                    $fail('Completed is only reachable through goods release.');
                }
                if ($step->delegate_to && ($step->action !== 'next' || $step->delegate_to === 'COMPLETED')) {
                    $fail('Delegation is only available during next-approval review and cannot directly complete a document.');
                }
                $map[$step->code] = $step->only(['code', 'name', 'position', 'actor_mode', 'role_code', 'user_ids', 'action', 'action_label', 'next_code', 'delegate_to', 'notice_roles', 'delegate_notice_roles', 'allow_return']);
            }
            if (! isset($map[$flow->start_code])) {
                $fail('Select an available initial step.');
            }
            $draftRoles = array_unique($flow->draft_notice_roles ?? []);
            if (Role::whereIn('code', $draftRoles)->where('is_active', true)->count() !== count($draftRoles)) {
                $fail('A draft notification role is inactive.');
            }
            // Every normal/delegate route must be acyclic and reach release after handover evidence.
            $visited = [];
            $walked = [];
            $walk = function (string $code, array $path = [], bool $signed = false) use (&$walk, &$visited, &$walked, $map, $fail) {
                if (! isset($map[$code])) {
                    $fail('Step destination '.$code.' was not found.');
                }
                if (in_array($code, $path)) {
                    $fail('A workflow cycle was found at '.$code.'.');
                }
                $key = $code.':'.(int) $signed;
                if (isset($walked[$key])) {
                    return;
                }
                $walked[$key] = true;
                $step = $map[$code];
                $visited[$code] = true;
                $signed = $signed || $step['action'] === 'handover';
                if ($step['action'] === 'release' && ! $signed) {
                    $fail('Every release route must include signed handover evidence.');
                }
                foreach (array_filter([$step['next_code'], $step['delegate_to']]) as $target) {
                    if ($target !== 'COMPLETED') {
                        $walk($target, [...$path, $code], $signed);
                    }
                }
            };
            $walk($flow->start_code);
            if (count($visited) !== count($map)) {
                $fail('Some steps are unreachable from the initial step. Connect or remove them.');
            }
            $version = $flow->version + 1;
            $definition = ['flow_id' => $flow->id, 'name' => $flow->name, 'version' => $version, 'start_code' => $flow->start_code, 'draft_notice_roles' => $draftRoles, 'steps' => $map];
            ApprovalFlow::where('type', $flow->type)->update(['is_active' => false]);
            $flow->refresh();
            $flow->update(['is_active' => true, 'version' => $version, 'published_definition' => $definition, 'published_by' => $actor?->id, 'published_at' => now()]);
        });
    }

    public function canAct(DeliveryDocument $doc, User $user): bool
    {
        if ($user->access_status !== 'approved' || ! $user->is_active || ! $user->roleRecord?->is_active || $doc->type !== 'SCRAP') {
            return false;
        }
        if ($doc->status === 'DRAFT') {
            return $doc->editableBy($user) && $this->definition($doc) !== null;
        }
        $step = $this->definition($doc)['steps'][$doc->status] ?? null;
        if (! $step) {
            return false;
        }

        return match ($step['actor_mode']) {
            'owner' => $doc->created_by === $user->id && $user->hasPermission('documents.create'),
            'users' => in_array($user->id, $step['user_ids'] ?? [], true),
            'role' => $user->role === $step['role_code'],
            default => false,
        };
    }

    public function actions(DeliveryDocument $doc): array
    {
        $def = $this->definition($doc);
        if (! $def) {
            return [];
        }
        if ($doc->status === 'DRAFT') {
            return ['submit' => 'Submit → '.__($def['steps'][$def['start_code']]['name'] ?? 'Approval')];
        }
        $step = $def['steps'][$doc->status] ?? null;
        if (! $step) {
            return [];
        }

        return [$step['action'] => __($step['action_label']), ...($step['delegate_to'] ? ['delegate' => 'Delegate & approve'] : []), ...($step['allow_return'] ? ['return' => 'Return to creator'] : [])];
    }
}
