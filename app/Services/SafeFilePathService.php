<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SafeFilePathService
{
    public function resolveExistingDirectChild(string $root, string $fileName): string
    {
        if (
            $fileName === ''
            || $fileName === '.'
            || $fileName === '..'
            || str_contains($fileName, "\0")
            || str_contains($fileName, '/')
            || str_contains($fileName, '\\')
        ) {
            throw new NotFoundHttpException('File not found.');
        }

        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false || !is_dir($canonicalRoot)) {
            throw new NotFoundHttpException('File not found.');
        }

        $requestedPath = $canonicalRoot . DIRECTORY_SEPARATOR . $fileName;
        if (is_link($requestedPath)) {
            throw new NotFoundHttpException('File not found.');
        }

        $canonicalPath = realpath($requestedPath);
        if (
            $canonicalPath === false
            || !is_file($canonicalPath)
            || !$this->samePath(dirname($canonicalPath), $canonicalRoot)
        ) {
            throw new NotFoundHttpException('File not found.');
        }

        return $canonicalPath;
    }

    private function samePath(string $left, string $right): bool
    {
        $normalize = static fn (string $path): string => rtrim(
            str_replace('\\', '/', $path),
            '/',
        );

        $left = $normalize($left);
        $right = $normalize($right);

        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }
}
