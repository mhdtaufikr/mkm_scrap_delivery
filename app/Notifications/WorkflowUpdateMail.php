<?php

namespace App\Notifications;

use App\Models\DeliveryDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowUpdateMail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $documentId, public string $number, public string $message, public string $status, public ?string $reason = null, public bool $requiresApproval = true)
    {
        $this->afterCommit();
        // Approval notices are operational alerts: send them immediately once the document transaction commits.
        $this->onConnection('sync');
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $document = DeliveryDocument::with('creator')->find($this->documentId);
        $step = $document?->statusLabel() ?? $this->status;
        $url = route('documents.show', $this->documentId);

        return (new MailMessage)
            ->subject('['.($document?->type === 'DISPOSAL' ? 'DISPOSAL' : 'SCRAP').'] '.($this->requiresApproval ? 'Approval Request' : 'Status Notification').': '.$this->number)
            ->view('mail.workflow-update', [
                'document' => $document,
                'recipient' => $notifiable,
                'step' => $step,
                'reason' => $this->reason,
                'trial' => (bool) config('workflow.trial_mode'),
                'requiresApproval' => $this->requiresApproval,
                'url' => $url,
            ])
            ->action('Open document', $url);
    }
}
