<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\ImageLoader;

use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\Contracts\ImageFormat;
use ImageColorAnalyzer\Contracts\ImageLoaderInterface;
use ImageColorAnalyzer\Contracts\ImageSource;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\Exception\InvalidImageException;
use ImageColorAnalyzer\Exception\UnsupportedImageException;

/**
 * OWNER: Developer A.
 *
 * Decodes sources with ext-gd, normalizing all formats to an immutable RGBA raster.
 */
final class GdImageLoader implements ImageLoaderInterface
{
    public function supports(ImageSource $source): bool
    {
        return in_array($source->detectedFormat(), [ImageFormat::PNG, ImageFormat::JPEG], true);
    }

    public function load(ImageSource $source): Raster
    {
        if (!$this->supports($source)) {
            throw new UnsupportedImageException('GD loader supports only PNG and JPEG images.');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new UnsupportedImageException('The GD extension is required to decode images.');
        }

        $bytes = $this->readAllBytes($source);
        $this->rejectUnsupportedJpeg($source, $bytes);

        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof \GdImage) {
            throw new InvalidImageException('GD could not decode the image bytes.');
        }

        try {
            $this->normalizeTruecolorWithAlpha($image);

            return $this->rasterFromGdImage($image);
        } finally {
            imagedestroy($image);
        }
    }

    private function readAllBytes(ImageSource $source): string
    {
        $stream = $source->stream();
        $bytes = stream_get_contents($stream);
        if ($bytes === false || $bytes === '') {
            throw new InvalidImageException('Image source is empty or unreadable.');
        }

        return $bytes;
    }

    private function rejectUnsupportedJpeg(ImageSource $source, string $bytes): void
    {
        if ($source->detectedFormat() !== ImageFormat::JPEG) {
            return;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new InvalidImageException('JPEG metadata could not be read.');
        }

        if (($info['channels'] ?? 3) === 4) {
            throw new UnsupportedImageException(
                'CMYK JPEG images require the optional Imagick loader; GD cannot decode them reliably.',
            );
        }
    }

    private function normalizeTruecolorWithAlpha(\GdImage $image): void
    {
        if (!imageistruecolor($image) && !imagepalettetotruecolor($image)) {
            throw new InvalidImageException('Unable to normalize palette image to truecolor.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    private function rasterFromGdImage(\GdImage $image): Raster
    {
        $width = imagesx($image);
        $height = imagesy($image);

        /** @var list<ColorRGBA> $pixels */
        $pixels = [];
        $hasAlpha = false;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = $this->readPixel($image, $x, $y);
                $pixels[] = $color;
                $hasAlpha = $hasAlpha || $color->a < 255;
            }
        }

        return new InMemoryRaster($width, $height, $pixels, $hasAlpha);
    }

    private function readPixel(\GdImage $image, int $x, int $y): ColorRGBA
    {
        $rgba = imagecolorat($image, $x, $y);
        if ($rgba === false) {
            throw new InvalidImageException("Unable to read pixel ({$x},{$y}) from GD image.");
        }
        $gdAlpha = ($rgba & 0x7F000000) >> 24;

        return new ColorRGBA(
            ($rgba >> 16) & 0xFF,
            ($rgba >> 8) & 0xFF,
            $rgba & 0xFF,
            (int) round((127 - $gdAlpha) * 255 / 127),
        );
    }
}
