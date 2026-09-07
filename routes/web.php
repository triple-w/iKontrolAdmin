<?php

use App\Http\Controllers\Admin\{AuditLogController, ClientController, ConfigurationController, DashboardController, IkontrolVersionController, InstanceController, LegacyFactucareController, FactucareConversionController, LoginController, ProvisioningController};
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
});
Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::resource('clients', ClientController::class)->except('destroy');
    Route::patch('/clients/{client}/toggle', [ClientController::class, 'toggle'])->name('clients.toggle');
    Route::get('/instances', [InstanceController::class, 'index'])->name('instances.index');
    Route::get('/instances/register', [InstanceController::class, 'create'])->name('instances.create');
    Route::post('/instances', [InstanceController::class, 'store'])->name('instances.store');
    Route::get('/instances/{instance}', [InstanceController::class, 'show'])->name('instances.show');
    Route::post('/instances/{instance}/test', [InstanceController::class, 'testConnection'])->middleware('throttle:10,1')->name('instances.test');
    Route::post('/instances/{instance}/inspect', [InstanceController::class, 'inspect'])->middleware('throttle:5,1')->name('instances.inspect');
    Route::get('/provisioning/new', [ProvisioningController::class, 'create'])->name('provisioning.create');
    Route::post('/provisioning/preflight', [ProvisioningController::class, 'preflight'])->name('provisioning.preflight');
    Route::post('/provisioning/dry-run', [ProvisioningController::class, 'dryRun'])->name('provisioning.dry-run');
    Route::post('/provisioning', [ProvisioningController::class, 'store'])->middleware('throttle:3,1')->name('provisioning.store');
    Route::post('/provisioning/{instance}/retry', [ProvisioningController::class, 'retry'])->middleware('throttle:3,1')->name('provisioning.retry');
    Route::post('/provisioning/{instance}/confirm-domain', [ProvisioningController::class, 'confirmDomain'])->middleware('throttle:3,1')->name('provisioning.confirm-domain');
    Route::get('/versions', [IkontrolVersionController::class, 'index'])->name('versions.index');
    Route::get('/versions/create', [IkontrolVersionController::class, 'create'])->name('versions.create');
    Route::post('/versions', [IkontrolVersionController::class, 'store'])->name('versions.store');
    Route::get('/versions/templates/{template}/edit', [IkontrolVersionController::class, 'editTemplate'])->name('versions.templates.edit');
    Route::put('/versions/templates/{template}', [IkontrolVersionController::class, 'updateTemplate'])->name('versions.templates.update');
    Route::get('/versions/{version}/edit', [IkontrolVersionController::class, 'editLegacy'])->name('versions.edit');
    Route::put('/versions/{version}', [IkontrolVersionController::class, 'completeLegacy'])->name('versions.update');
    Route::get('/audit', AuditLogController::class)->name('audit.index');
    Route::get('/legacy/factucare', [LegacyFactucareController::class, 'index'])->name('legacy.factucare.index');
    Route::post('/legacy/factucare/search', [LegacyFactucareController::class, 'search'])->middleware('throttle:20,1')->name('legacy.factucare.search');
    Route::get('/legacy/factucare/users/{user}', [LegacyFactucareController::class, 'show'])->whereNumber('user')->name('legacy.factucare.users.show');
    Route::get('/legacy/factucare/users/{user}/conversion', [FactucareConversionController::class, 'create'])->whereNumber('user')->name('legacy.factucare.conversion.create');
    Route::post('/legacy/factucare/users/{user}/conversion', [FactucareConversionController::class, 'store'])->whereNumber('user')->name('legacy.factucare.conversion.store');
    Route::get('/legacy/factucare/conversion-plans/{plan}', [FactucareConversionController::class, 'show'])->name('legacy.factucare.conversion.plans.show');
    Route::post('/legacy/factucare/conversion-plans/{plan}/regenerate', [FactucareConversionController::class, 'regenerate'])->name('legacy.factucare.conversion.plans.regenerate');
    Route::get('/configuration', [ConfigurationController::class, 'index'])->name('configuration.index');
    Route::post('/configuration/test/{target}', [ConfigurationController::class, 'test'])->whereIn('target', ['cpanel','mysql','filesystem','factucare'])->name('configuration.test');
});
