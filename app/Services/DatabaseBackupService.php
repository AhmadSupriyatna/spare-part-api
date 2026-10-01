<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartRepair;
use App\Models\PartReplacementRequest;
use App\Models\PartUnitActionRequest;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\TaskLibrary;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pure-PHP database backup/restore — deliberately NOT shelling out to
 * pg_dump/psql, because neither binary is actually on PATH in this dev
 * environment (checked directly, not assumed), and there's no guarantee
 * they'll be present on every machine this app ever runs on (home PC,
 * office PC, eventually Laravel Cloud). A JSON dump of every business
 * table, restorable with plain DB::table() calls, works anywhere PHP and
 * the DB connection itself work.
 */
class DatabaseBackupService
{
    /**
     * Framework/infra tables excluded from every backup: schema-version
     * tracking (touching this during restore could desync from the
     * codebase's actual migration files), ephemeral runtime state (queue/
     * cache), and per-device session/token data that would only cause
     * confusion — or a security concern, for API tokens — if carried
     * across machines.
     */
    private const EXCLUDED_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'personal_access_tokens',
    ];

    /**
     * @return list<string>
     */
    public function includedTables(): array
    {
        return collect(Schema::getTableListing())
            ->map(fn (string $table) => Str::afterLast($table, '.'))
            ->reject(fn (string $table) => in_array($table, self::EXCLUDED_TABLES, true))
            ->values()
            ->all();
    }

    /**
     * @return array{app: string, scope: string, generated_at: string, tables: array<string, list<array<string, mixed>>>}
     */
    public function exportAll(): array
    {
        $tables = [];

        foreach ($this->includedTables() as $table) {
            $tables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return [
            'app' => 'spare-part-api',
            'scope' => 'full',
            'generated_at' => now()->toIso8601String(),
            'tables' => $tables,
        ];
    }

    /**
     * Replaces every table present in both the backup file and the
     * current schema's allowed list — tables missing from the backup file
     * are left untouched rather than guessed at. Runs inside one
     * transaction with FK-enforcement triggers disabled for its duration
     * (`session_replication_role = replica`, the standard Postgres trick
     * for bulk-loading data without fighting insertion order) so table
     * order in the backup file never matters; each table's id sequence is
     * resynced afterwards so the next INSERT the app makes doesn't collide
     * with a restored id.
     *
     * Requires a DB role with enough privilege to change
     * session_replication_role (superuser locally; a managed cloud
     * Postgres may need this revisited).
     *
     * @param  array{tables?: array<string, list<array<string, mixed>>>}  $backup
     */
    public function restoreAll(array $backup): void
    {
        $allowed = $this->includedTables();
        /** @var Collection<string, list<array<string, mixed>>> $tables */
        $tables = collect($backup['tables'] ?? [])->only($allowed);

        DB::transaction(function () use ($tables) {
            DB::statement('SET session_replication_role = replica');

            foreach ($tables->keys() as $table) {
                DB::table($table)->truncate();
            }

            foreach ($tables as $table => $rows) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    if ($chunk !== []) {
                        DB::table($table)->insert($chunk);
                    }
                }
            }

            DB::statement('SET session_replication_role = origin');

            foreach ($tables->keys() as $table) {
                // Pivot tables (model_has_roles, branch_user, etc.) have no
                // `id` column at all — pg_get_serial_sequence() errors
                // outright on a nonexistent column rather than returning
                // null, so this has to be checked first, not caught after.
                if (! Schema::hasColumn($table, 'id')) {
                    continue;
                }

                $sequence = DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS seq', [$table, 'id'])?->seq;

                if ($sequence) {
                    DB::statement("SELECT setval('{$sequence}', COALESCE((SELECT MAX(id) FROM \"{$table}\"), 1))");
                }
            }
        });
    }

    /**
     * A read-only archival snapshot of one branch's operational data —
     * NOT a restorable file (there's no importForBranch()). Re-importing
     * one plant's rows into another database safely would mean remapping
     * every foreign key it carries (ids collide across environments,
     * shared catalogs like Part are global), which is a materially harder
     * and riskier problem than a straight full-database restore; this is
     * for review/audit/handoff, not disaster recovery.
     *
     * @return array{app: string, scope: string, branch: array{id: int, code: string, name: string}, generated_at: string, tables: array<string, list<array<string, mixed>>>}
     */
    public function exportForBranch(Branch $branch): array
    {
        $suppliers = $branch->suppliers()->get();
        $racks = $branch->racks()->with('levels.locations')->get();
        $locations = $racks->flatMap->levels->flatMap->locations;
        $partStocks = $branch->partStocks()->get();
        $partStockIds = $partStocks->pluck('id');
        $parts = Part::whereIn('id', $partStocks->pluck('part_id')->unique())->get();

        $lines = $branch->lines()->with('machines.equipment')->get();
        $machines = $lines->flatMap->machines;
        $equipment = $machines->flatMap->equipment;
        $equipmentIds = $equipment->pluck('id');

        $taskLibraries = TaskLibrary::whereIn('equipment_id', $equipmentIds)->get();
        $tasks = Task::whereIn('equipment_id', $equipmentIds)->with('partChecks')->get();
        $workOrders = WorkOrder::whereIn('equipment_id', $equipmentIds)->get();
        $partInstallations = PartInstallation::whereIn('equipment_id', $equipmentIds)->get();
        $partReplacementRequests = PartReplacementRequest::whereIn('equipment_id', $equipmentIds)->get();
        $partRepairs = PartRepair::whereIn('part_installation_id', $partInstallations->pluck('id'))->get();
        $partUnitActionRequests = PartUnitActionRequest::where('branch_id', $branch->id)->get();
        $budgets = $branch->budgets()->with('items')->get();
        $stockLedger = StockLedger::whereIn('part_stock_id', $partStockIds)->get();

        $toArray = fn (Collection $items) => $items->map(fn ($item) => $item->toArray())->values()->all();

        return [
            'app' => 'spare-part-api',
            'scope' => 'branch',
            'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
            'generated_at' => now()->toIso8601String(),
            'tables' => [
                'parts' => $toArray($parts),
                'suppliers' => $toArray($suppliers),
                'racks' => $toArray($racks),
                'locations' => $toArray($locations),
                'part_stocks' => $toArray($partStocks),
                'lines' => $toArray($lines),
                'machines' => $toArray($machines),
                'equipment' => $toArray($equipment),
                'task_libraries' => $toArray($taskLibraries),
                'tasks' => $toArray($tasks),
                'work_orders' => $toArray($workOrders),
                'part_installations' => $toArray($partInstallations),
                'part_replacement_requests' => $toArray($partReplacementRequests),
                'part_repairs' => $toArray($partRepairs),
                'part_unit_action_requests' => $toArray($partUnitActionRequests),
                'budgets' => $toArray($budgets),
                'stock_ledger' => $toArray($stockLedger),
            ],
        ];
    }
}
