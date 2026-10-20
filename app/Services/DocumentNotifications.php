<?php

namespace App\Services;

use App\Models\DeliveryDocument;
use App\Notifications\DocumentUpdated;
use App\Notifications\WorkflowUpdateMail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class DocumentNotifications
{
    public function send(Collection $recipients, DeliveryDocument $document, string $message, ?string $reason = null): void
    {
        $recipients = $recipients->filter(fn ($u) => $u->access_status === 'approved' && $u->is_active && $u->roleRecord?->is_active)->unique('id');
        Notification::send($recipients, new DocumentUpdated($document, $message));
        $this->sendMail($recipients, $document, $message, $reason);
    }

    public function sendInformational(Collection $recipients, DeliveryDocument $document, string $message, ?string $reason = null): void
    {
        $recipients = $recipients->filter(fn ($u) => $u->access_status === 'approved' && $u->is_active && $u->roleRecord?->is_active)->unique('id');
        $this->sendMail($recipients, $document, $message, $reason);
    }

    private function sendMail(Collection $recipients, DeliveryDocument $document, string $message, ?string $reason): void
    {
        foreach ($recipients as $user) {
            if (filter_var($user->email, FILTER_VALIDATE_EMAIL) && ! str_ends_with(strtolower($user->email), '.local')) {
                $user->notify(new WorkflowUpdateMail($document->id, $document->number, $message, $document->statusLabel(), $reason));
            }
        }
    }
}
