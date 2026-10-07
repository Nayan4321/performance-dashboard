<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SystemUpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardWidgetController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\Inventory\InvoiceController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Inventory\StockOrderController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecordsController;
use App\Http\Controllers\Webhooks\IntegrationWebhookController;
use Illuminate\Support\Facades\Route;

// CallGear "interactive call processing": called on every incoming call (GET or POST).
Route::match(['get', 'post'], 'webhooks/callgear/incoming', \App\Http\Controllers\Webhooks\CallGearIncomingController::class)
    ->middleware('throttle:600,1')->name('webhooks.callgear.incoming');

// Zenoti / CallGear push updates here (token-protected, no login).
Route::post('webhooks/{provider}', IntegrationWebhookController::class)
    ->whereIn('provider', ['zenoti', 'callgear'])->middleware('throttle:600,1')->name('webhooks');

// Automatic syncs without cron: an outside pinger calls this every few minutes (token-protected).
Route::match(['get', 'post'], 'cron/run', [\App\Http\Controllers\AutoSyncController::class, 'run'])
    ->middleware('throttle:30,1')->name('autosync.run');

// Custom logos (needed on the login page, so no login).
Route::get('branding/{kind}', [BrandingController::class, 'asset'])->name('branding.asset');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('complaints', [\App\Http\Controllers\ComplaintController::class, 'index'])->name('complaints.index');
    Route::get('complaints/new', [\App\Http\Controllers\ComplaintController::class, 'create'])->name('complaints.create');
    Route::post('complaints', [\App\Http\Controllers\ComplaintController::class, 'store'])->name('complaints.store');
    Route::put('complaints/{complaint}', [\App\Http\Controllers\ComplaintController::class, 'update'])->name('complaints.update');
    Route::put('complaints/{complaint}/note', [\App\Http\Controllers\ComplaintController::class, 'note'])->name('complaints.note');
    Route::delete('complaints/{complaint}', [\App\Http\Controllers\ComplaintController::class, 'destroy'])->name('complaints.destroy');
    Route::post('auto-sync', [\App\Http\Controllers\AutoSyncController::class, 'nudge'])->middleware('throttle:10,1')->name('autosync.nudge');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile');
    Route::get('notifications', [ActivityController::class, 'notifications'])->name('notifications');
    Route::put('profile', [ProfileController::class, 'update']);
    Route::get('avatars/{user}', [ProfileController::class, 'avatar'])->name('avatars.show');
    Route::get('help', [ProfileController::class, 'help'])->name('help');
    Route::post('notifications/read', [ActivityController::class, 'markRead'])->name('notifications.read');

    Route::get('/', [DashboardController::class, 'home'])->name('home');

    // CallGear agent guides: read with callgear.only, edit with guides.manage.
    Route::get('guides/{guide}', [\App\Http\Controllers\GuideController::class, 'show'])->name('guides.show');
    Route::post('guides/{guide}/notes', [\App\Http\Controllers\GuideController::class, 'store'])->name('guides.notes.store');
    Route::put('guide-notes/{note}', [\App\Http\Controllers\GuideController::class, 'update'])->name('guides.notes.update');
    Route::delete('guide-notes/{note}', [\App\Http\Controllers\GuideController::class, 'destroy'])->name('guides.notes.destroy');
    Route::put('guides/{guide}/flow', [\App\Http\Controllers\GuideController::class, 'saveFlow'])->name('guides.flow.save');
    Route::delete('guides/{guide}/flow', [\App\Http\Controllers\GuideController::class, 'resetFlow'])->name('guides.flow.reset');
    Route::post('guide-notes/{note}/move', [\App\Http\Controllers\GuideController::class, 'move'])->name('guides.notes.move');

    // ---------------------------------------------------------------- performance dashboards

    Route::middleware(['module:dashboards', 'permission:dashboards.view'])->group(function () {
        Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])->name('dashboards.show');
        Route::get('dashboards/{dashboard}/widgets/{widget}/data', [DashboardController::class, 'widgetData'])->name('dashboards.widget-data');
        Route::get('dashboards/{dashboard}/widgets/{widget}/records', [DashboardController::class, 'widgetRecords'])->name('dashboards.widget-records');
        Route::get('dashboards/{dashboard}/employees', [DashboardController::class, 'employees'])->name('dashboards.employees');
    });

    Route::middleware(['module:dashboards', 'permission:dashboards.manage'])->group(function () {
        Route::get('dashboard-builder/create', [DashboardController::class, 'create'])->name('dashboards.create');
        Route::post('dashboard-builder', [DashboardController::class, 'store'])->name('dashboards.store');
        Route::get('dashboard-builder/{dashboard}/edit', [DashboardController::class, 'edit'])->name('dashboards.edit');
        Route::put('dashboard-builder/{dashboard}', [DashboardController::class, 'update'])->name('dashboards.update');
        Route::delete('dashboard-builder/{dashboard}', [DashboardController::class, 'destroy'])->name('dashboards.destroy');
        Route::post('dashboard-builder/{dashboard}/widgets', [DashboardWidgetController::class, 'store'])->name('widgets.store');
        Route::put('dashboard-builder/{dashboard}/widgets/{widget}', [DashboardWidgetController::class, 'update'])->name('widgets.update');
        Route::delete('dashboard-builder/{dashboard}/widgets/{widget}', [DashboardWidgetController::class, 'destroy'])->name('widgets.destroy');
    });

    Route::middleware('module:performance')->group(function () {
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index')->middleware('permission:dashboards.view');
        Route::middleware('permission:dashboards.view')->group(function () {
            Route::get('guests', [RecordsController::class, 'guests'])->name('guests.index');
            Route::get('appointments', [RecordsController::class, 'appointments'])->name('appointments.index');
            Route::get('sales', [RecordsController::class, 'sales'])->name('sales.index');
            Route::get('invoices', [RecordsController::class, 'invoices'])->name('invoices.index');
            Route::get('calls', [RecordsController::class, 'calls'])->name('calls.index');
            Route::get('employees/{employee}', [ActivityController::class, 'employee'])->name('employees.show');
            Route::put('employees/{employee}/tags', [EmployeeController::class, 'updateTags'])->name('employees.tags')->middleware('permission:dashboards.manage');
            Route::post('employees/tags', [EmployeeController::class, 'bulkTags'])->name('employees.bulk-tags')->middleware('permission:dashboards.manage');
            Route::put('employees/{employee}/callgear', [EmployeeController::class, 'linkCallgear'])->name('employees.callgear')->middleware('permission:dashboards.manage');
            Route::put('employees/{employee}/target', [EmployeeController::class, 'updateTarget'])->name('employees.target')->middleware('permission:dashboards.manage');
            Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
        });
        Route::resource('leads', LeadController::class)->except(['show'])->middleware('permission:leads.manage');
    });

    // ---------------------------------------------------------------- administration
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show'])->middleware('permission:users.manage');

        Route::middleware('permission:roles.manage')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        Route::middleware('permission:organizations.manage')->group(function () {
            Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
            Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
            Route::put('organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
            Route::post('branches', [OrganizationController::class, 'storeBranch'])->name('branches.store');
            Route::put('branches/{branch}', [OrganizationController::class, 'updateBranch'])->name('branches.update');
            Route::get('modules', [ModuleController::class, 'index'])->name('modules.index');
            Route::put('modules', [ModuleController::class, 'update'])->name('modules.update');
        });

        Route::middleware('role:super-admin')->group(function () {
            Route::get('branding', [BrandingController::class, 'index'])->name('branding.index');
            Route::put('branding', [BrandingController::class, 'update'])->name('branding.update');
        });

        // Install code updates from the browser (super admin only).
        Route::middleware('role:super-admin')->prefix('system-update')->name('system-update.')->group(function () {
            Route::get('/', [SystemUpdateController::class, 'index'])->name('index');
            Route::post('/', [SystemUpdateController::class, 'upload'])->name('upload');
            Route::post('finish', [SystemUpdateController::class, 'finish'])->name('finish');
            Route::get('{update}', [SystemUpdateController::class, 'show'])->name('show');
            Route::post('{update}/apply', [SystemUpdateController::class, 'apply'])->name('apply');
            Route::post('{update}/rollback', [SystemUpdateController::class, 'rollback'])->name('rollback');
        });

        Route::middleware('permission:integrations.manage')->group(function () {
            Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations.index');
            Route::post('integrations/{provider}/sync', [IntegrationController::class, 'sync'])->name('integrations.sync');
            Route::post('integrations/run-now', [IntegrationController::class, 'runNow'])->name('integrations.run-now');
            Route::post('integrations/requests/{syncRequest}/cancel', [IntegrationController::class, 'cancel'])->name('integrations.cancel');
            Route::get('integrations/zenoti/test', [IntegrationController::class, 'test'])->name('integrations.test');
            Route::get('integrations/zenoti/compare', [\App\Http\Controllers\Admin\RevenueCompareController::class, 'show'])->name('integrations.compare');
            Route::post('integrations/zenoti/compare', [\App\Http\Controllers\Admin\RevenueCompareController::class, 'compare']);
        });
    });

    // ---------------------------------------------------------------- inventory (separate module)
    Route::prefix('inventory')->name('inventory.')->middleware(['module:inventory', 'permission:inventory.access'])->group(function () {
        Route::get('/', [StockOrderController::class, 'index'])->name('home');
        Route::resource('products', ProductController::class)->except(['show', 'destroy'])->middleware('permission:inventory.products.manage');
        Route::get('stock', [ProductController::class, 'stock'])->name('stock');
        Route::put('stock', [ProductController::class, 'updateStock'])->name('stock.update')->middleware('permission:inventory.products.manage');

        Route::get('orders', [StockOrderController::class, 'index'])->name('orders.index');
        Route::get('orders/create', [StockOrderController::class, 'create'])->name('orders.create')->middleware('permission:inventory.orders.create');
        Route::post('orders', [StockOrderController::class, 'store'])->name('orders.store')->middleware('permission:inventory.orders.create');
        Route::get('orders/{order}', [StockOrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/status', [StockOrderController::class, 'updateStatus'])->name('orders.status')->middleware('permission:inventory.orders.approve');
        Route::post('orders/{order}/cancel', [StockOrderController::class, 'cancel'])->name('orders.cancel');

        Route::post('orders/{order}/invoice', [InvoiceController::class, 'generate'])->name('invoices.generate')->middleware('permission:inventory.invoices.generate');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    });
});
