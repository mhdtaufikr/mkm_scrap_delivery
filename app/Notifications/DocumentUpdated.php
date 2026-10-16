<?php

namespace App\Notifications;

use App\Models\DeliveryDocument;
use Illuminate\Notifications\Notification;

class DocumentUpdated extends Notification
{
    public function __construct(public DeliveryDocument $document, public string $message) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['document_id' => $this->document->id, 'number' => $this->document->number, 'message' => $this->message];
    }
}
