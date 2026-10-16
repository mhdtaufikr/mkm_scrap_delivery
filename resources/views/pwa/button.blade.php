<button type="button" class="{{ $installClass ?? 'btn btn-secondary' }}" data-pwa-install>Install {{ ($pwaKind ?? 'general') === 'security' ? 'Security app' : 'app' }}</button>
