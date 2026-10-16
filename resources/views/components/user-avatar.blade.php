@props(['user', 'size' => 'size-8'])
@if($user->avatar_path)
<img src="{{ route('profile.avatar', ['v' => substr(hash('sha256', $user->avatar_path), 0, 12)]) }}" alt="Profile photo {{ $user->name }}" class="{{ $size }} shrink-0 rounded-full object-cover">
@else
<span class="{{ $size }} flex shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary">{{ mb_substr($user->name, 0, 1) }}</span>
@endif
