<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\ClientUnitController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ServiceFeeController;
use App\Http\Controllers\ServiceTypeController;
use App\Http\Controllers\ServiceVisitController;
use App\Http\Controllers\StubGatewayController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
    ]);
});

// Dashboard (module 9) — landing page; report payload gated view_reports inside the controller.
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Clients (module 2) — read gated by view_clients, writes by edit_client (P3)
    // Client picker JSON — read-only, shared by the record-service builder and the appointment modal.
    Route::get('clients/lookup', [ClientController::class, 'lookup'])->middleware('can:view_clients')->name('clients.lookup');
    Route::get('clients', [ClientController::class, 'index'])->middleware('can:view_clients')->name('clients.index');
    Route::get('clients/create', [ClientController::class, 'create'])->middleware('can:edit_client')->name('clients.create');
    Route::post('clients', [ClientController::class, 'store'])->middleware('can:edit_client')->name('clients.store');
    Route::get('clients/{client}', [ClientController::class, 'show'])->middleware('can:view_clients')->name('clients.show');
    Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->middleware('can:edit_client')->name('clients.edit');
    Route::put('clients/{client}', [ClientController::class, 'update'])->middleware('can:edit_client')->name('clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->middleware('can:edit_client')->name('clients.destroy');

    // Client Units — nested under clients, gated by view_clients (read) and manage_units (write)
    Route::get('clients/{client}/units', [ClientUnitController::class, 'index'])
        ->middleware('can:view_clients')->name('clients.units.index');
    Route::middleware('can:manage_units')->group(function () {
        Route::post('clients/{client}/units', [ClientUnitController::class, 'store'])->name('clients.units.store');
        Route::put('clients/{client}/units/{unit}', [ClientUnitController::class, 'update'])->name('clients.units.update');
        Route::patch('clients/{client}/units/{unit}/deactivate', [ClientUnitController::class, 'deactivate'])->name('clients.units.deactivate');
    });

    // Service Records (module 4) — gated by record_service (P3)
    Route::middleware('can:record_service')->group(function () {
        Route::get('service-records', [ServiceVisitController::class, 'index'])->name('service-records.index');
        Route::get('service-records/create', [ServiceVisitController::class, 'create'])->name('service-records.create');
        Route::post('service-records', [ServiceVisitController::class, 'store'])->name('service-records.store');
        Route::get('service-records/{serviceRecord}', [ServiceVisitController::class, 'show'])->name('service-records.show');
    });

    // Appointments (module 7) — scheduling, all gated by set_appointment (P3)
    Route::middleware('can:set_appointment')->group(function () {
        Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::post('appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::put('appointments/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');
        Route::patch('appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('appointments.status');
    });

    // Service Fees (module 3) — price book, all gated by edit_fees (P3)
    Route::middleware('can:edit_fees')->group(function () {
        Route::get('fees', [ServiceFeeController::class, 'index'])->name('fees.index');
        Route::post('fees', [ServiceFeeController::class, 'store'])->name('fees.store');
        Route::put('fees/{fee}', [ServiceFeeController::class, 'update'])->name('fees.update');
        Route::delete('fees/{fee}', [ServiceFeeController::class, 'destroy'])->name('fees.destroy');
    });

    // Service Types (manage_service_types — admin + technician)
    Route::middleware('can:manage_service_types')->group(function () {
        Route::get('service-types', [ServiceTypeController::class, 'index'])->name('service-types.index');
        Route::post('service-types', [ServiceTypeController::class, 'store'])->name('service-types.store');
        Route::put('service-types/{serviceType}', [ServiceTypeController::class, 'update'])->name('service-types.update');
    });

    // Users (module 1) — staff management; manage_users is admin-only (P1), so only admins reach these.
    Route::middleware('can:manage_users')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('users/{user}/active', [UserController::class, 'toggleActive'])->name('users.active');
    });

    // Payments (module 5) — collection gated by collect_payment (P3)
    Route::get('payments/{transaction}', [PaymentController::class, 'show'])->middleware('can:collect_payment')->name('payments.show');
    Route::post('payments/{transaction}/cash', [PaymentController::class, 'cash'])->middleware('can:collect_payment')->name('payments.cash');
    Route::post('payments/{transaction}/pay', [PaymentController::class, 'pay'])->middleware('can:collect_payment')->name('payments.pay');
    Route::get('payments/{transaction}/return', [PaymentController::class, 'return'])->name('payments.return');

    // Documents (module 6) — invoice & receipt view + PDF, gated view_clients (P3).
    Route::middleware('can:view_clients')->group(function () {
        Route::get('documents/invoice/{transaction}', [DocumentController::class, 'invoice'])->name('documents.invoice');
        Route::get('documents/invoice/{transaction}/pdf', [DocumentController::class, 'invoicePdf'])->name('documents.invoice.pdf');
        Route::get('documents/receipt/{transaction}', [DocumentController::class, 'receipt'])->name('documents.receipt');
        Route::get('documents/receipt/{transaction}/pdf', [DocumentController::class, 'receiptPdf'])->name('documents.receipt.pdf');
    });

    // Reminders (module 8) — derived due/overdue follow-up list, gated view_clients (P3).
    Route::middleware('can:view_clients')->group(function () {
        Route::get('reminders', [ReminderController::class, 'index'])->name('reminders.index');
        Route::patch('reminders/{client}/contacted', [ReminderController::class, 'toggleContacted'])->name('reminders.contacted');
    });

    // Reports (module 9) — transactions CSV export, gated export_data (P3).
    Route::get('reports/transactions/export', [ReportController::class, 'exportTransactions'])
        ->middleware('can:export_data')->name('reports.transactions.export');
});

// Client Portal (module 10) — public, serial + phone-last-4 gated (P5). No RBAC.
Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'showLogin'])->name('login');
    Route::post('/', [PortalController::class, 'authenticate'])
        ->middleware('throttle:5,1')->name('authenticate');
    Route::post('logout', [PortalController::class, 'logout'])->name('logout');

    Route::middleware('portal.auth')->group(function () {
        Route::get('account', [PortalController::class, 'account'])->name('account');
        Route::get('receipt/{transaction}', [PortalController::class, 'receipt'])->name('receipt');
        Route::get('receipt/{transaction}/pdf', [PortalController::class, 'receiptPdf'])->name('receipt.pdf');
    });
});

// Stub gateway hosted page — only when the fake driver is active.
if (config('services.bayarcash.driver') === 'fake') {
    Route::get('dev/bayarcash/{ref}', [StubGatewayController::class, 'show'])->name('dev.bayarcash.show');
    Route::post('dev/bayarcash/{ref}/simulate', [StubGatewayController::class, 'simulate'])->name('dev.bayarcash.simulate');
}

// Payment gateway callback — public, CSRF-exempt, signature-verified.
Route::post('webhooks/bayarcash', [PaymentWebhookController::class, 'handle'])->name('webhooks.bayarcash');

require __DIR__.'/auth.php';
