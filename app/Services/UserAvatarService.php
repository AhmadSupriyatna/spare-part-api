<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Same normalize-to-square-WebP treatment as part photos (PartImageService),
 * just a smaller footprint since this is only ever shown as a small avatar.
 */
class UserAvatarService
{
    private const DIMENSION = 300;

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

        $path = 'avatars/'.Str::uuid()->toString().'.webp';
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
