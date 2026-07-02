<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\WhiteBackgroundCropper;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\Contracts\BoundingBox;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\Contracts\CropperInterface;
use ImageColorAnalyzer\Contracts\CropResult;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\Options\CropOptions;

/**
 * OWNER: Developer B.
 *
 * Border-inward crop: each row and column is classified by how much *content* it
 * holds, then the four edges advance inward to the first row/column carrying at
 * least `lineContentFraction` of content pixels. The smallest rectangle spanning
 * those becomes the crop box. Scanning inward from the borders (rather than
 * removing white globally) structurally protects legitimate white *inside* the
 * artwork.
 *
 * "Near-white" is judged in CIELAB (L* >= lightnessMin and chroma
 * sqrt(a*^2 + b*^2) <= chromaMax); transparent pixels count as background. Two
 * RGB fast-paths (obvious white, obviously colored) keep the Lab conversion off
 * the hot path for the common cases.
 */
final class WhiteBackgroundCropper implements CropperInterface
{
    public function __construct(private readonly ColorConverter $converter)
    {
    }

    public function crop(Raster $image, CropOptions $options): CropResult
    {
        $width = $image->width();
        $height = $image->height();

        $rowContent = array_fill(0, $height, 0);
        $colContent = array_fill(0, $width, 0);

        $index = 0;
        foreach ($image->pixels() as $pixel) {
            if (!$this->isBackground($pixel, $options)) {
                $rowContent[intdiv($index, $width)]++;
                $colContent[$index % $width]++;
            }
            $index++;
        }

        $rowThreshold = $options->lineContentFraction * $width;
        $colThreshold = $options->lineContentFraction * $height;

        $top = $this->firstQualifying($rowContent, $rowThreshold);
        $left = $this->firstQualifying($colContent, $colThreshold);

        // No row (equivalently, no column) clears the noise guard: nothing worth
        // cropping to, so hand back the whole image untouched.
        if ($top === null || $left === null) {
            return new CropResult($image, new BoundingBox(0, 0, $width, $height), false);
        }

        $bottom = $this->lastQualifying($rowContent, $rowThreshold);
        $right = $this->lastQualifying($colContent, $colThreshold);

        $box = new BoundingBox($left, $top, $right - $left + 1, $bottom - $top + 1);
        $wasCropped = $box->x !== 0 || $box->y !== 0 || $box->width !== $width || $box->height !== $height;

        return new CropResult($wasCropped ? $image->crop($box) : $image, $box, $wasCropped);
    }

    private function isBackground(ColorRGBA $pixel, CropOptions $options): bool
    {
        if ($pixel->isTransparent($options->alphaThreshold)) {
            return true;
        }

        $min = min($pixel->r, $pixel->g, $pixel->b);
        $max = max($pixel->r, $pixel->g, $pixel->b);

        // Fast paths: near-pure white is background; anything with a mid/low
        // channel is far too dark to reach the L* >= 95 default and is content.
        if ($min >= 250 && $max - $min <= 4) {
            return true;
        }
        if ($min < 200) {
            return false;
        }

        [$l, $a, $b] = $this->converter->rgbToLab($pixel);
        $chroma = sqrt($a * $a + $b * $b);

        return $l >= $options->lightnessMin && $chroma <= $options->chromaMax;
    }

    /**
     * @param list<int> $content per-line content-pixel counts
     */
    private function firstQualifying(array $content, float $threshold): ?int
    {
        foreach ($content as $index => $count) {
            if ($count >= $threshold && $count > 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<int> $content per-line content-pixel counts
     */
    private function lastQualifying(array $content, float $threshold): int
    {
        for ($index = count($content) - 1; $index >= 0; $index--) {
            if ($content[$index] >= $threshold && $content[$index] > 0) {
                return $index;
            }
        }

        return 0;
    }
}
