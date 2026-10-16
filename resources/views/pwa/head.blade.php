@php($pwa = \App\Http\Controllers\PwaController::settings($pwaKind ?? 'general'))
<link rel="manifest" href="{{ route('pwa.'.$pwa['kind'].'.manifest') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('pwa/icons/'.$pwa['kind'].'-180.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('pwa/icons/'.$pwa['kind'].'-192.png') }}">
<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="apple-mobile-web-app-title" content="{{ $pwa['short_name'] }}">
<meta name="mkm-pwa" data-worker="{{ route('pwa.'.$pwa['kind'].'.worker') }}" data-scope="{{ $pwa['scope'] }}" data-install="{{ $pwa['install_url'] }}" data-name="{{ $pwa['name'] }}">
