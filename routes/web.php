<?php

use App\Http\Controllers\Admin\{AuditLogController, ClientController, ConfigurationController, DashboardController, InstanceController, LoginController, ProvisioningController};
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
    Route::get('/audit', AuditLogController::class)->name('audit.index');
    Route::get('/configuration', [ConfigurationController::class, 'index'])->name('configuration.index');
    Route::post('/configuration/test/{target}', [ConfigurationController::class, 'test'])->whereIn('target', ['cpanel','mysql','filesystem'])->name('configuration.test');
});
