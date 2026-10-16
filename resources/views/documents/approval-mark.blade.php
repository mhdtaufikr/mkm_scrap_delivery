@if($event)
<span>{{ $event->security_officer_name ?: ($event->user?->name ?: 'Recorded approver') }}</span>
@if($event->action === 'delegate')<small>Approved by Procurement Staff (Delegate)</small>@else<small>Approved</small>@endif
@if($event->security_device_name)<small>Security post: {{ $event->security_device_name }}</small>@endif
<small>{{ $event->created_at->format('d M Y H:i') }} WIB</small>
@if($event->action === 'delegate' && $event->notes)<small class="paper-delegation-reason">Reason: {{ \Illuminate\Support\Str::limit($event->notes, 180) }}</small>@endif
@endif
