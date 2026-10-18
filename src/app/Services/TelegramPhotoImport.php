<?php
namespace App\Services;

use App\Enums\GroupConfigKey;
use App\Enums\StrategyKey;
use App\Exceptions\UploadException;
use App\Filesystem\TelegramAdapter;
use App\Models\Image;
use App\Models\TelegramFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** One source post -> one photo album; actual Telegram bytes are verified before commit. */
class TelegramPhotoImport
{
    private function write(string $path, array $data): void
    {
        $tmp=$path.'.tmp';
        $json=json_encode($data, JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp,$json)!==strlen($json) || !rename($tmp,$path)) {
            @unlink($tmp); throw new RuntimeException('无法保存照片组状态');
        }
    }
    public function store(Request $request, ImageService $service): array
    {
        $user=$request->user();
        if (!$user) throw new UploadException('照片组导入需要登录');
        $request->validate([
            'strategy_id'=>'required|integer|min:1', 'album_id'=>'required|integer|min:1',
            'source_group'=>'required|string|regex:/^[0-9]+:[gs][0-9]+$/D|max:100',
            'files'=>'required|array|min:1|max:10', 'files.*'=>'required|file|max:8192',
        ]);
        $strategy=$user->group->strategies()->find($request->input('strategy_id'));
        if (!$strategy || $strategy->key!==StrategyKey::Telegram) throw new UploadException('照片组仅支持当前角色已绑定的TG策略');
        if (!$user->albums()->whereKey($request->input('album_id'))->exists()) throw new UploadException('指定相册不存在或不属于当前账号');
        $config=$user->group->configs;
        if ($config->get(GroupConfigKey::IsEnableScan) || $config->get(GroupConfigKey::IsEnableWatermark)
            || $config->get(GroupConfigKey::ImageSaveFormat) || (int)$config->get(GroupConfigKey::ImageSaveQuality)!==100) throw new UploadException('照片组角色需要质量100、不转换格式、关闭审查与水印');
        $adapter=$service->getAdapter($strategy);
        if (!$adapter instanceof TelegramAdapter) throw new UploadException('TG适配器不匹配');
        $files=array_values($request->file('files')); $sources=[];
        foreach ($files as $file) {
            $info=@getimagesize($file->getRealPath());
            if (!$info || ($info[2]??0)!==IMAGETYPE_JPEG || !in_array(strtolower($file->getClientOriginalExtension()),['jpg','jpeg'],true)
                || $info[0]+$info[1]>10000 || max($info[0],$info[1])/max(1,min($info[0],$info[1]))>20) {
                throw new UploadException('照片组仅接收符合TG尺寸限制的JPEG照片，不能静默改为文档');
            }
            $sources[]=['name'=>$file->getClientOriginalName(), 'md5'=>md5_file($file->getRealPath()),
                'sha1'=>sha1_file($file->getRealPath()), 'bytes'=>$file->getSize()];
        }
        $dir=storage_path('app/tg-photo-import');
        if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('无法创建照片组状态目录');
        $lock=fopen($dir.'/user-'.$user->id.'.lock','c');
        if (!$lock || !flock($lock, LOCK_EX|LOCK_NB)) {if ($lock) fclose($lock);throw new UploadException('该账号已有照片组导入任务');}
        $id=hash('sha256',json_encode([$user->id,$strategy->id,(int)$request->input('album_id'),$request->input('source_group')]));
        $path=$dir.'/'.$id.'.json'; $fingerprint=hash('sha256',json_encode($sources));
        $streams=[]; $sentIds=[]; $committed=false;
        try {
            if (is_file($path)) {
                $old=json_decode(file_get_contents($path),true);
                if (($old['fingerprint']??'')!==$fingerprint) throw new UploadException('相同源帖内容发生变化，停止重复发送');
                if (($old['phase']??'')!=='done') throw new UploadException('该照片组上次结果不确定或未完成，请人工核对，不自动重发');
                $keys=array_column($old['result']['images']??[], 'key');
                if (!$keys || count(array_unique($keys))!==count($keys)) throw new UploadException('照片组完成状态损坏，请人工核对');
                $remaining=Image::where('user_id',$user->id)->whereIn('key',$keys)->count();
                if ($remaining===count($keys)) return $old['result'];
                if ($remaining>0) throw new UploadException('原照片组只有部分图床记录被删除，停止重复发送');
                // All published records were deleted by the owner. Preserve the old completed
                // ledger for audit, then allow a fresh import under a new random namespace.
                if (!rename($path,$path.'.retired-'.bin2hex(random_bytes(8)))) throw new RuntimeException('无法备份已删除照片组状态');
            }
            $meta=['fingerprint'=>$fingerprint,'phase'=>'preparing','source_group'=>$request->input('source_group')];
            $this->write($path,$meta);
            $result=DB::transaction(function () use ($request,$service,$adapter,$files,$sources,&$streams,&$sentIds,&$meta,$path) {
                $images=[]; $namespace='tg-photo-import/'.bin2hex(random_bytes(16));
                foreach ($files as $file) {
                    $child=Request::create('/api/v1/upload/telegram-photos','POST',$request->only('strategy_id','album_id'),[],['file'=>$file]);
                    $child->setUserResolver(fn()=>$request->user());
                    $images[]=$service->store($child,function ($location,$in) use (&$streams,$file) {
                        $tmp=tmpfile(); if (!$tmp) throw new RuntimeException('无法暂存照片');
                        $streams[]=['stream'=>$tmp,'filename'=>$file->getClientOriginalName()];
                        if (stream_copy_to_stream($in,$tmp)!== $file->getSize()) throw new RuntimeException('照片暂存不完整');
                        rewind($tmp);
                    },$namespace);
                }
                // Persist uncertainty before the external call. A lost response must never trigger blind retry.
                $meta['phase']='sending';$meta['keys']=array_map(fn($i)=>$i->key,$images);$this->write($path,$meta);
                $messages=$adapter->sendPhotoBatch($streams);
                foreach ($messages as $message) if (isset($message['message_id'])) $sentIds[]=(int)$message['message_id'];
                $meta['message_ids']=$sentIds;$meta['phase']='sent';$this->write($path,$meta);
                if (count($messages)!==count($images)) throw new RuntimeException('TG照片组回包数量不符');
                $groupId=null; $outputs=[];
                foreach ($images as $n=>$image) {
                    $message=$messages[$n];$variants=$message['photo']??[];
                    if (!$variants || empty($message['message_id'])) throw new RuntimeException('TG未返回照片映射');
                    if (count($images)>1) {
                        if (empty($message['media_group_id'])) throw new RuntimeException('TG未返回媒体组');
                        $groupId ??= (string)$message['media_group_id'];
                        if ($groupId!==(string)$message['media_group_id']) throw new RuntimeException('TG媒体组不一致');
                    }
                    usort($variants,fn($a,$b)=>(($a['width']??0)*($a['height']??0))<=>(($b['width']??0)*($b['height']??0)));
                    $photo=end($variants);
                    if (empty($photo['file_id'])) throw new RuntimeException('TG未返回file_id');
                    TelegramFile::create(['strategy_id'=>$image->strategy_id,'path'=>$image->pathname,'file_id'=>$photo['file_id'],
                        'message_id'=>(int)$message['message_id'],'size'=>(int)($photo['file_size']??0),'mime_type'=>'image/jpeg']);
                    $stream=$adapter->readStream($image->pathname);$tmp=tmpfile();
                    if (!$tmp) {if(is_resource($stream))fclose($stream);throw new RuntimeException('无法验证TG照片');}
                    try {
                        $bytes=0;$md5=hash_init('md5');$sha1=hash_init('sha1');
                        while (!feof($stream)) {
                            $chunk=fread($stream,1048576);if($chunk===false || ($chunk===''&&!feof($stream)))throw new RuntimeException('TG照片读取失败');
                            $bytes+=strlen($chunk);if($bytes>10*1024*1024)throw new RuntimeException('TG照片超过校验上限');
                            hash_update($md5,$chunk);hash_update($sha1,$chunk);
                            if(fwrite($tmp,$chunk)!==strlen($chunk))throw new RuntimeException('TG照片校验暂存失败');
                        }
                        fflush($tmp);
                        $actual=@getimagesize(stream_get_meta_data($tmp)['uri']);
                        if (!$actual || $actual[2]!==IMAGETYPE_JPEG || !$bytes || (isset($photo['file_size'])&&(int)$photo['file_size']!==$bytes))throw new RuntimeException('TG实际照片格式或长度不符');
                        $image->forceFill(['md5'=>hash_final($md5),'sha1'=>hash_final($sha1),'size'=>$bytes/1024,
                            'width'=>$actual[0],'height'=>$actual[1],'mimetype'=>'image/jpeg'])->save();
                        $service->makeThumbnail($image,stream_get_meta_data($tmp)['uri']);
                        TelegramFile::where('strategy_id',$image->strategy_id)->where('path',$image->pathname)->update(['size'=>$bytes]);
                    } finally {if(is_resource($stream))fclose($stream);if(is_resource($tmp))fclose($tmp);}
                    $outputs[]=['key'=>$image->key,'album_id'=>$image->album_id,'links'=>['url'=>$image->url],
                        'md5'=>$image->md5,'sha1'=>$image->sha1,'size'=>$image->size,'source'=>$sources[$n]];
                }
                $user=$request->user();
                if ($user->images()->sum('size')>$user->capacity) throw new UploadException('TG照片处理后的总容量超出账号额度');
                return ['media_group_id'=>$groupId,'images'=>$outputs];
            });
            $committed=true;$meta['phase']='done';$meta['result']=$result;$this->write($path,$meta);
            return $result;
        } catch (Throwable $e) {
            if (!$committed && $sentIds) $adapter->discardPhotoMessages($sentIds);
            // Never delete unknown messages or existing channel posts. Unknown sends require manual reconciliation.
            throw $e;
        } finally {
            foreach ($streams as $item) if (is_resource($item['stream'])) fclose($item['stream']);
            flock($lock,LOCK_UN);fclose($lock);
        }
    }
}
