<?php
namespace App\Http\Controllers;

use App\Enums\ImagePermission;
use App\Models\Image;
use App\Services\ArchivedImageOutput;
use App\Services\VideoMetadata;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

class VideoShareController extends Controller
{
    private function canManage(Request $request, Image $image): bool
    {
        $user = $request->user();
        return $user && ($user->is_adminer || ($image->user_id !== null && (int)$image->user_id === (int)$user->id));
    }

    private function find(Request $request): Image
    {
        $image = Image::where('key', $request->route('key'))->firstOrFail();
        abort_unless(VideoMetadata::isMedia($image->extension), 404);
        return $image;
    }

    private function readable(Request $request): Image
    {
        $image = $this->find($request);
        if (!$this->canManage($request, $image)) {
            abort_unless($image->permission === ImagePermission::Public && !$image->is_unhealthy, 404);
        }
        return $image;
    }

    public function show(Request $request)
    {
        $image = $this->readable($request);
        return response()->view('video.share', [
            'image'=>$image, 'canManage'=>$this->canManage($request, $image),
            'isAudio'=>VideoMetadata::isAudio($image->extension),
            'playable'=>in_array(strtolower($image->extension), ['mp4','mov','m4v','webm','m3u8','mp3','flac','wav','m4a','aac','ogg','opus'], true),
        ])->header('Cache-Control', 'private, no-store')
          ->header('X-Robots-Tag', 'noindex, nofollow')
          ->header('Referrer-Policy', 'no-referrer');
    }

    public function settings(Request $request)
    {
        $image = $this->find($request);
        abort_unless($this->canManage($request, $image), 403);
        $data = $request->validate(['download_enabled'=>'required|boolean', 'public_share'=>'sometimes|required|boolean']);
        // Only these validated settings are writable; never mass-assign the request.
        $image->video_download_enabled = (bool)$data['download_enabled'];
        if (array_key_exists('public_share', $data)) {
            $image->permission = $data['public_share'] ? ImagePermission::Public : ImagePermission::Private;
        }
        $image->save();
        return redirect()->route('video.share', ['key'=>$image->key])->with('video_saved', true);
    }

    public function download(Request $request, ArchivedImageOutput $output)
    {
        $image = $this->readable($request);
        // Ownership does not bypass the published download switch.
        abort_unless((bool)$image->video_download_enabled, 403, '此媒体未开放下载入口');
        $response = $output->response($request, $image);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            'attachment', $image->name, 'video.'.strtolower($image->extension)
        ));
        // Never cache this policy-dependent response, even on Range/HEAD/304.
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
