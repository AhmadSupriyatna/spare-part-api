<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Unlike part photos (cropped to a fixed 500x500 square), a logo keeps its
 * own aspect ratio — it's only scaled down if it's larger than needed for a
 * printed QR label, then re-encoded as WebP for consistency.
 */
class CompanyLogoService
{
    private const MAX_DIMENSION = 400;

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

        $path = 'company/'.Str::uuid()->toString().'.webp';
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
