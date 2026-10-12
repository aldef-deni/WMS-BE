<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExportJobController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ShippingLabelController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('dashboard', [OperationsController::class, 'dashboard']);
        Route::get('notifications', [OperationsController::class, 'notifications']);
        Route::get('permissions', [MasterController::class, 'permissions']);
        Route::get('master/{kind}', [MasterController::class, 'index']);
        Route::post('master/{kind}', [MasterController::class, 'save']);
        Route::put('master/{kind}/{id}', [MasterController::class, 'save']);
        Route::get('staff', [MasterController::class, 'staff']);
        Route::post('waves', [MasterController::class, 'wave']);
        Route::get('integrations', [IntegrationController::class, 'index']);
        Route::post('integrations/marketplace', [IntegrationController::class, 'marketplace']);
        Route::post('integrations/email', [IntegrationController::class, 'email']);
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::post('orders/import', [DocumentController::class, 'import']);
        Route::get('documents/{id}', [DocumentController::class, 'show']);
        Route::post('documents/{id}/actions/{action}', [DocumentController::class, 'action']);
        Route::get('documents/{id}/pdf', [DocumentController::class, 'pdf']);
        Route::post('documents/{id}/attachments', [DocumentController::class, 'upload']);
        Route::get('attachments/{id}', [DocumentController::class, 'download']);
        Route::get('documents/{id}/label', [ShippingLabelController::class, 'label']);
        Route::get('inventory', [OperationsController::class, 'balances']);
        Route::get('ledger', [OperationsController::class, 'ledger']);
        Route::get('audit', [OperationsController::class, 'audit']);
        Route::get('charges', [OperationsController::class, 'charges']);
        Route::get('invoices', [OperationsController::class, 'invoices']);
        Route::post('invoices', [OperationsController::class, 'invoice']);
        Route::post('invoices/{id}/payments', [OperationsController::class, 'payment']);
        Route::get('reports', [OperationsController::class, 'reports']);
        Route::get('exports/{kind}', [OperationsController::class, 'export']);
        Route::get('report-jobs', [ExportJobController::class, 'index']);
        Route::post('report-jobs', [ExportJobController::class, 'store']);
        Route::get('report-jobs/{id}/download',[ExportJobController::class, 'download']);
    });
});
