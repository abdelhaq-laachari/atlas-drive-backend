<?php

namespace App\Support;

use RuntimeException;

/**
 * Phone photos (3-5 MB) → readable JPEG of ~0.3 MB.
 * Re-drawing the picture also drops everything hidden in the file (EXIF data such as the GPS
 * location, or code disguised as an image).
 */
class ImageShrinker
{
    public const MAX_SIDE = 1600;

    public const QUALITY = 80;

    public static function toJpeg(string $path): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($path));
        if ($source === false) {
            throw new RuntimeException('Not a readable image.');
        }

        $source = self::applyPhoneRotation($source, $path);

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        // White background, so transparent PNGs don't turn black in JPEG
        $image = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($image, null, self::QUALITY);

        return (string) ob_get_clean();
    }

    /**
     * Phones save photos sideways with a "rotate me" note (EXIF Orientation); apply it,
     * because the re-saved JPEG no longer carries the note.
     */
    private static function applyPhoneRotation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (@exif_read_data($path) ?: [])['Orientation'] ?? 1;

        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }
}
