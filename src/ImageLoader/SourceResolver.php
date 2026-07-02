<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\ImageLoader;

use ImageColorAnalyzer\Contracts\ImageSource;
use ImageColorAnalyzer\Exception\InvalidImageException;
use InvalidArgumentException;

/**
 * Normalizes public input forms into a seekable, format-sniffed ImageSource.
 */
final class SourceResolver
{
    public function resolve(mixed $source): ImageSource
    {
        if ($source instanceof ImageSource) {
            return $source;
        }

        if ($source instanceof \GdImage) {
            return FileImageSource::fromBytes($this->encodeGdImage($source));
        }

        if (is_resource($source)) {
            if (get_resource_type($source) === 'gd') {
                return FileImageSource::fromBytes($this->encodeLegacyGdResource($source));
            }

            return FileImageSource::fromStream($source);
        }

        if (is_string($source)) {
            if (!str_contains($source, "\0") && is_file($source)) {
                return FileImageSource::fromPath($source);
            }

            return FileImageSource::fromBytes($source);
        }

        throw new InvalidArgumentException(
            'Source must be an ImageSource, a stream resource, raw image bytes, a GD image, or a file path.',
        );
    }

    private function encodeGdImage(\GdImage $image): string
    {
        ob_start();
        $encoded = imagepng($image);
        $bytes = ob_get_clean();

        if ($encoded === false || !is_string($bytes)) {
            throw new InvalidImageException('Unable to encode GD image source.');
        }

        return $bytes;
    }

    /**
     * @param resource $image
     */
    private function encodeLegacyGdResource($image): string
    {
        ob_start();
        /** @phpstan-ignore-next-line PHP 8.3 uses GdImage objects; this supports legacy callers. */
        $encoded = imagepng($image);
        $bytes = ob_get_clean();

        if ($encoded === false || !is_string($bytes)) {
            throw new InvalidImageException('Unable to encode GD image source.');
        }

        return $bytes;
    }
}
