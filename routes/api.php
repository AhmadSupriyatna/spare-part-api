<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\BudgetManualEntryController;
use App\Http\Controllers\Api\CompanySettingController;
use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Http\Controllers\Api\DatabaseBackupController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\Fp3RequestController;
use App\Http\Controllers\Api\LineController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MachineController;
use App\Http\Controllers\Api\MaintenanceDecisionController;
use App\Http\Controllers\Api\MaintenanceHistoryController;
use App\Http\Controllers\Api\PartController;
use App\Http\Controllers\Api\PartInstallationController;
use App\Http\Controllers\Api\PartRepairController;
use App\Http\Controllers\Api\PartReplacementRequestController;
use App\Http\Controllers\Api\PartStockController;
use App\Http\Controllers\Api\PartSupplierController;
use App\Http\Controllers\Api\PartUnitActionRequestController;
use App\Http\Controllers\Api\PartUnitController;
use App\Http\Controllers\Api\PublicBreakdownController;
use App\Http\Controllers\Api\PublicFp3Controller;
use App\Http\Controllers\Api\PublicMachineMonitoringController;
use App\Http\Controllers\Api\PublicMaintenanceReportController;
use App\Http\Controllers\Api\PublicPartUnitController;
use App\Http\Controllers\Api\RackController;
use App\Http\Controllers\Api\RackLevelController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskLibraryChecklistItemController;
use App\Http\Controllers\Api\TaskLibraryController;
use App\Http\Controllers\Api\TaskLibraryPartController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Unauthenticated shop-floor QR-scan flows: read-only lookups plus submitting
// a request. Nothing here touches stock or installations — that only happens
// once an Engineer/Teknisi approves the request (see below).
Route::middleware('throttle:breakdown-public')->prefix('public')->group(function () {
    Route::get('/parts/{part}', [PublicBreakdownController::class, 'showPart']);
    Route::get('/branches', [PublicBreakdownController::class, 'branches']);
    Route::get('/branches/{branch}/lines', [PublicBreakdownController::class, 'lines']);
    Route::get('/lines/{line}/machines', [PublicBreakdownController::class, 'machines']);
    Route::get('/machines/{machine}/equipment', [PublicBreakdownController::class, 'equipment']);
    Route::get('/parts/{part}/branches/{branch}/equipment', [PublicBreakdownController::class, 'equipmentForPartInBranch']);
    Route::post('/branches/{branch}/replacement-requests', [PublicBreakdownController::class, 'store']);

    // QR-per-unit flow: scanning the tag on a specific physical part unit —
    // reuses the branches/equipment-for-part lookups above for the reinstall
    // picker, since a unit's part_id feeds the same BOM-filtered endpoint.
    Route::get('/part-units/{partUnit}', [PublicPartUnitController::class, 'show']);
    Route::post('/part-units/{partUnit}/action-requests', [PublicPartUnitController::class, 'store']);

    // QR-per-machine "Monitoring Life Time Mesin" flow: read-only, no
    // submission — see PublicMachineMonitoringController's own docblock.
    Route::get('/machines/{machine}/monitoring', [PublicMachineMonitoringController::class, 'show']);

    // FP3 "Ajukan FP3" flow: one generic QR per branch — see
    // PublicFp3Controller's own docblock.
    Route::post('/branches/{branch}/fp3-requests', [PublicFp3Controller::class, 'store']);

    // QR Validator for Cetak FP3 — read-only, no submission.
    Route::get('/fp3-requests/{fp3Request}', [PublicFp3Controller::class, 'show']);

    // The FP3 form's "Departemen" picklist — same read as the authenticated
    // /departments below, just reachable without login too.
    Route::get('/departments', [DepartmentController::class, 'index']);

    // QR Validator for Laporan Pemeriksaan Mesin — read-only, no submission
    // — see PublicMaintenanceReportController's own docblock.
    Route::get('/wo-report', [PublicMaintenanceReportController::class, 'show']);
});

// branch.access is a no-op for Superadmin/Supervisor and for any route whose
// bound models don't resolve to a branch at all (global lists like
// GET /parts, GET /tasks/mine) — safe to apply blanket-wide rather than
// threading it into each sub-group below.
Route::middleware(['auth:sanctum', 'track.activity', 'branch.access'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::put('/user', [AuthController::class, 'updateProfile']);
    Route::get('/search', [SearchController::class, 'index']);

    // Master data: any authenticated role can read.
    Route::apiResource('branches', BranchController::class)->only(['index', 'show']);
    Route::apiResource('parts', PartController::class)->only(['index', 'show']);
    Route::get('/units', [UnitController::class, 'index']);
    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::get('/branches/{branch}/active-users', [UserController::class, 'activeForBranch']);
    Route::get('/branches/{branch}/supervisor', [UserController::class, 'supervisorForBranch']);
    Route::get('/settings/company', [CompanySettingController::class, 'show']);
    Route::get('/branches/{branch}/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
    Route::get('/branches/{branch}/locations', [LocationController::class, 'index']);
    Route::get('/locations/{location}', [LocationController::class, 'show']);
    Route::get('/branches/{branch}/racks', [RackController::class, 'index']);

    // Stock: any authenticated role can view current stock and its history.
    Route::get('/branches/{branch}/part-stocks', [PartStockController::class, 'index']);
    Route::get('/part-stocks/{partStock}', [PartStockController::class, 'show']);
    Route::get('/part-stocks/{partStock}/ledger', [PartStockController::class, 'ledger']);
    Route::get('/part-stocks/{partStock}/reservations', [PartStockController::class, 'reservations']);
    Route::get('/part-stocks/{partStock}/analytics', [PartStockController::class, 'analytics']);
    Route::get('/branches/{branch}/stock-ledger', [PartStockController::class, 'branchLedger']);

    // Line > Machine > Equipment hierarchy: any authenticated role can read.
    Route::get('/branches/{branch}/lines', [LineController::class, 'index']);
    Route::get('/lines/{line}', [LineController::class, 'show']);
    Route::get('/lines/{line}/runtime-logs', [LineController::class, 'runtimeLogs']);
    Route::get('/lines/{line}/runtime-at', [LineController::class, 'runtimeAsOf']);
    Route::get('/lines/{line}/kwh-logs', [LineController::class, 'kwhLogs']);
    // "Laporan kWh" / "Laporan Running Hours Line" — every Line in the
    // branch, aggregated per calendar month (see LineController::kwhReport()/runtimeReport()).
    Route::get('/branches/{branch}/lines/kwh-report', [LineController::class, 'kwhReport']);
    Route::get('/branches/{branch}/lines/runtime-report', [LineController::class, 'runtimeReport']);
    Route::get('/lines/{line}/machines', [MachineController::class, 'index']);
    Route::get('/machines/{machine}', [MachineController::class, 'show']);
    Route::get('/machines/{machine}/summary', [MachineController::class, 'summary']);
    Route::get('/machines/{machine}/equipment', [EquipmentController::class, 'index']);
    Route::get('/equipment/{equipment}', [EquipmentController::class, 'show']);
    // Phase 3B "Asset Outside Line" — an asset with no Line/Machine at all.
    Route::get('/branches/{branch}/equipment', [EquipmentController::class, 'indexOutsideLine']);
    // "Mesin Luar Line" — a Machine with no production Line at all.
    Route::get('/branches/{branch}/machines', [MachineController::class, 'indexOutsideLine']);

    // Work orders (maintenance schedule templates) and tasks (actual work items):
    // any authenticated role can read.
    Route::get('/equipment/{equipment}/work-orders', [WorkOrderController::class, 'index']);
    Route::get('/branches/{branch}/work-orders', [WorkOrderController::class, 'indexForBranch']);
    Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show']);
    Route::get('/parts/{part}/work-orders', [WorkOrderController::class, 'forPart']);
    Route::get('/equipment/{equipment}/tasks', [TaskController::class, 'index']);
    Route::get('/tasks/mine', [TaskController::class, 'mine']);
    Route::get('/tasks/{task}', [TaskController::class, 'show']);

    // Task Library ("PM task" recipes: activity + equipment + planned parts)
    // and the PM schedule/ledger of tasks generated from them: any
    // authenticated role can read.
    Route::get('/equipment/{equipment}/task-libraries', [TaskLibraryController::class, 'index']);
    Route::get('/branches/{branch}/task-libraries', [TaskLibraryController::class, 'indexForBranch']);
    Route::get('/task-libraries/{taskLibrary}', [TaskLibraryController::class, 'show']);
    Route::get('/branches/{branch}/pm-tasks', [TaskController::class, 'pmSchedule']);
    Route::get('/branches/{branch}/task-reschedules', [TaskController::class, 'rescheduleHistory']);
    // Laporan Pemeriksaan Mesin (Phase 4): every WO under a Machine (or
    // every Machine under a Line) due within an explicit date range,
    // regular PM + Part Lifetime auto-scheduled alike — a read-time
    // grouping only, see TaskController::woReportForMachine()/woReportForLine().
    Route::get('/machines/{machine}/wo-report', [TaskController::class, 'woReportForMachine']);
    Route::get('/machines/{machine}/task-history', [TaskController::class, 'historyForMachine']);
    Route::get('/lines/{line}/wo-report', [TaskController::class, 'woReportForLine']);
    Route::get('/branches/{branch}/part-lifetime-alerts', [PartInstallationController::class, 'atRisk']);

    // Part <-> Supplier (approved supplier list per part) relation, plus part
    // lifetime tracking: any authenticated role can read.
    Route::get('/parts/{part}/suppliers', [PartSupplierController::class, 'index']);
    Route::get('/suppliers/{supplier}/parts', [PartSupplierController::class, 'forSupplier']);
    Route::get('/equipment/{equipment}/part-installations', [PartInstallationController::class, 'index']);
    Route::get('/parts/{part}/part-installations', [PartInstallationController::class, 'forPart']);
    Route::get('/locations/{location}/part-stocks', [PartStockController::class, 'forLocation']);

    // Part units: one row per physical, trackable part instance — its
    // install/repair history across equipment and repair cycles.
    Route::get('/parts/{part}/units', [PartUnitController::class, 'index']);
    Route::get('/part-units/{partUnit}', [PartUnitController::class, 'show']);
    Route::get('/part-repairs', [PartRepairController::class, 'index']);

    // Maintenance History (Phase 2 Step 5): read-only aggregation over
    // existing sources, any authenticated role can read — same "View
    // Finding" level as the rest of the Finding/Decision data it surfaces.
    Route::get('/branches/{branch}/maintenance-history', [MaintenanceHistoryController::class, 'index']);

    // Any authenticated role can work their own tasks and log line runtime.
    Route::post('/tasks/{task}/start', [TaskController::class, 'start']);
    Route::post('/tasks/{task}/complete', [TaskController::class, 'complete']);
    Route::post('/tasks/{task}/cancel', [TaskController::class, 'cancel']);
    Route::post('/lines/{line}/runtime', [LineController::class, 'addRuntime']);
    Route::post('/lines/{line}/kwh', [LineController::class, 'addKwh']);

    // FP3 (Formulir Permintaan Perbaikan dan Pembuatan) — read + claim/work
    // is open to any authenticated role the same way Task's is above;
    // Fp3RequestController::ensureCanWork() does the actual
    // engineer|supervisor|superadmin + claim check.
    Route::get('/branches/{branch}/fp3-requests', [Fp3RequestController::class, 'indexForBranch']);
    Route::get('/fp3-requests/{fp3Request}', [Fp3RequestController::class, 'show']);
    Route::post('/fp3-requests/{fp3Request}/receive', [Fp3RequestController::class, 'receive']);
    Route::post('/fp3-requests/{fp3Request}/complete', [Fp3RequestController::class, 'complete']);
    Route::post('/fp3-requests/{fp3Request}/cancel', [Fp3RequestController::class, 'cancel']);

    // Master data & inventory: Admin Spare Part is the broadest role day to
    // day, but confined to their own branch by branch.access above; only
    // Superadmin can act outside it. Supervisor is deliberately absent here
    // — oversight (approve + view everywhere) but no master-data CRUD.
    Route::middleware('role:admin_spare_part|superadmin')->group(function () {
        Route::apiResource('branches', BranchController::class)->only(['store', 'update']);
        Route::apiResource('parts', PartController::class)->only(['store', 'update', 'destroy']);
        Route::post('/units', [UnitController::class, 'store']);
        Route::put('/units/{unit}', [UnitController::class, 'update']);
        Route::delete('/units/{unit}', [UnitController::class, 'destroy']);
        Route::post('/departments', [DepartmentController::class, 'store']);
        Route::put('/departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);
        Route::put('/settings/company', [CompanySettingController::class, 'update']);
        Route::post('/branches/{branch}/suppliers', [SupplierController::class, 'store']);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
        Route::put('/locations/{location}', [LocationController::class, 'update']);
        Route::delete('/locations/{location}', [LocationController::class, 'destroy']);
        Route::post('/branches/{branch}/racks', [RackController::class, 'store']);
        Route::delete('/racks/{rack}', [RackController::class, 'destroy']);
        Route::post('/racks/{rack}/levels', [RackLevelController::class, 'store']);
        Route::delete('/rack-levels/{rackLevel}', [RackLevelController::class, 'destroy']);
        Route::post('/rack-levels/{rackLevel}/locations', [LocationController::class, 'store']);

        Route::post('/branches/{branch}/part-stocks', [PartStockController::class, 'store']);
        Route::post('/part-stocks/{partStock}/receive', [PartStockController::class, 'receive']);
        Route::post('/part-stocks/{partStock}/adjust', [PartStockController::class, 'adjust'])
            ->middleware('throttle:stock-adjustment');
        Route::put('/part-stocks/{partStock}/location', [PartStockController::class, 'updateLocation']);

        Route::post('/branches/{branch}/lines', [LineController::class, 'store']);
        Route::put('/lines/{line}', [LineController::class, 'update']);
        Route::delete('/lines/{line}', [LineController::class, 'destroy']);
        Route::post('/lines/{line}/machines', [MachineController::class, 'store']);
        Route::put('/machines/{machine}', [MachineController::class, 'update']);
        Route::delete('/machines/{machine}', [MachineController::class, 'destroy']);
        Route::post('/machines/{machine}/equipment', [EquipmentController::class, 'store']);
        Route::put('/equipment/{equipment}', [EquipmentController::class, 'update']);
        Route::delete('/equipment/{equipment}', [EquipmentController::class, 'destroy']);
        // Phase 3B "Asset Outside Line" — same role slice as the
        // machine-scoped Equipment CRUD above.
        Route::post('/branches/{branch}/equipment', [EquipmentController::class, 'storeOutsideLine']);
        // "Mesin Luar Line" — same role slice as the line-scoped Machine CRUD above.
        Route::post('/branches/{branch}/machines', [MachineController::class, 'storeOutsideLine']);

        Route::post('/equipment/{equipment}/work-orders', [WorkOrderController::class, 'store']);
        Route::put('/work-orders/{workOrder}', [WorkOrderController::class, 'update']);
        Route::delete('/work-orders/{workOrder}', [WorkOrderController::class, 'destroy']);
        Route::post('/work-orders/{workOrder}/generate-task', [WorkOrderController::class, 'generateTask']);

        Route::post('/equipment/{equipment}/tasks', [TaskController::class, 'store']);
        Route::put('/tasks/{task}', [TaskController::class, 'update']);
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);

        Route::post('/parts/{part}/suppliers', [PartSupplierController::class, 'store']);
        Route::put('/part-suppliers/{partSupplier}', [PartSupplierController::class, 'update']);
        Route::delete('/part-suppliers/{partSupplier}', [PartSupplierController::class, 'destroy']);

        Route::post('/equipment/{equipment}/part-installations', [PartInstallationController::class, 'store']);
        Route::put('/part-installations/{partInstallation}', [PartInstallationController::class, 'update']);
        Route::post('/part-installations/{partInstallation}/remove', [PartInstallationController::class, 'remove']);
        Route::post(
            '/part-installations/{partInstallation}/schedule-replacement',
            [PartInstallationController::class, 'scheduleReplacement']
        );

        Route::put('/part-repairs/{partRepair}', [PartRepairController::class, 'update']);
    });

    // BOM and Task Library (PM recipes): Engineer's turf alongside Admin
    // Spare Part/Superadmin — this is the "kelola BOM/Task Library" slice of
    // the Engineer role, distinct from (and narrower than) full master-data
    // management above.
    Route::middleware('role:admin_spare_part|engineer|superadmin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);

        Route::post('/equipment/{equipment}/task-libraries', [TaskLibraryController::class, 'store']);
        Route::put('/task-libraries/{taskLibrary}', [TaskLibraryController::class, 'update']);
        Route::delete('/task-libraries/{taskLibrary}', [TaskLibraryController::class, 'destroy']);
        Route::post('/task-libraries/{taskLibrary}/parts', [TaskLibraryPartController::class, 'store']);
        Route::delete('/task-library-parts/{taskLibraryPart}', [TaskLibraryPartController::class, 'destroy']);
        Route::post('/task-libraries/{taskLibrary}/checklist-items', [TaskLibraryChecklistItemController::class, 'store']);
        Route::delete('/task-library-checklist-items/{taskLibraryChecklistItem}', [TaskLibraryChecklistItemController::class, 'destroy']);
        Route::post('/task-libraries/{taskLibrary}/schedule', [TaskLibraryController::class, 'schedule']);
        Route::put('/fp3-requests/{fp3Request}/schedule', [Fp3RequestController::class, 'schedule']);
    });

    // Drag-to-reschedule on the PM Schedule calendar — Supervisor plans/
    // oversees the schedule, alongside Admin Spare Part/Superadmin.
    Route::middleware('role:admin_spare_part|supervisor|superadmin')->group(function () {
        Route::put('/tasks/{task}/reschedule', [TaskController::class, 'reschedule']);

        // Budget is a planning/finance concern — Admin Spare Part sets it,
        // Supervisor oversees it, Engineer has no reason to see or touch it.
        Route::get('/branches/{branch}/budgets', [BudgetController::class, 'index']);
        Route::get('/branches/{branch}/budgets/parts', [BudgetController::class, 'partsForStrategy']);
        Route::post('/branches/{branch}/budgets', [BudgetController::class, 'store']);
        Route::put('/budgets/{budget}', [BudgetController::class, 'update']);
        Route::delete('/budgets/{budget}', [BudgetController::class, 'destroy']);
        Route::post('/budgets/{budget}/manual-entries', [BudgetManualEntryController::class, 'store']);
        Route::delete('/budget-manual-entries/{manualEntry}', [BudgetManualEntryController::class, 'destroy']);

        // Same finance-concern gate as Budget above — the dashboard's cost
        // heatmap and budget projection chart data live here too.
        Route::get('/branches/{branch}/dashboard-analytics', [DashboardAnalyticsController::class, 'show']);

        Route::get('/branches/{branch}/activity-log', [ActivityLogController::class, 'index']);
    });

    // Breakdown replacement / part-unit-action approval boards, read side:
    // Engineer keeps visibility into what's pending/decided (it's their own
    // field requests half the time), Supervisor/Superadmin the same.
    Route::middleware('role:engineer|supervisor|superadmin')->group(function () {
        Route::get('/branches/{branch}/replacement-requests', [PartReplacementRequestController::class, 'index']);
        Route::get('/branches/{branch}/part-unit-action-requests', [PartUnitActionRequestController::class, 'index']);
    });

    // Same boards, decide side: approving/rejecting is Supervisor's call
    // (plus Superadmin) — Engineer can see the board but not act on it, and
    // Admin Spare Part never had a say here since this isn't inventory.
    Route::middleware('role:supervisor|superadmin')->group(function () {
        Route::post('/replacement-requests/{partReplacementRequest}/approve', [PartReplacementRequestController::class, 'approve']);
        Route::post('/replacement-requests/{partReplacementRequest}/reject', [PartReplacementRequestController::class, 'reject']);

        Route::post('/part-unit-action-requests/{partUnitActionRequest}/approve', [PartUnitActionRequestController::class, 'approve']);
        Route::post('/part-unit-action-requests/{partUnitActionRequest}/reject', [PartUnitActionRequestController::class, 'reject']);

        // Deciding a Finding (Phase 2 Step 4) is the same authority level as
        // approving a replacement — Supervisor/Superadmin only, matching the
        // locked role matrix.
        Route::post('/findings/{finding}/decision', [MaintenanceDecisionController::class, 'store']);
    });

    // Managing other people's accounts (role, branches, password, active
    // status) is the one thing kept to Superadmin only — narrower than
    // every other "kelola" group above, deliberately.
    Route::middleware('role:superadmin')->group(function () {
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('/users/{user}/activate', [UserController::class, 'activate']);

        // Undoing a mistaken "Lepas" on the Repair Part board — a data-entry
        // correction, not a routine inventory action, so it's kept as
        // narrow as account management above.
        Route::delete('/part-repairs/{partRepair}', [PartRepairController::class, 'destroy']);

        // Archiving (and the rarely-needed hard delete) a Plant is kept to
        // Superadmin only and gated behind DestroyWithPasswordRequest —
        // branch_id cascades to suppliers, locations/racks, part_stocks,
        // lines, budgets, and the branch_user pivot, so this is by far the
        // widest-blast-radius action in the app. Un-archiving doesn't need
        // the password re-entry since it only restores access, it can't
        // lose anything.
        Route::post('/branches/{branch}/archive', [BranchController::class, 'archive']);
        Route::post('/branches/{branch}/unarchive', [BranchController::class, 'unarchive']);
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy']);

        // Full-database backup/restore — a pure-PHP JSON dump/reload, not
        // pg_dump/psql (neither is on PATH here, and there's no guarantee
        // either is on every machine this app runs on). Restore replaces
        // every table in the file, so it's gated the same as the other
        // widest-blast-radius actions above. Per-branch export is
        // read-only/archival — see DatabaseBackupService for why there's
        // no matching per-branch import.
        Route::get('/admin/backup', [DatabaseBackupController::class, 'backup']);
        Route::post('/admin/restore', [DatabaseBackupController::class, 'restore']);
        Route::get('/branches/{branch}/backup', [DatabaseBackupController::class, 'backupBranch']);
    });
});
