<?php
declare(strict_types=1);

/**
 * Official stamp generated around the company logo.
 *
 * Draws a round seal (double outer ring, company name along the top arc, tagline along the
 * bottom arc, stars between them, logo in the centre) as a transparent PNG with GD, so the
 * same image works in the browser and in Dompdf PDFs. Files are cached by a hash of their
 * inputs, so changing the name, tagline, colours or logo produces a new stamp automatically.
 */

const UH_STAMP_DIR = 'assets/img/uploads/stamps';
const UH_STAMP_SIZE = 600;      // output pixels
const UH_STAMP_SUPERSAMPLE = 3; // drawn larger then downscaled for smooth edges

if (!function_exists('uh_stamp_color')) {
    function uh_stamp_color(array $cfg, string $key, string $default): string
    {
        $value = (string)($cfg[$key] ?? '');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $default;
    }
}

if (!function_exists('uh_stamp_upper')) {
    function uh_stamp_upper(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    }
}

if (!function_exists('uh_stamp_texts')) {
    /** @return array{top:string,bottom:string} */
    function uh_stamp_texts(array $cfg): array
    {
        $top = uh_stamp_upper((string)($cfg['company_name'] ?? ''));
        $bottom = uh_stamp_upper((string)($cfg['stamp_bottom_text'] ?? ''));
        if ($bottom === '') {
            $bottom = uh_stamp_upper((string)($cfg['company_tagline'] ?? ''));
        }
        return [
            'top'    => $top !== '' ? $top : 'OFFICIAL STAMP',
            'bottom' => $bottom !== '' ? $bottom : 'OFFICIAL STAMP',
        ];
    }
}

if (!function_exists('uh_stamp_font')) {
    function uh_stamp_font(string $baseDir): ?string
    {
        foreach ([
            $baseDir . '/assets/fonts/DejaVuSans-Bold.ttf',
            $baseDir . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
        ] as $font) {
            if (is_file($font)) {
                return $font;
            }
        }
        return null;
    }
}

if (!function_exists('uh_stamp_logo_file')) {
    function uh_stamp_logo_file(array $cfg, string $baseDir): ?string
    {
        $assetsRoot = realpath($baseDir . '/assets/img');
        $configured = trim((string)($cfg['company_logo'] ?? ''));
        $candidates = [];
        if ($configured !== '') {
            $candidates[] = realpath($baseDir . '/' . ltrim($configured, '/\\'));
        }
        $candidates[] = realpath($baseDir . '/assets/img/logo.png');

        foreach ($candidates as $path) {
            if ($path && $assetsRoot && str_starts_with($path, $assetsRoot . DIRECTORY_SEPARATOR) && is_file($path)) {
                return $path;
            }
        }
        return null;
    }
}

if (!function_exists('uh_stamp_generate')) {
    /**
     * Returns the stamp's path relative to $baseDir, generating it if needed, or null when
     * GD/FreeType or a font is unavailable.
     */
    function uh_stamp_generate(array $cfg, string $baseDir): ?string
    {
        if (!function_exists('imagettftext') || !function_exists('imagecreatetruecolor')) {
            return null;
        }
        $font = uh_stamp_font($baseDir);
        if ($font === null) {
            return null;
        }

        $texts   = uh_stamp_texts($cfg);
        $primary = uh_stamp_color($cfg, 'brand_primary_color', '#1a2a72');
        $accent  = uh_stamp_color($cfg, 'brand_accent_color', '#fc7f10');
        $logo    = uh_stamp_logo_file($cfg, $baseDir);

        $hash = substr(sha1(implode('|', [
            'v1', $texts['top'], $texts['bottom'], $primary, $accent,
            $logo ?? '', $logo ? (string)@filemtime($logo) : '',
        ])), 0, 16);
        $relative = UH_STAMP_DIR . '/stamp_' . $hash . '.png';
        $absolute = $baseDir . '/' . $relative;

        if (is_file($absolute)) {
            return $relative;
        }

        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return null;
        }

        $png = uh_stamp_render($texts['top'], $texts['bottom'], $primary, $accent, $logo, $font);
        if ($png === null || @file_put_contents($absolute, $png) === false) {
            return null;
        }

        // Remove stamps made from older settings.
        foreach (glob($dir . '/stamp_*.png') ?: [] as $old) {
            if ($old !== $absolute) {
                @unlink($old);
            }
        }

        return $relative;
    }
}

if (!function_exists('uh_stamp_resolve')) {
    /**
     * The stamp to print on documents: the uploaded image when stamp_mode is "uploaded" and the
     * file exists, otherwise the generated seal. Returns a path relative to $baseDir, or null.
     */
    function uh_stamp_resolve(array $cfg, string $baseDir): ?string
    {
        $uploaded = trim((string)($cfg['company_stamp'] ?? ''));
        $mode = trim((string)($cfg['stamp_mode'] ?? ''));
        if ($mode === '') {
            // Not chosen yet: keep an existing uploaded stamp, otherwise generate one.
            $mode = $uploaded !== '' ? 'uploaded' : 'generated';
        }

        if ($mode === 'uploaded' && $uploaded !== '' && is_file($baseDir . '/' . ltrim($uploaded, '/\\'))) {
            return $uploaded;
        }

        try {
            $generated = uh_stamp_generate($cfg, $baseDir);
        } catch (Throwable $e) {
            error_log('Stamp generation failed: ' . $e->getMessage());
            $generated = null;
        }
        if ($generated !== null) {
            return $generated;
        }

        // Generation unavailable: fall back to an uploaded stamp if there is one.
        return ($uploaded !== '' && is_file($baseDir . '/' . ltrim($uploaded, '/\\'))) ? $uploaded : null;
    }
}

if (!function_exists('uh_stamp_render')) {
    function uh_stamp_render(string $top, string $bottom, string $primaryHex, string $accentHex, ?string $logoFile, string $font): ?string
    {
        $k  = UH_STAMP_SUPERSAMPLE;
        $sz = UH_STAMP_SIZE * $k;
        $c  = $sz / 2;

        $im = imagecreatetruecolor($sz, $sz);
        if ($im === false) {
            return null;
        }
        imagesavealpha($im, true);
        imagealphablending($im, false);
        $clear = imagecolorallocatealpha($im, 255, 255, 255, 127);
        imagefilledrectangle($im, 0, 0, $sz, $sz, $clear);

        $rgb = static fn(string $hex): array => [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        [$pr, $pg, $pb] = $rgb($primaryHex);
        [$ar, $ag, $ab] = $rgb($accentHex);
        $ink    = imagecolorallocatealpha($im, $pr, $pg, $pb, 0);
        $accent = imagecolorallocatealpha($im, $ar, $ag, $ab, 0);

        // Radii as fractions of the half-size.
        $R = $c * 0.98;
        $ring = static function (float $outer, float $inner) use ($im, $c, $ink, $clear): void {
            imagealphablending($im, false);
            imagefilledellipse($im, (int)$c, (int)$c, (int)($outer * 2), (int)($outer * 2), $ink);
            imagefilledellipse($im, (int)$c, (int)$c, (int)($inner * 2), (int)($inner * 2), $clear);
        };
        $ring($R, $R * 0.955);                 // thick outer ring
        $ring($R * 0.935, $R * 0.92);          // thin ring inside it
        $bandOuter = $R * 0.92;
        $bandInner = $R * 0.66;
        $ring($bandInner, $bandInner - $R * 0.015); // ring around the centre
        $centerR = $bandInner - $R * 0.04;

        // Dotted circle just inside the centre ring.
        imagealphablending($im, true);
        $dotR = $centerR - $R * 0.005;
        $dots = 72;
        for ($i = 0; $i < $dots; $i++) {
            $t = 2 * M_PI * $i / $dots;
            $d = (int)($R * 0.012);
            imagefilledellipse($im, (int)($c + $dotR * cos($t)), (int)($c + $dotR * sin($t)), $d, $d, $ink);
        }

        // Arc text in the band between $bandInner and $bandOuter.
        $bandMid = ($bandInner + $bandOuter) / 2;
        $bandH   = $bandOuter - $bandInner;
        $chars = static fn(string $s): array => preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Measure a string at a font size, with tracking, as arc angle at radius $r (radians).
        $measure = static function (array $glyphs, float $size, float $r) use ($font): array {
            $widths = [];
            foreach ($glyphs as $g) {
                if ($g === ' ') {
                    $widths[] = $size * 0.45;
                    continue;
                }
                // Advance width (ink width makes narrow letters like "I" crowd their neighbours).
                $with = imagettfbbox($size, 0, $font, 'H' . $g . 'H');
                $without = imagettfbbox($size, 0, $font, 'HH');
                $widths[] = ($with && $without) ? (float)(($with[2] - $with[0]) - ($without[2] - $without[0])) : $size * 0.7;
            }
            $tracking = $size * 0.28;
            $total = array_sum($widths) + $tracking * max(0, count($widths) - 1);
            return [$widths, $tracking, $total / $r];
        };

        $capRatio = 0.73; // cap height / font size for DejaVu Sans Bold
        $fit = static function (string $text, float $maxSpan, float $r) use ($measure, $chars, $bandH, $capRatio): array {
            $glyphs = $chars($text);
            $size = ($bandH * 0.58) / $capRatio;
            do {
                [$widths, $tracking, $span] = $measure($glyphs, $size, $r);
                if ($span <= $maxSpan) {
                    break;
                }
                $size *= 0.95;
            } while ($size > $bandH * 0.18);
            return [$glyphs, $size, $widths, $tracking, $span];
        };

        // Top text reads left to right over the top: baseline below the band centre, letters pointing outward.
        [$tg, $tSize, $tW, $tTrack] = $fit($top, deg2rad(220), $bandMid);
        $tBase = $bandMid - ($tSize * $capRatio) / 2;
        [$tg, $tSize, $tW, $tTrack, $tSpan] = $fit($top, deg2rad(220), $tBase);
        $tBase = $bandMid - ($tSize * $capRatio) / 2;

        // Bottom text reads left to right along the bottom: letters pointing inward.
        $bSpanMax = deg2rad(min(120.0, 330.0 - rad2deg($tSpan)));
        [$bg, $bSize] = $fit($bottom, $bSpanMax, $bandMid);
        $bSize = min($bSize, $tSize * 0.85);
        $bBase = $bandMid + ($bSize * $capRatio) / 2;
        [$bWidths, $bTracking, $bSpan] = $measure($bg, $bSize, $bBase);

        $placeArc = static function (array $glyphs, array $widths, float $tracking, float $size, float $r, float $center, bool $topArc) use ($im, $c, $font, $ink): void {
            $total = (array_sum($widths) + $tracking * max(0, count($widths) - 1)) / $r;
            // Top arc: angle increases (clockwise on screen) left->right. Bottom arc: decreases.
            $theta = $topArc ? $center - $total / 2 : $center + $total / 2;
            foreach ($glyphs as $i => $g) {
                $w = $widths[$i];
                $mid = $topArc ? $theta + ($w / 2) / $r : $theta - ($w / 2) / $r;
                if ($g !== ' ') {
                    // Direction of writing at this point, and the GD rotation (degrees, counter-clockwise).
                    if ($topArc) {
                        $dx = -sin($mid); $dy = cos($mid);
                        $angle = -rad2deg($mid) - 90;
                    } else {
                        $dx = sin($mid); $dy = -cos($mid);
                        $angle = 90 - rad2deg($mid);
                    }
                    $x = $c + $r * cos($mid) - $dx * $w / 2;
                    $y = $c + $r * sin($mid) - $dy * $w / 2;
                    imagettftext($im, $size, $angle, (int)round($x), (int)round($y), $ink, $font, $g);
                }
                $step = ($w + $tracking) / $r;
                $theta = $topArc ? $theta + $step : $theta - $step;
            }
        };

        imagealphablending($im, true);
        $placeArc($tg, $tW, $tTrack, $tSize, $tBase, -M_PI / 2, true);
        $placeArc($bg, $bWidths, $bTracking, $bSize, $bBase, M_PI / 2, false);

        // Stars centred in the gaps between the two texts.
        $topEnd   = -M_PI / 2 + $tSpan / 2;
        $botStart = M_PI / 2 - $bSpan / 2;
        $rightGap = ($topEnd + $botStart) / 2;
        $leftGap  = M_PI - $rightGap;
        foreach ([$rightGap, $leftGap] as $t) {
            uh_stamp_star($im, $c + $bandMid * cos($t), $c + $bandMid * sin($t), $bandH * 0.32, $accent);
        }

        // Logo in the centre, or the company initials when the logo cannot be read (e.g. SVG).
        $logo = null;
        if ($logoFile !== null) {
            $data = @file_get_contents($logoFile);
            $logo = $data !== false ? @imagecreatefromstring($data) : false;
            $logo = $logo ?: null;
        }
        if ($logo !== null) {
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            // Largest box that fits inside the centre circle with a margin.
            $fitR = $centerR * 0.92;
            $diag = sqrt($lw * $lw + $lh * $lh);
            $scale = (2 * $fitR) / $diag;
            $dw = (int)round($lw * $scale);
            $dh = (int)round($lh * $scale);
            imagealphablending($im, true);
            imagecopyresampled($im, $logo, (int)round($c - $dw / 2), (int)round($c - $dh / 2), 0, 0, $dw, $dh, $lw, $lh);
            imagedestroy($logo);
        } else {
            $initials = '';
            foreach (preg_split('/\s+/', $top) ?: [] as $word) {
                if (preg_match('/^\p{L}/u', $word) && mb_strlen($initials, 'UTF-8') < 3) {
                    $initials .= function_exists('mb_substr') ? mb_substr($word, 0, 1, 'UTF-8') : $word[0];
                }
            }
            $size = $centerR * 0.55;
            $box = imagettfbbox($size, 0, $font, $initials);
            if ($box) {
                $w = $box[2] - $box[0];
                imagettftext($im, $size, 0, (int)($c - $w / 2 - $box[0]), (int)($c + $size * $capRatio / 2), $ink, $font, $initials);
            }
        }

        // Downscale for anti-aliased edges.
        $out = imagecreatetruecolor(UH_STAMP_SIZE, UH_STAMP_SIZE);
        imagesavealpha($out, true);
        imagealphablending($out, false);
        imagefilledrectangle($out, 0, 0, UH_STAMP_SIZE, UH_STAMP_SIZE, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, UH_STAMP_SIZE, UH_STAMP_SIZE, $sz, $sz);
        imagedestroy($im);

        ob_start();
        imagepng($out, null, 9);
        imagedestroy($out);
        $png = ob_get_clean();

        return $png !== false && $png !== '' ? $png : null;
    }
}

if (!function_exists('uh_stamp_star')) {
    function uh_stamp_star($im, float $cx, float $cy, float $r, int $color): void
    {
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $rad = $i % 2 === 0 ? $r : $r * 0.42;
            $t = -M_PI / 2 + $i * M_PI / 5;
            $points[] = (int)round($cx + $rad * cos($t));
            $points[] = (int)round($cy + $rad * sin($t));
        }
        imagefilledpolygon($im, $points, $color);
    }
}
