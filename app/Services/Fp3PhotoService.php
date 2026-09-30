<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * FP3 request/completion photos — unlike PartImageService, these are
 * evidence/documentation shots (not a square thumbnail), so this only scales
 * down anything wider than DIMENSION (preserving aspect ratio, never
 * upscales a smaller photo) and re-encodes as WebP for consistent size.
 */
class Fp3PhotoService
{
    private const MAX_DIMENSION = 1600;

    private const QUALITY = 80;

    private readonly ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(Driver::class);
    }

    public function store(UploadedFile $file): string
    {
        $image = $this->manager->decodePath($file->getRealPath());
        $image->scaleDown(self::MAX_DIMENSION, self::MAX_DIMENSION);

        $encoded = $image->encode(new WebpEncoder(quality: self::QUALITY));

        $path = 'fp3/'.Str::uuid()->toString().'.webp';
        Storage::disk('public')->put($path, (string) $encoded);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
