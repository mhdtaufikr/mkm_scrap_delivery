<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\ApprovalMasterController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SecurityAdminController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SsoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'auth.azure'));
Route::get('/MKM@dm1n', [AuthController::class, 'login'])->name('login');
Route::middleware('guest')->group(function () {
    Route::post('/MKM@dm1n', [AuthController::class, 'authenticate'])->name('auth.login');
    Route::get('/login', fn () => redirect()->route('auth.azure'));
    Route::get('/auth/azure', [SsoController::class, 'redirect'])->name('auth.azure');
});
Route::get('/auth/azure/callback', [SsoController::class, 'callback'])->name('auth.azure.callback');
Route::middleware(['auth', 'account.ready'])->group(function () {
    Route::get('/access', [AccessRequestController::class, 'show'])->name('access.show');
    Route::post('/access', [AccessRequestController::class, 'store'])->middleware('throttle:5,1')->name('access.store');
    Route::get('/access-requests', [AccessRequestController::class, 'index'])->name('access-requests.index');
    Route::post('/access-requests/{accessRequest}/decision', [AccessRequestController::class, 'decide'])->name('access-requests.decide');
    Route::get('/home', [DocumentController::class, 'dashboard'])->name('dashboard');
    Route::get('/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');
    Route::post('/profile/refresh', [ProfileController::class, 'refresh'])->name('profile.refresh');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/password', [AuthController::class, 'password'])->name('password.edit');
    Route::put('/password', [AuthController::class, 'updatePassword'])->name('password.update');
    Route::get('/documents/{document}/print', [DocumentController::class, 'print'])->name('documents.print');
    Route::resource('documents', DocumentController::class)->except('destroy');
    Route::post('/documents/{document}/transition', [DocumentController::class, 'transition'])->name('documents.transition');
    Route::get('/documents/{document}/evidence', [DocumentController::class, 'evidence'])->name('documents.evidence');
    Route::get('/documents/{document}/events/{event}/evidence', [DocumentController::class, 'eventEvidence'])->name('documents.event-evidence');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::put('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/masters/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('/masters/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::patch('/masters/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::get('/masters/approvals', [ApprovalMasterController::class, 'index'])->name('approvals.index');
    Route::post('/masters/approvals', [ApprovalMasterController::class, 'store'])->name('approvals.store');
    Route::get('/masters/approvals/{flow}', [ApprovalMasterController::class, 'edit'])->name('approvals.edit');
    Route::put('/masters/approvals/{flow}', [ApprovalMasterController::class, 'update'])->name('approvals.update');
    Route::post('/masters/approvals/{flow}/steps', [ApprovalMasterController::class, 'saveStep'])->name('approvals.steps.save');
    Route::delete('/masters/approvals/{flow}/steps/{step}', [ApprovalMasterController::class, 'deleteStep'])->name('approvals.steps.delete');
    Route::post('/masters/approvals/{flow}/publish', [ApprovalMasterController::class, 'publish'])->name('approvals.publish');
});

// Security devices use their own narrowly scoped browser credential, never an SSO session.
Route::get('/security/activate', [SecurityController::class, 'activation'])->name('security.activate');
Route::post('/security/activate', [SecurityController::class, 'pair'])->middleware('throttle:5,15,security-pair:')->name('security.pair');
Route::middleware('security.device')->prefix('security')->name('security.')->group(function () {
    Route::get('/', [SecurityController::class, 'index']); // Existing bookmarks remain valid.
    Route::get('/gate', [SecurityController::class, 'index'])->name('index');
    Route::get('/documents/{document}', [SecurityController::class, 'show'])->name('show');
    Route::get('/documents/{document}/evidence', [SecurityController::class, 'evidence'])->name('evidence');
    Route::post('/documents/{document}/approve', [SecurityController::class, 'approve'])->middleware('throttle:30,1,security-approve:')->name('approve');
});
Route::middleware(['auth', 'account.ready'])->prefix('masters/security')->name('security-admin.')->group(function () {
    Route::get('/', [SecurityAdminController::class, 'index'])->name('index');
    Route::post('/devices', [SecurityAdminController::class, 'device'])->name('devices.store');
    Route::post('/devices/{device}/activate', [SecurityAdminController::class, 'resetDevice'])->name('devices.activate');
    Route::post('/devices/{device}/revoke', [SecurityAdminController::class, 'revoke'])->name('devices.revoke');
    Route::post('/officers', [SecurityAdminController::class, 'officer'])->name('officers.store');
    Route::patch('/officers/{officer}', [SecurityAdminController::class, 'updateOfficer'])->name('officers.update');
    Route::post('/officers/{officer}/pin', [SecurityAdminController::class, 'resetPin'])->name('officers.pin');
});

// Canonical general-app URLs use a sibling PWA scope, never a parent of /security/.
// Keep existing routes as action-compatible aliases while generated links use /app/.
$generalRoutes = array_filter(Route::getRoutes()->getRoutes(), fn ($route) => $route->getName() && ! str_starts_with($route->getName(), 'security.') && $route->getName() !== 'auth.azure.callback');
foreach ($generalRoutes as $legacyRoute) {
    $name = $legacyRoute->getName();
    $action = $legacyRoute->getAction();
    unset($action['as']);
    $legacyRoute->setAction([...$action, 'legacy_name' => $name]);
    $canonicalAction = $action;
    unset($canonicalAction['prefix']);
    Route::match($legacyRoute->methods(), 'app/'.$legacyRoute->uri(), $canonicalAction)->name($name)->where($legacyRoute->wheres);
}
Route::get('/app', fn () => redirect()->route('pwa.general.install'))->name('pwa.entry');
Route::get('/app/start', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'auth.azure'))->name('pwa.start');
foreach (['general' => 'app', 'security' => 'security'] as $kind => $prefix) {
    Route::get('/'.$prefix.'/install', [PwaController::class, 'install'])->defaults('kind', $kind)->name('pwa.'.$kind.'.install');
    Route::get('/'.$prefix.'/manifest.webmanifest', [PwaController::class, 'manifest'])->defaults('kind', $kind)->name('pwa.'.$kind.'.manifest');
    Route::get('/'.$prefix.'/sw.js', [PwaController::class, 'worker'])->defaults('kind', $kind)->name('pwa.'.$kind.'.worker');
    Route::get('/'.$prefix.'/offline', [PwaController::class, 'offline'])->defaults('kind', $kind)->name('pwa.'.$kind.'.offline');
}
Route::getRoutes()->refreshNameLookups();
Route::getRoutes()->refreshActionLookups();
