<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\WhiteBackgroundCropper;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\ImageLoader\InMemoryRaster;
use ImageColorAnalyzer\Options\CropOptions;
use ImageColorAnalyzer\Tests\Support\SyntheticImageFactory;
use ImageColorAnalyzer\WhiteBackgroundCropper\WhiteBackgroundCropper;
use PHPUnit\Framework\TestCase;

final class WhiteBackgroundCropperTest extends TestCase
{
    private WhiteBackgroundCropper $cropper;

    protected function setUp(): void
    {
        $this->cropper = new WhiteBackgroundCropper(new ColorConverter());
    }

    public function testCropsSymmetricWhiteBorder(): void
    {
        $raster = SyntheticImageFactory::contentOnBorder(100, 100, 20, new ColorRGBA(255, 0, 0));

        $result = $this->cropper->crop($raster, new CropOptions());

        self::assertTrue($result->wasCropped);
        self::assertSame([20, 20, 60, 60], $this->box($result->boundingBox));
        self::assertSame(60, $result->raster->width());
        self::assertSame(60, $result->raster->height());
    }

    public function testKeepsInteriorWhite(): void
    {
        // 20px white margin, 60x60 red content, with a 20x20 white square in its center.
        $raster = $this->build(100, 100, static function (int $x, int $y): ColorRGBA {
            $inContent = $x >= 20 && $x < 80 && $y >= 20 && $y < 80;
            $inHole = $x >= 40 && $x < 60 && $y >= 40 && $y < 60;
            if ($inContent && !$inHole) {
                return new ColorRGBA(255, 0, 0);
            }

            return new ColorRGBA(255, 255, 255);
        });

        $result = $this->cropper->crop($raster, new CropOptions());

        // The bounding box spans the whole content; the interior white is not removed.
        self::assertSame([20, 20, 60, 60], $this->box($result->boundingBox));
        self::assertSame('#FFFFFF', $result->raster->pixelAt(20, 20)->toHex()); // center hole survives
    }

    public function testCropsNearWhiteBorderWithinTolerance(): void
    {
        $raster = SyntheticImageFactory::contentOnBorder(
            80,
            80,
            10,
            new ColorRGBA(0, 0, 200),
            new ColorRGBA(250, 250, 250), // near-white (scanning/compression)
        );

        $result = $this->cropper->crop($raster, new CropOptions());

        self::assertTrue($result->wasCropped);
        self::assertSame([10, 10, 60, 60], $this->box($result->boundingBox));
    }

    public function testFullyWhiteImageIsNotCropped(): void
    {
        $raster = SyntheticImageFactory::solid(40, 40, new ColorRGBA(255, 255, 255));

        $result = $this->cropper->crop($raster, new CropOptions());

        self::assertFalse($result->wasCropped);
        self::assertSame([0, 0, 40, 40], $this->box($result->boundingBox));
    }

    public function testNoMarginImageReturnsWholeImage(): void
    {
        $raster = SyntheticImageFactory::solid(30, 30, new ColorRGBA(12, 34, 56));

        $result = $this->cropper->crop($raster, new CropOptions());

        self::assertFalse($result->wasCropped);
        self::assertSame([0, 0, 30, 30], $this->box($result->boundingBox));
    }

    public function testTransparentMarginTreatedAsBackground(): void
    {
        $raster = $this->build(40, 40, static function (int $x, int $y): ColorRGBA {
            $inside = $x >= 8 && $x < 32 && $y >= 8 && $y < 32;

            return $inside ? new ColorRGBA(0, 128, 0) : new ColorRGBA(0, 0, 0, 0);
        }, true);

        $result = $this->cropper->crop($raster, new CropOptions());

        self::assertTrue($result->wasCropped);
        self::assertSame([8, 8, 24, 24], $this->box($result->boundingBox));
    }

    public function testNoiseGuardIgnoresStraySpecks(): void
    {
        // A 3x3 speck of content on white; a strict content fraction ignores it.
        $raster = $this->build(100, 100, static function (int $x, int $y): ColorRGBA {
            $speck = $x >= 0 && $x < 3 && $y >= 0 && $y < 3;

            return $speck ? new ColorRGBA(0, 0, 0) : new ColorRGBA(255, 255, 255);
        });

        $result = $this->cropper->crop($raster, new CropOptions(lineContentFraction: 0.05));

        self::assertFalse($result->wasCropped);
    }

    public function testOffCenterContentIsCroppedTightly(): void
    {
        $raster = $this->build(50, 50, static function (int $x, int $y): ColorRGBA {
            $inside = $x >= 5 && $x < 15 && $y >= 30 && $y < 45;

            return $inside ? new ColorRGBA(200, 0, 0) : new ColorRGBA(255, 255, 255);
        });

        $result = $this->cropper->crop($raster, new CropOptions());

        self::assertSame([5, 30, 10, 15], $this->box($result->boundingBox));
    }

    /**
     * @param callable(int,int):ColorRGBA $color
     */
    private function build(int $width, int $height, callable $color, bool $hasAlpha = false): Raster
    {
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixels[] = $color($x, $y);
            }
        }

        return new InMemoryRaster($width, $height, $pixels, $hasAlpha);
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function box(\ImageColorAnalyzer\Contracts\BoundingBox $box): array
    {
        return [$box->x, $box->y, $box->width, $box->height];
    }
}
