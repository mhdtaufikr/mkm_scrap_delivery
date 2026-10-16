<?php

namespace App\Http\Controllers;

class PwaController extends Controller
{
    public static function settings(string $kind): array
    {
        abort_unless(in_array($kind, ['general', 'security'], true), 404);
        $security = $kind === 'security';

        return ['kind' => $kind, 'name' => $security ? 'MKM Security Gate' : 'MKM Scrap Delivery', 'short_name' => $security ? 'MKM Security' : 'MKM Scrap', 'scope' => url($security ? '/security' : '/app').'/', 'start_url' => route($security ? 'security.index' : 'pwa.start'), 'color' => $security ? '#17364b' : '#00677f', 'install_url' => route('pwa.'.$kind.'.install')];
    }

    public function manifest(string $kind)
    {
        $p = self::settings($kind);

        return response()->json([
            'id' => $p['scope'].($kind === 'security' ? 'mkm-security-gate' : 'mkm-scrap-delivery'),
            'name' => $p['name'], 'short_name' => $p['short_name'], 'description' => $kind === 'security' ? 'Security inspection and personal PIN approval for MKM goods release.' : 'MKM goods release requests, approvals and document tracking.',
            'lang' => 'en', 'start_url' => $p['start_url'], 'scope' => $p['scope'], 'display' => 'standalone', 'background_color' => '#f3f6f8', 'theme_color' => $p['color'],
            'icons' => [
                ['src' => asset('pwa/icons/'.$kind.'-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('pwa/icons/'.$kind.'-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
            'shortcuts' => [['name' => $kind === 'security' ? 'Inspect SMB' : 'Goods Release', 'url' => $kind === 'security' ? route('security.index') : route('documents.index')]],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function worker(string $kind)
    {
        $p = self::settings($kind);
        $build = public_path('build/manifest.json');
        $version = substr(hash('sha256', (is_file($build) ? hash_file('sha256', $build) : '1')
            .hash_file('sha256', public_path('pwa/icons/'.$kind.'-512.png'))
            .hash_file('sha256', resource_path('views/pwa/offline.blade.php'))), 0, 12);

        return response()->view('pwa.worker', ['settings' => $p, 'cachePrefix' => 'mkm-scrap-pwa-'.substr(sha1($p['scope']), 0, 12).'-'.$kind.'-', 'version' => $version], 200)
            ->header('Content-Type', 'application/javascript; charset=UTF-8')->header('Service-Worker-Allowed', parse_url($p['scope'], PHP_URL_PATH))->header('Cache-Control', 'no-cache')->header('X-Content-Type-Options', 'nosniff');
    }

    public function install(string $kind)
    {
        return response()->view('pwa.install', ['settings' => self::settings($kind)])->header('Cache-Control', 'no-store, private');
    }

    public function offline(string $kind)
    {
        return response()->view('pwa.offline', ['settings' => self::settings($kind)])->header('Cache-Control', 'public, max-age=300');
    }
}
