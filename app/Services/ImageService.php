<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use App\Models\Book;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImageService {
    public function __construct(private SafeFilePathService $files) {
    }

    /*
        Checks if the user is authorized to download the image,
        and returns the absolute file path, or throws an exception.
    */
    public function getBookImage($userId, $language, $fileName) {
        
        $book = Book
            ::where('user_id', $userId)
            ->where('language', $language)
            ->where('cover_image', $fileName)
            ->first();

        if (!$book && $fileName !== null) {
            throw new NotFoundHttpException('The file does not exist in the selected language.');
        }

        if (is_null($fileName)) {
            return $this->files->resolveExistingDirectChild(
                Storage::disk('default-files')->path('/images/book_images'),
                'default.svg'
            );
        } else {
            return $this->files->resolveExistingDirectChild(
                Storage::path('/images/book_images'),
                $fileName
            );
        }
    }
}
