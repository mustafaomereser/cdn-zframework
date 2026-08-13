<?php

use App\Controllers\Cdn\AdminController;
use App\Controllers\Cdn\ApiController;
use App\Controllers\Cdn\DeliveryController;
use App\Controllers\Cdn\OperatorController;
use App\Middlewares\Cdn\Admin;
use App\Middlewares\Cdn\ApiKey;
use App\Middlewares\Cdn\Operator;
use zFramework\Core\Route;

/**
 * CDN routes.
 *
 * Three surfaces, in the order they are hit: the public delivery endpoint that
 * carries all the traffic, the management API, and the panel.
 *
 * Everything here is declared with [Controller::class, 'method'] rather than a
 * closure, so `php terminal route cache` can compile the table - on an asset
 * host the route table is walked more often than anything else in the process.
 */

$prefix = rtrim((string) (config('cdn.delivery.url-prefix') ?: '/cdn'), '/') ?: '/cdn';

#region Delivery
Route::pre($prefix, '/cdn')->noCSRF()->group(function () {

    # Before the wildcard below, or a bucket called _health would be
    # unreachable - and this one has to answer while the database is down.
    Route::get('/_health', [DeliveryController::class, 'health'])->name('health');

    /**
     * The public endpoint: /cdn/<project>/<bucket>/<path>.
     *
     * The project is a segment of its own so that a bucket name only has to be
     * unique inside a project - every account gets to call one "photos".
     *
     * The router matches segment by segment and has no catch-all, so the object
     * path is spelled out as one required segment and seven optional ones. That
     * is the depth limit, and `cdn.delivery.depth` documents it - a file stored
     * deeper than this is reachable through the API but not through a URL.
     *
     * Registered with any() rather than get(): HEAD is how every cache and
     * download manager asks about a file before fetching it, and OPTIONS is the
     * browser's preflight. Both are handled inside.
     */
    Route::any('/{project}/{bucket}/{p1}/{?p2}/{?p3}/{?p4}/{?p5}/{?p6}/{?p7}/{?p8}', [DeliveryController::class, 'serve'])->name('serve');
});
#endregion

#region Management API
Route::pre((string) (config('cdn.api.route') ?: '/api/cdn'), '/api/cdn')
    ->middleware([ApiKey::class])
    ->noCSRF()
    ->group(function () {

        Route::pre('/v1')->group(function () {
            Route::get('/', [ApiController::class, 'index'])->name('index');

            Route::get('/buckets', [ApiController::class, 'buckets'])->name('buckets');

            Route::get('/files',      [ApiController::class, 'files'])->name('files');
            Route::post('/files',     [ApiController::class, 'upload'])->name('upload');
            Route::get('/files/{id}', [ApiController::class, 'show'])->name('show');
            Route::delete('/files/{id}', [ApiController::class, 'delete'])->name('delete');

            # Delete by path: a path is not an id, and url-encoding one into a
            # segment is a worse interface than a body.
            Route::post('/files/delete', [ApiController::class, 'delete'])->name('delete-by-path');

            # Resumable uploads.
            Route::post('/uploads', [ApiController::class, 'uploadBegin'])->name('upload-begin');
            Route::put('/uploads/{upload}', [ApiController::class, 'uploadChunk'])->name('upload-chunk');
            Route::post('/uploads/{upload}/complete', [ApiController::class, 'uploadComplete'])->name('upload-complete');
            Route::delete('/uploads/{upload}', [ApiController::class, 'uploadAbort'])->name('upload-abort');

            Route::post('/purge', [ApiController::class, 'purge'])->name('purge');
            Route::post('/sign',  [ApiController::class, 'sign'])->name('sign');
            Route::get('/stats',  [ApiController::class, 'stats'])->name('stats');
        });
    });
#endregion

#region Panel
Route::pre((string) (config('cdn.admin.route') ?: '/cdn-admin'), '/cdn-admin')
    ->middleware([Admin::class])
    ->group(function () {

        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');

        Route::get('/buckets', [AdminController::class, 'buckets'])->name('buckets');
        Route::get('/buckets/create', [AdminController::class, 'bucketForm'])->name('buckets.create');
        Route::get('/buckets/{id}/edit', [AdminController::class, 'bucketForm'])->name('buckets.edit');
        Route::post('/buckets', [AdminController::class, 'bucketSave'])->name('buckets.save');
        Route::post('/buckets/{id}/delete', [AdminController::class, 'bucketDelete'])->name('buckets.delete');
        Route::post('/buckets/{id}/purge', [AdminController::class, 'bucketPurge'])->name('buckets.purge');

        Route::get('/files', [AdminController::class, 'files'])->name('files');
        Route::post('/files/upload', [AdminController::class, 'upload'])->name('files.upload');
        Route::post('/files/{id}/delete', [AdminController::class, 'fileDelete'])->name('files.delete');
        Route::post('/files/bulk', [AdminController::class, 'filesBulk'])->name('files.bulk');
        Route::get('/files/{id}', [AdminController::class, 'file'])->name('files.show');

        Route::get('/keys', [AdminController::class, 'keys'])->name('keys');
        Route::post('/keys', [AdminController::class, 'keyCreate'])->name('keys.create');
        Route::post('/keys/{id}/revoke', [AdminController::class, 'keyRevoke'])->name('keys.revoke');

        # One page: what was served, and what was cleared. They answer the same
        # question and used to be two.
        Route::get('/activity', [AdminController::class, 'activity'])->name('activity');

        # Projects have pages of their own. They used to be a block on the
        # settings page, which is where a thing goes when nobody has decided
        # what it is.
        Route::get('/projects', [AdminController::class, 'projects'])->name('projects');
        Route::get('/projects/create', [AdminController::class, 'projectForm'])->name('projects.create');
        Route::post('/projects/create', [AdminController::class, 'projectCreate'])->name('projects.create.save');
        Route::get('/projects/{id}', [AdminController::class, 'project'])->name('projects.show');
        Route::post('/projects/{id}', [AdminController::class, 'projectSave'])->name('projects.save');
        Route::post('/projects/{id}/delete', [AdminController::class, 'projectDelete'])->name('projects.delete');

        # The switcher in the sidebar. A GET because it is a link with
        # javascript off, and it changes nothing but which rows are listed.
        Route::get('/switch', [AdminController::class, 'projectSwitch'])->name('projects.switch');

        Route::get('/settings', [AdminController::class, 'settings'])->name('settings');
    });

# Administering the installation rather than a project. Its own middleware, not
# a branch inside the pages above: what keeps one customer out of another's
# files everywhere else is that the queries cannot express it, and these pages
# exist precisely to express it.
Route::pre((string) (config('cdn.admin.route') ?: '/cdn-admin') . '/admin', '/cdn-admin.operator')
    ->middleware([Operator::class])
    ->group(function () {

        Route::get('/', [OperatorController::class, 'users'])->name('users');
        Route::get('/projects', [OperatorController::class, 'projects'])->name('projects');
        Route::get('/files', [OperatorController::class, 'files'])->name('files');
        Route::post('/files/bulk', [OperatorController::class, 'filesBulk'])->name('files.bulk');
        Route::get('/users/{id}', [OperatorController::class, 'account'])->name('users.show');
        Route::get('/projects/{id}', [OperatorController::class, 'project'])->name('projects.show');
        Route::get('/system', [OperatorController::class, 'system'])->name('system');
        Route::get('/log', [OperatorController::class, 'audits'])->name('audits');
        Route::post('/system/run', [OperatorController::class, 'maintenance'])->name('system.run');

        Route::post('/users/{id}/quota', [OperatorController::class, 'quota'])->name('users.quota');
        Route::post('/users/{id}/status', [OperatorController::class, 'userStatus'])->name('users.status');
        Route::post('/users/{id}/operator', [OperatorController::class, 'operator'])->name('users.operator');
        Route::post('/users/{id}/delete', [OperatorController::class, 'userDelete'])->name('users.delete');

        Route::post('/projects/{id}/quota', [OperatorController::class, 'projectQuota'])->name('projects.quota');
        Route::post('/projects/{id}/bandwidth', [OperatorController::class, 'bandwidthReset'])->name('projects.bandwidth');
        Route::post('/projects/{id}/status', [OperatorController::class, 'projectStatus'])->name('projects.status');

        Route::get('/console', [OperatorController::class, 'console'])->name('console');
        Route::post('/console', [OperatorController::class, 'consoleRun'])->name('console.run');
    });
#endregion
