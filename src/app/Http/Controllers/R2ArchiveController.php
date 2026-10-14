<?php
namespace App\Http\Controllers;

use App\Models\Image;
use App\Models\R2ArchiveJob;
use App\Models\Strategy;
use App\Services\R2ArchiveQuota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class R2ArchiveController extends Controller
{
    public function health(int $source)
    {
        abort_unless(Schema::hasTable('r2_archive_jobs') && Strategy::find($source), 503);
        return response()->json(['lsky_r2_archive'=>1, 'source'=>$source]);
    }
    public function archived(Request $request, int $source)
    {
        abort_unless(Schema::hasTable('r2_archive_jobs'), 404);
        $path=(string)$request->query('path', '');
        abort_unless(strlen($path) <= 2000 && $path !== '', 404);
        $strategy=Strategy::findOrFail($source);
        $job=R2ArchiveJob::where('source_identity', R2ArchiveQuota::identity($strategy))
            ->where('source_path_hash', hash('sha256', $path))->whereIn('phase', ['switched','done'])->firstOrFail();
        $image=Image::where('id', $job->image_id)->where('strategy_id', $job->target_strategy_id)->first();
        // A shared physical object can outlive the original representative image record.
        $image ??= Image::where('strategy_id', $job->target_strategy_id)
            ->where('path', dirname($job->target_path))->where('name', basename($job->target_path))->firstOrFail();
        return app(\App\Services\ArchivedImageOutput::class)->response($request, $image);
    }
}
