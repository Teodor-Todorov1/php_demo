<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ImageLoader;

use ImageColorAnalyzer\Contracts\ImageFormat;
use ImageColorAnalyzer\Exception\InvalidImageException;
use ImageColorAnalyzer\Exception\UnsupportedImageException;
use ImageColorAnalyzer\ImageLoader\FileImageSource;
use PHPUnit\Framework\TestCase;

final class FileImageSourceTest extends TestCase
{
    public function testDetectsPngFromMagicBytes(): void
    {
        $source = FileImageSource::fromStream($this->streamWith("\x89PNG\x0d\x0a\x1a\x0a"));

        self::assertSame(ImageFormat::PNG, $source->detectedFormat());
    }

    public function testDetectsJpegFromMagicBytes(): void
    {
        $source = FileImageSource::fromStream($this->streamWith("\xFF\xD8\xFF\xE0"));

        self::assertSame(ImageFormat::JPEG, $source->detectedFormat());
    }

    public function testRejectsUnknownFormat(): void
    {
        $this->expectException(UnsupportedImageException::class);
        FileImageSource::fromStream($this->streamWith('GIF89a'));
    }

    public function testCreatesSourceFromRawBytes(): void
    {
        $source = FileImageSource::fromBytes("\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\x00", 16));

        self::assertSame(ImageFormat::PNG, $source->detectedFormat());
        self::assertSame("\x89PNG\x0d\x0a\x1a\x0a", fread($source->stream(), 8));
    }

    public function testRejectsInvalidStreamArgument(): void
    {
        $this->expectException(InvalidImageException::class);

        FileImageSource::fromStream('not a stream');
    }

    /**
     * @return resource
     */
    private function streamWith(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        if (!is_resource($stream)) {
            self::fail('Unable to open a temporary stream.');
        }
        fwrite($stream, $bytes . str_repeat("\x00", 32));
        rewind($stream);

        return $stream;
    }
}
