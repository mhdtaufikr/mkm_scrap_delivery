<?php

namespace App\Notifications;

use App\Models\AccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccessRequestMail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public AccessRequest $accessRequest, public bool $forAdmin = false)
    {
        $this->afterCommit();
        $this->onQueue('access-mail');
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
        $r = $this->accessRequest;
        $p = $r->profile;
        $mail = (new MailMessage)->subject($this->forAdmin ? 'Access approval required - MKM Scrap Delivery' : 'Your access request has been '.$r->status)->greeting('Hello,');
        if ($this->forAdmin) {
            return $mail->line('A Microsoft SSO user has requested application access.')
                ->line('Name: '.$p['name'])->line('Email: '.$p['email'])
                ->line('Job title: '.($p['job_title'] ?: 'Not provided'))->line('Department: '.($p['department'] ?: 'Not provided'))
                ->line('Section: '.($p['section'] ?: 'Not provided'))->line('Requested role: '.$r->requestedRole?->name)
                ->line('Responsibilities: '.$r->responsibility)->line('Business reason: '.$r->reason)
                ->action('Review access request', route('access-requests.index', ['request' => $r->id]))
                ->line('Sign in as an administrator to approve or reject this request.');
        }

        return $mail->line($r->status === 'approved' ? 'Your access is approved. You can now sign in using Microsoft SSO.' : 'Your access request was rejected. You can sign in to review the decision and submit a revised request.')
            ->line('Requested role: '.$r->requestedRole?->name)->line('Reviewer note: '.($r->review_note ?: 'No additional comment.'))
            ->action($r->status === 'approved' ? 'Open MKM Scrap Delivery' : 'View request', url('/'));
    }
}
