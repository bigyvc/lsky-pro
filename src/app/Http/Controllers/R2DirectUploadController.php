<?php

namespace App\Http\Controllers;

use App\Exceptions\UploadException;
use App\Services\R2DirectUpload;
use App\Utils;
use Illuminate\Http\Request;

class R2DirectUploadController extends Controller
{
    private function run(callable $callback)
    {
        try { return $callback(); }
        catch (UploadException $e) { return $this->fail($e->getMessage()); }
        catch (\Throwable $e) {
            Utils::e($e, 'R2 直传控制请求失败');
            return $this->fail('R2 请求失败，请重试；若持续失败，请检查存储权限及服务器日志');
        }
    }

    public function begin(Request $request, R2DirectUpload $service)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'size' => 'required|integer|min:1|max:536870912',
            'md5' => 'required|regex:/^[a-f0-9]{32}$/', 'sha1' => 'required|regex:/^[a-f0-9]{40}$/',
            'parts_json' => 'required|json|max:4096', 'strategy_id' => 'required|integer',
            'id' => 'nullable|regex:/^[a-f0-9]{32}$/',
        ]);
        $parts = json_decode($data['parts_json'], true);
        $count = (int) ceil($data['size'] / R2DirectUpload::CHUNK_SIZE);
        if (! is_array($parts) || ! array_is_list($parts) || count($parts) !== $count) return $this->fail('分片清单无效');
        foreach ($parts as $index => $part) {
            $expected = min(R2DirectUpload::CHUNK_SIZE, (int) $data['size'] - $index * R2DirectUpload::CHUNK_SIZE);
            if (! is_array($part) || ! is_int($part['size'] ?? null) || $part['size'] !== $expected
                || ! preg_match('/^[a-f0-9]{32}$/D', $part['md5'] ?? '')) return $this->fail('分片大小或校验值无效');
        }
        $file = ['name' => basename(str_replace('\\', '/', $data['name'])), 'size' => (int) $data['size'],
            'md5' => $data['md5'], 'sha1' => $data['sha1'], 'parts' => $parts];
        return $this->run(fn () => $this->success('直传任务已准备', $service->begin($request, $file, (int) $data['strategy_id'], $data['id'] ?? null)));
    }

    public function sign(Request $request, R2DirectUpload $service, string $id)
    {
        $data = $request->validate(['index' => 'required|integer|min:0|max:10']);
        return $this->run(fn () => $this->success('分片已授权', $service->sign($request, $id, (int) $data['index'])));
    }

    public function complete(Request $request, R2DirectUpload $service, string $id)
    {
        return $this->run(fn () => response($service->complete($request, $id)));
    }

    public function abort(Request $request, R2DirectUpload $service, string $id)
    {
        return $this->run(function () use ($request, $service, $id) {
            $service->abort($request, $id);
            return $this->success('直传任务已取消');
        });
    }
}
