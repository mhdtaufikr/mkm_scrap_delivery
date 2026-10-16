<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowUpdateMail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $documentId, public string $number, public string $message, public string $status, public ?string $reason = null)
    {
        $this->afterCommit();
        $this->onConnection('database');
        $this->onQueue('workflow-mail');
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
        $mail = (new MailMessage)->subject('Goods release update - '.$this->number)->greeting('Hello,')->line($this->message)->line('Document: '.$this->number)->line('Status: '.$this->status);
        if ($this->reason) {
            $mail->line('Reason / notes: '.$this->reason);
        }

        return $mail->action('View document', route('documents.show', $this->documentId))->line('Sign in with your MKM account to review the document. Approval actions are available only to authorized users.');
    }
}
