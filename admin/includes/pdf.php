<?php
/**
 * admin/includes/pdf.php
 *
 * A small, self-contained PDF writer.
 *
 * Why hand-rolled instead of FPDF/TCPDF/Dompdf: this project is dropped into
 * an htdocs folder and imported through phpMyAdmin — there's no Composer step
 * and no guarantee the machine is even online. A library would have to be
 * vendored in anyway, and we only need five things: text, wrapped paragraphs,
 * rules, simple tables and photos. That fits in one file.
 *
 * What it supports:
 *   - A4 portrait, multiple pages, automatic page breaks
 *   - The three core Helvetica faces (no font embedding, so no licensing or
 *     file-size questions) with real glyph-width metrics for word wrapping
 *   - Filled rectangles and lines, for table banding and rules
 *   - JPEG images (DCTDecode). Anything else — PNG, WEBP, GIF — is converted
 *     to JPEG by GD on the way in, which also flattens transparency onto
 *     white so photos don't come out with black backgrounds.
 *
 * Coordinates: callers work in millimetre-ish points measured DOWN from the
 * top-left of the page, because that's how a document is actually read. The
 * flip into PDF's bottom-left origin happens in one place (self::yy()).
 */

class SimplePdf
{
    /** A4 in PostScript points. */
    public float $pageWidth  = 595.28;
    public float $pageHeight = 841.89;

    public float $marginLeft   = 46;
    public float $marginRight  = 46;
    public float $marginTop    = 46;
    public float $marginBottom = 52;

    /** Cursor: distance from the top edge of the current page. */
    private float $y = 0;

    private string $buffer = '';       // content stream of the page being built
    private array  $pages = [];        // finished content streams
    private array  $images = [];       // name => ['data','w','h']
    private int    $imageSeq = 0;

    /** Called right after each new page starts, for running headers. */
    private $headerFn = null;
    /** Called just before each page is closed, for page numbers. */
    private $footerFn = null;

    private array $meta = ['title' => 'OSAS Report', 'author' => 'OSAS Venue Reservation System'];

    public function __construct()
    {
        $this->startPage();
    }

    /* ==================================================================
       Metrics
       ================================================================== */

    /** Widths (per 1000 units) for ASCII 32-126. */
    private const W_REGULAR = [
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,
    ];
    private const W_BOLD = [
        278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
        975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
        333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
        611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,
    ];

    /** Rendered width of $text at $size, in points. */
    public function textWidth(string $text, float $size, string $style = ''): float
    {
        $table = str_contains($style, 'B') ? self::W_BOLD : self::W_REGULAR;
        $total = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $code = ord($text[$i]);
            $total += ($code >= 32 && $code <= 126) ? $table[$code - 32] : 500;
        }
        return $total * $size / 1000;
    }

    /* ==================================================================
       Page handling
       ================================================================== */

    /**
     * The first page is already open by the time a caller configures its
     * header, so run it immediately for that page — otherwise page 1 would
     * be the only one missing its banner.
     */
    public function setHeader(?callable $fn): void
    {
        $this->headerFn = $fn;
        if ($fn && $this->buffer === '') $fn($this);
    }
    public function setFooter(?callable $fn): void { $this->footerFn = $fn; }
    public function setMeta(string $title, string $author = ''): void
    {
        $this->meta['title'] = $title;
        if ($author !== '') $this->meta['author'] = $author;
    }

    public function contentWidth(): float
    {
        return $this->pageWidth - $this->marginLeft - $this->marginRight;
    }

    public function getY(): float { return $this->y; }
    public function setY(float $y): void { $this->y = $y; }
    public function moveY(float $delta): void { $this->y += $delta; }
    public function pageCount(): int { return count($this->pages) + 1; }

    private function startPage(): void
    {
        $this->buffer = '';
        $this->y = $this->marginTop;
        if ($this->headerFn) ($this->headerFn)($this);
    }

    private function closePage(): void
    {
        if ($this->footerFn) ($this->footerFn)($this, $this->pageCount());
        $this->pages[] = $this->buffer;
        $this->buffer = '';
    }

    public function addPage(): void
    {
        $this->closePage();
        $this->startPage();
    }

    /** Starts a new page if $needed points wouldn't fit below the cursor. */
    public function ensureSpace(float $needed): void
    {
        if ($this->y + $needed > $this->pageHeight - $this->marginBottom) {
            $this->addPage();
        }
    }

    /** Converts a from-the-top y into PDF's from-the-bottom y. */
    private function yy(float $fromTop): float
    {
        return $this->pageHeight - $fromTop;
    }

    /* ==================================================================
       Text and strings
       ================================================================== */

    /**
     * PDF core fonts don't speak UTF-8, and this system's content is full of
     * typographic quotes and en-dashes from copy-pasted event descriptions.
     * Fold the common ones down to ASCII rather than dropping them.
     */
    public static function ascii(?string $text): string
    {
        $text = (string) $text;
        $map = [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
            "\u{2026}" => '...', "\u{00A0}" => ' ', "\u{2022}" => '-',
            "\u{20B1}" => 'PHP ', "\u{00B0}" => ' deg', "\u{00D7}" => 'x',
            "\u{00E9}" => 'e', "\u{00F1}" => 'n', "\u{00C9}" => 'E', "\u{00D1}" => 'N',
        ];
        $text = strtr($text, $map);
        $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? $text;
        // Anything still non-ASCII would render as garbage in a core font.
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text;
        return trim($text);
    }

    /** Same as ascii() but keeps newlines, for multi-line free text. */
    public static function asciiMultiline(?string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $lines = array_map([self::class, 'ascii'], explode("\n", $text));
        return implode("\n", $lines);
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private static function fontRef(string $style): string
    {
        if (str_contains($style, 'B')) return '/F2';
        if (str_contains($style, 'I')) return '/F3';
        return '/F1';
    }

    /** '#1f5a3c' or [r,g,b] 0-255 => PDF "r g b" in 0-1. */
    private static function color($color): string
    {
        if (is_array($color)) {
            [$r, $g, $b] = $color;
        } else {
            $hex = ltrim((string) $color, '#');
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        return sprintf('%.3F %.3F %.3F', $r / 255, $g / 255, $b / 255);
    }

    /**
     * Draws one line of text at an absolute position. Does not move the cursor.
     * $align is 'left', 'right' or 'center' within [$x, $x + $width].
     */
    public function textAt(float $x, float $yFromTop, string $text, float $size = 10,
                           string $style = '', $color = '#1d1d1b',
                           ?float $width = null, string $align = 'left'): void
    {
        $text = self::ascii($text);
        if ($text === '') return;

        if ($width !== null && $align !== 'left') {
            $tw = $this->textWidth($text, $size, $style);
            if ($align === 'right')  $x = $x + $width - $tw;
            if ($align === 'center') $x = $x + ($width - $tw) / 2;
        }

        $this->buffer .= sprintf(
            "BT %s rg %s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            self::color($color), self::fontRef($style), $size,
            $x, $this->yy($yFromTop + $size * 0.82), self::escape($text)
        );
    }

    /** Writes one line at the cursor and advances past it. */
    public function line(string $text, float $size = 10, string $style = '',
                         $color = '#1d1d1b', float $spacing = 1.45, float $indent = 0): void
    {
        $this->ensureSpace($size * $spacing);
        $this->textAt($this->marginLeft + $indent, $this->y, $text, $size, $style, $color);
        $this->y += $size * $spacing;
    }

    /** Splits $text into lines that fit $maxWidth, breaking long words if needed. */
    public function wrap(string $text, float $maxWidth, float $size, string $style = ''): array
    {
        $out = [];
        foreach (explode("\n", self::asciiMultiline($text)) as $paragraph) {
            if (trim($paragraph) === '') { $out[] = ''; continue; }
            $line = '';
            foreach (preg_split('/\s+/', trim($paragraph)) as $word) {
                // A single word longer than the column (a URL, a long filename)
                // has to be cut mid-word or it would overflow the page.
                while ($this->textWidth($word, $size, $style) > $maxWidth) {
                    $cut = strlen($word);
                    while ($cut > 1 && $this->textWidth(substr($word, 0, $cut), $size, $style) > $maxWidth) {
                        $cut--;
                    }
                    if ($line !== '') { $out[] = $line; $line = ''; }
                    $out[] = substr($word, 0, $cut);
                    $word = substr($word, $cut);
                }
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if ($this->textWidth($candidate, $size, $style) <= $maxWidth) {
                    $line = $candidate;
                } else {
                    if ($line !== '') $out[] = $line;
                    $line = $word;
                }
            }
            if ($line !== '') $out[] = $line;
        }
        return $out;
    }

    /** Wrapped body text at the cursor. Breaks across pages as needed. */
    public function paragraph(string $text, float $size = 10, string $style = '',
                              $color = '#33332e', float $spacing = 1.5,
                              float $indent = 0, ?float $width = null): void
    {
        $width ??= $this->contentWidth() - $indent;
        foreach ($this->wrap($text, $width, $size, $style) as $line) {
            if ($line === '') { $this->y += $size * 0.6; continue; }
            $this->ensureSpace($size * $spacing);
            $this->textAt($this->marginLeft + $indent, $this->y, $line, $size, $style, $color);
            $this->y += $size * $spacing;
        }
    }

    /* ==================================================================
       Shapes
       ================================================================== */

    public function rect(float $x, float $yFromTop, float $w, float $h, $fill): void
    {
        $this->buffer .= sprintf(
            "%s rg %.2F %.2F %.2F %.2F re f\n",
            self::color($fill), $x, $this->yy($yFromTop + $h), $w, $h
        );
    }

    public function hline(float $x, float $yFromTop, float $w, $color = '#e6dcc3', float $thickness = 0.7): void
    {
        $this->buffer .= sprintf(
            "%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            self::color($color), $thickness, $x, $this->yy($yFromTop), $x + $w, $this->yy($yFromTop)
        );
    }

    /** Full-width rule at the cursor, with breathing room either side. */
    public function rule(float $gapBefore = 8, float $gapAfter = 10, $color = '#e6dcc3'): void
    {
        $this->y += $gapBefore;
        $this->ensureSpace(2);
        $this->hline($this->marginLeft, $this->y, $this->contentWidth(), $color);
        $this->y += $gapAfter;
    }

    /* ==================================================================
       Images
       ================================================================== */

    /**
     * Loads an image from disk and normalises it to a JPEG the PDF can embed.
     * Returns null (rather than throwing) when GD is missing or the file is
     * unreadable, so a report with an unloadable photo still exports.
     */
    public static function loadImage(string $path, int $maxDimension = 1100): ?array
    {
        if (!is_file($path) || !function_exists('imagecreatefromstring')) return null;

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return null;

        $src = @imagecreatefromstring($raw);
        if (!$src) return null;

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 1 || $h < 1) { imagedestroy($src); return null; }

        $scale = min(1, $maxDimension / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        // Always redraw onto an opaque white canvas: PNG/WEBP transparency
        // would otherwise composite to black once flattened into JPEG.
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        ob_start();
        imagejpeg($dst, null, 82);
        $data = ob_get_clean();
        imagedestroy($dst);

        if (!$data) return null;
        return ['data' => $data, 'w' => $nw, 'h' => $nh];
    }

    /** Places a loaded image. Returns the height used, in points. */
    public function drawImage(array $image, float $x, float $yFromTop, float $boxW, float $boxH): float
    {
        $ratio = $image['w'] / $image['h'];
        $w = $boxW;
        $h = $w / $ratio;
        if ($h > $boxH) { $h = $boxH; $w = $h * $ratio; }

        $name = 'Im' . (++$this->imageSeq);
        $this->images[$name] = $image;

        // Centre it inside the box so a portrait photo in a landscape slot
        // doesn't sit awkwardly against the left edge.
        $ox = $x + ($boxW - $w) / 2;

        $this->buffer .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
            $w, $h, $ox, $this->yy($yFromTop + $h), $name
        );
        return $h;
    }

    /* ==================================================================
       Output
       ================================================================== */

    public function output(): string
    {
        $this->closePage();

        $pageCount = count($this->pages);
        $firstPageObj = 3;
        $fontObj = $firstPageObj + 2 * $pageCount;
        $imageObjStart = $fontObj + 3;

        // Shared resource dictionary — same fonts and images on every page,
        // which keeps the file small and the assembly simple.
        $xobjects = '';
        $i = 0;
        foreach (array_keys($this->images) as $name) {
            $xobjects .= sprintf('/%s %d 0 R ', $name, $imageObjStart + $i);
            $i++;
        }
        $resources = '<< /Font << /F1 ' . $fontObj . ' 0 R /F2 ' . ($fontObj + 1)
            . ' 0 R /F3 ' . ($fontObj + 2) . " 0 R >>"
            . ($xobjects !== '' ? " /XObject << $xobjects>>" : '')
            . ' /ProcSet [/PDF /Text /ImageC] >>';

        $objects = [];

        $kids = [];
        for ($p = 0; $p < $pageCount; $p++) {
            $kids[] = ($firstPageObj + 2 * $p) . ' 0 R';
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';

        for ($p = 0; $p < $pageCount; $p++) {
            $pageNum = $firstPageObj + 2 * $p;
            $contentNum = $pageNum + 1;
            $objects[$pageNum] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>',
                $this->pageWidth, $this->pageHeight, $resources, $contentNum
            );
            $stream = $this->pages[$p];
            $objects[$contentNum] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $objects[$fontObj]     = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontObj + 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[$fontObj + 2] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';

        $i = 0;
        foreach ($this->images as $img) {
            $objects[$imageObjStart + $i] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB "
                . "/BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $img['w'], $img['h'], strlen($img['data']), $img['data']
            );
            $i++;
        }

        $infoObj = $imageObjStart + count($this->images);
        $objects[$infoObj] = sprintf(
            '<< /Title (%s) /Author (%s) /Producer (OSAS Venue Reservation System) /CreationDate (D:%s) >>',
            self::escape(self::ascii($this->meta['title'])),
            self::escape(self::ascii($this->meta['author'])),
            date('YmdHis')
        );

        // ---- Assemble, recording byte offsets for the cross-reference table ----
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $maxObj = max(array_keys($objects));

        for ($n = 1; $n <= $maxObj; $n++) {
            $offsets[$n] = strlen($pdf);
            $body = $objects[$n] ?? '<< >>'; // no gaps allowed in an xref table
            $pdf .= "$n 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxObj + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $maxObj; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF",
            $maxObj + 1, $infoObj, $xrefPos
        );

        return $pdf;
    }

    /** Sends the PDF as a download and stops. */
    public function download(string $filename): void
    {
        $pdf = $this->output();
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'report.pdf';

        while (ob_get_level() > 0) ob_end_clean(); // drop any stray output first
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdf;
        exit;
    }
}
