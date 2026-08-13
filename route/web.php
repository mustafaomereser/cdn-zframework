<?php

use App\Controllers\AuthController;
use zFramework\Core\Route;
use App\Controllers\HomeController;
use App\Controllers\LanguageController;
use App\Controllers\PushNotificationController;

Route::get('/language/{lang}', [LanguageController::class, 'set'])->name('language');

# Picking a language nobody has generated yet. `prepare` is the page with the
# bar on it; `build` is what that page calls, once per chunk, until it is done.
Route::get('/language/{lang}/prepare', [LanguageController::class, 'prepare'])->name('language.prepare');
Route::post('/language/{lang}/build', [LanguageController::class, 'build'])->name('language.build');

# Subscribing is a POST, so it carries a csrf token like every other one -
# assets/js/push-notification.js reads it from the page.
Route::pre('/push-notification')->group(function () {
    # Route::pre() already prefixes the name with the group, so these become
    # push-notification.config and so on.
    Route::get('/config', [PushNotificationController::class, 'config'])->name('config');
    Route::post('/subscribe', [PushNotificationController::class, 'subscribe'])->name('subscribe');
    Route::post('/unsubscribe', [PushNotificationController::class, 'unsubscribe'])->name('unsubscribe');
});

Route::middleware([App\Middlewares\Guest::class])->group(function () {
    Route::get('/auth', [AuthController::class, 'auth'])->name('auth-form');
    Route::post('/sign-in', [AuthController::class, 'signin'])->name('sign-in');
    Route::post('/sign-up', [AuthController::class, 'signup'])->name('sign-up');
});

# POST, not any(): a sign-out reachable by GET can be triggered by an <img> tag
# on somebody else's page, and the csrf check only runs on non-GET methods.
Route::middleware([App\Middlewares\Auth::class])->group(fn() => Route::post('/sign-out', [AuthController::class, 'signout'])->name('sign-out'));

Route::get('/auth-content', [AuthController::class, 'content'])->name('auth-content');

# Public, and deliberately so: somebody deciding whether to sign up reads this
# before there is an account to sign in to.
Route::get('/docs', [App\Controllers\Cdn\DocsController::class, 'index'])->name('docs');
Route::get('/docs/{language}', [App\Controllers\Cdn\DocsController::class, 'index'])->name('docs.language');

# A single page, not a resource. The skeleton's resource registered six other
# routes on `/`, one of which POSTed straight into the terminal - a remote shell
# on a host whose whole job is to be publicly reachable.
Route::get('/', [HomeController::class, 'index'])->name('home');



Route::get('/db-migrate', function () {
    echo "<style>body{background: black} pre {background: #111; font-size: 11pt; padding: 10px; border-radius: 5px}</style>";
    ob_start();
    zFramework\Kernel\Terminal::begin(array_merge(['terminal', 'db', 'migrate', '--web'], (request('force') == true ? ['--force'] : [])));
    $logs = ob_get_clean();
    echo "<pre>" . trim($logs) . "</pre>";
});
