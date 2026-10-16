<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MicrosoftProfileSync
{
    public function sync(User $user, object $identity): void
    {
        $profile = $identity->getRaw();
        $token = $identity->token ?? null;
        if ($token) {
            try {
                $response = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(5)
                    ->withOptions(['allow_redirects' => false])
                    ->get('https://graph.microsoft.com/v1.0/me', ['$select' => 'id,displayName,jobTitle,department']);
                if ($response->successful() && strtolower((string) $response->json('id')) === strtolower((string) $user->sso_object_id)) {
                    $profile = array_replace($profile, $response->json());
                }
            } catch (\Throwable $exception) {
                Log::notice('Microsoft profile request unavailable.', ['exception_class' => $exception::class]);
            }
        }
        $updates = ['profile_synced_at' => now()];
        $name = $profile['displayName'] ?? $identity->getName();
        if (is_string($name) && trim($name) !== '') {
            $updates['name'] = mb_substr(trim($name), 0, 255);
        }
        foreach (['jobTitle' => 'job_title', 'department' => 'department'] as $source => $field) {
            if (array_key_exists($source, $profile) && (is_string($profile[$source]) || $profile[$source] === null)) {
                $updates[$field] = $profile[$source] === null ? null : mb_substr(trim($profile[$source]), 0, 255);
            }
        }
        if ($token) {
            try {
                $organization = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(5)
                    ->withOptions(['allow_redirects' => false])
                    ->get('https://graph.microsoft.com/v1.0/me', ['$select' => 'id,employeeOrgData,onPremisesExtensionAttributes']);
                if ($organization->successful() && strtolower((string) $organization->json('id')) === strtolower((string) $user->sso_object_id)) {
                    $data = $organization->json();
                    $updates['organization_attributes'] = array_intersect_key($data, array_flip(['employeeOrgData', 'onPremisesExtensionAttributes']));
                    $mapping = config('services.azure.section_attribute');
                    if ($mapping && is_string($section = data_get($data, $mapping)) && trim($section) !== '') {
                        $updates['section'] = mb_substr(trim($section), 0, 255);
                        $updates['section_source'] = 'microsoft';
                    }
                }
            } catch (\Throwable $exception) {
                Log::notice('Microsoft organization request unavailable.', ['exception_class' => $exception::class]);
            }
        }
        $previous = $user->avatar_path;
        if ($token) {
            try {
                $photo = Http::withToken($token)->connectTimeout(3)->timeout(5)->withOptions(['allow_redirects' => false])
                    ->get('https://graph.microsoft.com/v1.0/me/photo/$value');
                if ($photo->successful() && strlen($photo->body()) <= 5 * 1024 * 1024) {
                    $info = @getimagesizefromstring($photo->body());
                    $extension = match ($info['mime'] ?? '') {
                        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', default => null
                    };
                    if ($extension) {
                        $path = 'avatars/'.$user->id.'/'.hash('sha256', $photo->body()).'.'.$extension;
                        Storage::disk('local')->put($path, $photo->body());
                        $updates['avatar_path'] = $path;
                    }
                }
            } catch (\Throwable $exception) {
                Log::notice('Microsoft photo request unavailable.', ['exception_class' => $exception::class]);
            }
        }
        // Profile fields do not change email, role, permissions, SSO identity, or password.
        $user->update($updates);
        if ($previous && $previous !== $user->avatar_path && str_starts_with($previous, 'avatars/'.$user->id.'/')) {
            Storage::disk('local')->delete($previous);
        }
    }
}
