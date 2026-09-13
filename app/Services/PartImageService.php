<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Every part photo is normalized to the same 500x500 footprint (cropped to
 * fill, not stretched) and re-encoded as WebP, so uploads of wildly
 * different sizes/formats end up small and visually consistent everywhere
 * they're displayed.
 */
class PartImageService
{
    private const DIMENSION = 500;

    private const QUALITY = 80;

    private readonly ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(Driver::class);
    }

    public function store(UploadedFile $file): string
    {
        $image = $this->manager->decodePath($file->getRealPath());
        $image->cover(self::DIMENSION, self::DIMENSION);

        $encoded = $image->encode(new WebpEncoder(quality: self::QUALITY));

        $path = 'parts/'.Str::uuid()->toString().'.webp';
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
