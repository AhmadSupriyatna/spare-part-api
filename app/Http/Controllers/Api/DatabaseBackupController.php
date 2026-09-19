<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RestoreDatabaseRequest;
use App\Models\Branch;
use App\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;

class DatabaseBackupController extends Controller
{
    public function __construct(private readonly DatabaseBackupService $backups) {}

    public function backup(): Response
    {
        $payload = json_encode($this->backups->exportAll(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = 'backup-spare-part-'.Date::now()->format('Y-m-d_His').'.json';

        return response($payload, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function backupBranch(Branch $branch): Response
    {
        $payload = json_encode($this->backups->exportForBranch($branch), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = "backup-{$branch->code}-".Date::now()->format('Y-m-d_His').'.json';

        return response($payload, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function restore(RestoreDatabaseRequest $request): JsonResponse
    {
        $contents = $request->file('backup_file')->get();
        $backup = json_decode($contents, true);

        if (! is_array($backup) || ($backup['app'] ?? null) !== 'spare-part-api' || ($backup['scope'] ?? null) !== 'full') {
            return response()->json([
                'message' => 'File ini bukan backup penuh yang valid dari aplikasi ini.',
            ], 422);
        }

        $this->backups->restoreAll($backup);

        return response()->json([
            'message' => 'Database berhasil dipulihkan.',
            'restored_at' => now()->toIso8601String(),
        ]);
    }
}
