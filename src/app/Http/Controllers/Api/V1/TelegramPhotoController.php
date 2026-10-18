<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ImageService;
use App\Services\TelegramPhotoImport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TelegramPhotoController extends Controller
{
    public function store(Request $request, TelegramPhotoImport $import, ImageService $images): Response
    {
        return $this->success('照片组导入成功', $import->store($request,$images));
    }
}
