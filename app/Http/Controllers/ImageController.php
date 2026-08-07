<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// form requests
use App\Http\Requests\Images\GetBookImageRequest;
use App\Http\Requests\Images\GetKanjiImageRequest;

// services
use App\Services\ImageService;
use App\Services\SafeFilePathService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImageController extends Controller
{
    private $imageService;

    public function __construct(ImageService $imageService) {
        $this->imageService = $imageService;
    }
    
    public function getBookImage($fileName, GetBookImageRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        try {
            $imagePath = $this->imageService->getBookImage($userId, $language, $fileName);
        } catch (NotFoundHttpException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            Log::error('book_image_load_failed', [
                'exception' => $exception::class,
            ]);
            abort(500, 'Book image could not be loaded.');
        }
        
        return response()->file($imagePath);
    }

    public function getKanjiImage(
        $fileName,
        GetKanjiImageRequest $request,
        SafeFilePathService $files
    ) {
        $imagePath = $files->resolveExistingDirectChild(
            Storage::path('/images/kanjivg'),
            $fileName
        );
        return response()->file($imagePath);
    }
}
