<?php

namespace App\Http\Controllers;

use App\Enums\ConfigKey;
use App\Enums\GroupConfigKey;
use App\Enums\UserStatus;
use App\Models\Group;
use App\Services\ChunkUploadStore;
use App\Services\ImageService;
use App\Utils;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class ChunkUploadController extends Controller
{
    private function owner(Request $request): string
    {
        return $request->user() ? 'user:'.$request->user()->id : 'guest:'.$request->session()->getId();
    }

    private function staging(): ChunkUploadStore
    {
        return new ChunkUploadStore(storage_path('app/upload-chunks'));
    }

    private function group(Request $request): Group
    {
        $user = $request->user();
        if (! $user && ! Utils::config(ConfigKey::IsAllowGuestUpload, true)) {
            throw new RuntimeException('管理员关闭了游客上传');
        }
        if ($user && ($user->status !== UserStatus::Normal
            || (Utils::config(ConfigKey::IsUserNeedVerify) && ! $user->email_verified_at))) {
            throw new RuntimeException('账号异常或尚未验证');
        }
        $group = $user ? $user->group : Group::query()->where('is_guest', true)->first();
        if (! $group) throw new RuntimeException('没有可用的角色组');
        return $group;
    }

    public function begin(Request $request)
    {
        $input = $request->validate([
            'name' => 'required|string|max:255', 'size' => 'required|integer|min:1|max:536870912',
            'fingerprint' => 'required|string|max:160', 'strategy_id' => 'nullable|integer',
            'id' => 'nullable|regex:/^[a-f0-9]{32}$/',
        ]);
        try {
            $group = $this->group($request);
            $extension = strtolower(pathinfo($input['name'], PATHINFO_EXTENSION));
            if (! in_array($extension, $group->configs->get(GroupConfigKey::AcceptedFileSuffixes, []))) {
                throw new RuntimeException('不支持的文件类型');
            }
            if ($input['size'] / 1024 > $group->configs->get(GroupConfigKey::MaximumFileSize)) {
                throw new RuntimeException('图片大小超出限制');
            }
            $strategies = $group->strategies()->get();
            $strategy = isset($input['strategy_id']) ? $strategies->find($input['strategy_id']) : $strategies->first();
            if (! $strategy) throw new RuntimeException('选定的存储策略不可用');
            $metadata = ['name' => basename(str_replace('\\', '/', $input['name'])),
                'size' => (int) $input['size'], 'fingerprint' => $input['fingerprint'], 'strategy_id' => $strategy->id];
            $state = $this->staging()->begin($this->owner($request), $metadata, $input['id'] ?? null,
                function ($pending) use ($request, $input) {
                    $user = $request->user();
                    if ($user && ($pending + $input['size']) / 1024 + $user->images()->sum('size') > $user->capacity) {
                        throw new RuntimeException('储存空间不足（包含待完成上传的预留容量）');
                    }
                });
            return $this->success('上传任务已准备', $state);
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    public function part(Request $request, string $id)
    {
        $input = $request->validate(['index' => 'required|integer|min:0|max:10', 'file' => 'required|file|max:51200']);
        try {
            $this->group($request);
            return $this->success('分片已接收', $this->staging()->put($this->owner($request), $id,
                (int) $input['index'], $request->file('file')->getRealPath()));
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    public function complete(Request $request, ImageService $service, string $id)
    {
        try {
            $this->group($request);
            $result = $this->staging()->complete($this->owner($request), $id,
                function ($path, $metadata) use ($request, $service) {
                    $file = new UploadedFile($path, $metadata['name'], null, null, true);
                    $request->files->set('file', $file);
                    $request->merge(['strategy_id' => $metadata['strategy_id']]);
                    // The complete file goes through the existing role, capacity, storage and scan checks.
                    $image = $service->store($request);
                    return ['status' => true, 'message' => '上传成功', 'data' => $image
                        ->setAppends(['pathname', 'links'])->only(
                            'id', 'pathname', 'origin_name', 'size', 'mimetype', 'md5', 'sha1', 'links')];
                });
            return response($result);
        } catch (\Throwable $e) {
            Utils::e($e, '分片合并或提交失败');
            return $this->fail($e->getMessage());
        }
    }
}
