<?php

namespace App\Services\Images;

/**
 * Makes customer photos small enough for the size they are actually shown at.
 *
 * A phone photo is often 3-5 MB and 4000 pixels wide, but a profile picture is
 * never shown bigger than 96 pixels (288 on the sharpest phone screens). This
 * shrinks the picture to what is needed, turns it the right way up (phones
 * store "rotate me" as a note instead of rotating the pixels, and the note is
 * lost when the picture is re-saved), drops hidden details such as the GPS
 * location the camera saved, and saves it as WebP, a modern format every
 * current browser shows that is far smaller than JPEG/PNG at the same look.
 *
 * Uses PHP's built-in GD image library, so no new package is needed. When GD
 * (or its WebP support) is missing, or the picture is an animation, or the
 * result would not actually be smaller, it returns null and the caller keeps
 * the original file exactly as before: nothing ever gets worse.
 */
class ImageOptimizer
{
    /** WebP quality (0-100). 82 looks the same as the original to the eye at these sizes. */
    public const QUALITY = 82;

    /** Pictures bigger than this many pixels are left alone so a huge file can't use up the server's memory. */
    private const MAX_PIXELS = 50_000_000;

    public function available(): bool
    {
        return function_exists('imagewebp')
            && function_exists('imagecreatefromstring')
            && (imagetypes() & IMG_WEBP);
    }

    /**
     * A square profile picture: the middle of the photo, cut to a square (the
     * same part every page already shows, since they all crop to a circle or
     * square) and no bigger than $size pixels each way.
     */
    public function square(string $bytes, int $size = 320): ?string
    {
        return $this->process($bytes, function (int $w, int $h) use ($size): array {
            $side = min($w, $h);
            $target = min($side, $size);

            return [(int) floor(($w - $side) / 2), (int) floor(($h - $side) / 2), $side, $side, $target, $target];
        });
    }

    /** The whole photo, uncropped, shrunk so its longest side is at most $maxSide pixels. */
    public function fit(string $bytes, int $maxSide = 1600): ?string
    {
        return $this->process($bytes, function (int $w, int $h) use ($maxSide): array {
            $scale = min(1, $maxSide / max($w, $h));

            return [0, 0, $w, $h, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        });
    }

    /**
     * @param  callable(int, int): array{0:int,1:int,2:int,3:int,4:int,5:int}  $box  source x, y, width, height, then output width, height
     */
    private function process(string $bytes, callable $box): ?string
    {
        if (! $this->available() || $bytes === '') {
            return null;
        }

        $info = @getimagesizefromstring($bytes);

        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return null;
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS || $this->isAnimated($bytes, $info[2])) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        try {
            if ($info[2] === IMAGETYPE_JPEG) {
                $image = $this->upright($image, $this->jpegOrientation($bytes));
            }

            [$sx, $sy, $sw, $sh, $dw, $dh] = $box(imagesx($image), imagesy($image));

            $out = imagecreatetruecolor($dw, $dh);
            // Keep see-through parts of a PNG see-through.
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
            imagecopyresampled($out, $image, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

            ob_start();
            $ok = imagewebp($out, null, self::QUALITY);
            $webp = (string) ob_get_clean();
            imagedestroy($out);

            if (! $ok || $webp === '' || strlen($webp) >= strlen($bytes)) {
                return null;
            }

            return $webp;
        } finally {
            imagedestroy($image);
        }
    }

    /** Animated GIFs/WebPs would lose their movement, so they are kept as they are. */
    private function isAnimated(string $bytes, int $type): bool
    {
        if ($type === IMAGETYPE_GIF) {
            // Each frame starts with a "graphic control" block; more than one means animation.
            return substr_count($bytes, "\x21\xF9\x04") > 1;
        }

        if ($type === IMAGETYPE_WEBP) {
            return str_contains(substr($bytes, 0, 64), 'ANIM') || str_contains($bytes, 'ANMF');
        }

        return false;
    }

    /** Apply the camera's "rotate me" note to the pixels themselves. */
    private function upright(\GdImage $image, int $orientation): \GdImage
    {
        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated === false) {
            return $image;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        if ($rotated !== $image) {
            imagedestroy($image);
        }

        return $rotated;
    }

    /**
     * The EXIF orientation number (1 = already upright) of a JPEG. Read by
     * hand so it works even on servers without PHP's exif extension.
     */
    public function jpegOrientation(string $bytes): int
    {
        $len = strlen($bytes);
        $pos = 2;

        while ($pos + 4 <= $len && $bytes[$pos] === "\xFF") {
            $marker = ord($bytes[$pos + 1]);
            $size = unpack('n', substr($bytes, $pos + 2, 2))[1];

            if ($marker === 0xE1 && substr($bytes, $pos + 4, 6) === "Exif\0\0") {
                return $this->tiffOrientation(substr($bytes, $pos + 10, $size - 8));
            }

            if ($marker === 0xDA) {
                break; // picture data starts; no EXIF block came before it
            }

            $pos += 2 + $size;
        }

        return 1;
    }

    private function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        $big = substr($tiff, 0, 2) === 'MM';
        $short = fn (int $at) => unpack($big ? 'n' : 'v', substr($tiff, $at, 2))[1] ?? 0;
        $long = fn (int $at) => unpack($big ? 'N' : 'V', substr($tiff, $at, 4))[1] ?? 0;

        $ifd = $long(4);

        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }

        $entries = $short($ifd);

        for ($i = 0; $i < $entries; $i++) {
            $at = $ifd + 2 + $i * 12;

            if ($at + 12 > strlen($tiff)) {
                break;
            }

            if ($short($at) === 0x0112) {
                $value = $short($at + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
