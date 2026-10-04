<?php
declare(strict_types=1);

/*
 * A small PDF writer with no dependencies: A4 pages, the standard Helvetica
 * fonts (WinAnsi, so £ and – work), text with wrapping, lines and filled
 * boxes. Enough for quotes and acceptance records on shared hosting.
 */

final class SimplePdf
{
    public const W = 595.28;
    public const H = 841.89;

    /** Helvetica and Helvetica-Bold widths (per 1000 units) for character codes 32–126. */
    private const WIDTHS = [
        false => [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
            278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611,
            722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584],
        true => [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
            333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611,
            722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
            611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584],
    ];
    /** A few common characters above 126 (WinAnsi codes). */
    private const EXTRA = [0x80 => 556, 0x91 => 222, 0x92 => 222, 0x93 => 333, 0x94 => 333, 0x95 => 350, 0x96 => 556, 0x97 => 1000, 0xA3 => 556, 0xA9 => 737, 0xB7 => 278];

    /** @var string[] content streams, one per page */
    private array $pages = [];
    /** @var array[] images drawn: ['dict' => image dictionary, 'data' => stream, 'smask' => ?[dict, data]] */
    private array $images = [];
    private int $page = -1;
    public float $y = 0;

    public function __construct(public readonly float $margin = 50, private string $title = '')
    {
    }

    /** Text in the PDF's font encoding (Windows-1252), with unsupported characters swapped for close ones. */
    public static function encode(string $s): string
    {
        $s = strtr($s, ['✔' => '', '✓' => '', '→' => '->', '←' => '<-', '…' => '...', "\t" => ' ']);
        $s = preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $s);
        $out = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return is_string($out) ? $out : preg_replace('/[^\x20-\x7E\n]/', '?', $s);
    }

    public function width(string $s, float $size, bool $bold = false): float
    {
        $w = 0;
        $enc = self::encode($s);
        for ($i = 0, $n = strlen($enc); $i < $n; $i++) {
            $c = ord($enc[$i]);
            $w += ($c >= 32 && $c <= 126) ? self::WIDTHS[$bold][$c - 32] : (self::EXTRA[$c] ?? 556);
        }
        return $w * $size / 1000;
    }

    /** Split text into lines no wider than $width (keeps the text's own line breaks). */
    public function wrap(string $s, float $width, float $size, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $s) as $para) {
            $line = '';
            foreach (preg_split('/ +/', trim($para)) as $word) {
                // Break words too long for a line on their own.
                while ($this->width($word, $size, $bold) > $width && mb_strlen($word) > 1) {
                    $cut = mb_strlen($word);
                    while ($cut > 1 && $this->width(mb_substr($word, 0, $cut), $size, $bold) > $width) {
                        $cut--;
                    }
                    if ($line !== '') {
                        $lines[] = $line;
                        $line = '';
                    }
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $try = $line === '' ? $word : "$line $word";
                if ($line !== '' && $this->width($try, $size, $bold) > $width) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->page = count($this->pages) - 1;
        $this->y = $this->margin;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function setPage(int $i): void
    {
        $this->page = $i;
    }

    /** Start a new page unless $height more points fit on this one. */
    public function need(float $height): bool
    {
        if ($this->page < 0 || $this->y + $height > self::H - $this->margin - 20) {
            $this->addPage();
            return true;
        }
        return false;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private static function rgb(array $c): string
    {
        return implode(' ', array_map(fn($v) => self::num($v / 255), $c));
    }

    /** Text with its top-left corner at ($x, $top); 'right' alignment puts its right edge at $x. */
    public function text(float $x, float $top, string $s, float $size = 10, bool $bold = false, array $color = [29, 41, 57], string $align = 'left'): void
    {
        if ($align === 'right') {
            $x -= $this->width($s, $size, $bold);
        } elseif ($align === 'center') {
            $x -= $this->width($s, $size, $bold) / 2;
        }
        $enc = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], self::encode($s));
        $baseline = self::H - $top - $size * 0.8;
        $this->pages[$this->page] .= 'BT ' . self::rgb($color) . ' rg /' . ($bold ? 'F2' : 'F1') . ' ' . self::num($size) . ' Tf '
            . self::num($x) . ' ' . self::num($baseline) . " Td ($enc) Tj ET\n";
    }

    /** Wrapped text from the current position; moves $y down. Returns the height used. */
    public function paragraph(float $x, float $width, string $s, float $size = 10, bool $bold = false, array $color = [29, 41, 57], float $leading = 1.35): float
    {
        $start = $this->y;
        foreach ($this->wrap($s, $width, $size, $bold) as $line) {
            $this->need($size * $leading);
            $this->text($x, $this->y, $line, $size, $bold, $color);
            $this->y += $size * $leading;
        }
        return $this->y - $start;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $color = [234, 236, 240], float $w = 0.75): void
    {
        $this->pages[$this->page] .= self::rgb($color) . ' RG ' . self::num($w) . ' w ' . self::num($x1) . ' ' . self::num(self::H - $y1) . ' m '
            . self::num($x2) . ' ' . self::num(self::H - $y2) . " l S\n";
    }

    public function rect(float $x, float $top, float $w, float $h, array $fill, ?array $stroke = null): void
    {
        $this->pages[$this->page] .= self::rgb($fill) . ' rg ' . ($stroke ? self::rgb($stroke) . ' RG 0.75 w ' : '')
            . self::num($x) . ' ' . self::num(self::H - $top - $h) . ' ' . self::num($w) . ' ' . self::num($h) . ' re ' . ($stroke ? 'B' : 'f') . "\n";
    }

    /**
     * Draw a PNG or JPEG image (file contents) with its top-left at ($x, $top), scaled to fit within
     * $maxW × $maxH keeping its shape. Returns [width, height] drawn, or null if the image can't be used.
     */
    public function image(string $bytes, float $x, float $top, float $maxW, float $maxH): ?array
    {
        $img = self::prepareImage($bytes);
        if (!$img) {
            return null;
        }
        $scale = min($maxW / $img['w'], $maxH / $img['h']);
        [$w, $h] = [$img['w'] * $scale, $img['h'] * $scale];
        $this->images[] = $img;
        $name = 'Im' . count($this->images);
        $this->pages[$this->page] .= 'q ' . self::num($w) . ' 0 0 ' . self::num($h) . ' ' . self::num($x) . ' ' . self::num(self::H - $top - $h) . " cm /$name Do Q\n";
        return [$w, $h];
    }

    /** Turn a PNG or JPEG into a PDF image (with a soft mask for PNG transparency). Null if unsupported. */
    public static function prepareImage(string $bytes): ?array
    {
        if (str_starts_with($bytes, "\xFF\xD8")) {
            $info = @getimagesizefromstring($bytes);
            if (!$info) {
                return null;
            }
            $space = match ($info['channels'] ?? 3) { 1 => '/DeviceGray', 4 => '/DeviceCMYK', default => '/DeviceRGB' };
            return ['w' => $info[0], 'h' => $info[1], 'data' => $bytes, 'smask' => null,
                'dict' => "/Type /XObject /Subtype /Image /Width {$info[0]} /Height {$info[1]} /ColorSpace $space /BitsPerComponent 8 /Filter /DCTDecode"
                    . ($space === '/DeviceCMYK' ? ' /Decode [1 0 1 0 1 0 1 0]' : '')];
        }
        if (!str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return null;
        }
        // Read the chunks.
        $pos = 8;
        $idat = $palette = $trns = '';
        $hdr = null;
        while ($pos + 8 <= strlen($bytes)) {
            $len = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            $chunk = substr($bytes, $pos + 8, $len);
            $pos += 12 + $len;
            match ($type) {
                'IHDR' => $hdr = unpack('Nw/Nh/Cdepth/Ctype/Ccomp/Cfilter/Cinterlace', $chunk),
                'PLTE' => $palette = $chunk,
                'tRNS' => $trns = $chunk,
                'IDAT' => $idat .= $chunk,
                default => null,
            };
            if ($type === 'IEND') {
                break;
            }
        }
        if (!$hdr || $hdr['interlace'] !== 0 || !in_array($hdr['depth'], [8, 16], true) && !($hdr['type'] === 3 && $hdr['depth'] <= 8)) {
            return null;
        }
        [$w, $h, $type, $depth] = [$hdr['w'], $hdr['h'], $hdr['type'], $hdr['depth']];
        $raw = @gzuncompress($idat);
        if ($raw === false) {
            return null;
        }
        // Undo the PNG row filters.
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$type] ?? 0;
        if (!$channels) {
            return null;
        }
        $bpp = max(1, (int)($channels * $depth / 8));
        $rowLen = (int)ceil($w * $channels * $depth / 8);
        $prev = str_repeat("\0", $rowLen);
        $rgb = $alpha = '';
        $gray = $type === 0 || $type === 4;
        for ($y = 0, $o = 0; $y < $h; $y++) {
            $filter = ord($raw[$o] ?? "\0");
            $line = substr($raw, $o + 1, $rowLen);
            $o += $rowLen + 1;
            $out = '';
            for ($i = 0; $i < $rowLen; $i++) {
                $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
                $b = ord($prev[$i]);
                $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                $v = ord($line[$i] ?? "\0");
                $v += match ($filter) {
                    1 => $a, 2 => $b, 3 => intdiv($a + $b, 2),
                    4 => (function () use ($a, $b, $c) { $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c); })(),
                    default => 0,
                };
                $out .= chr($v & 0xFF);
            }
            $prev = $out;
            // Split into colour and alpha (8 bits per sample).
            if ($type === 3) {
                for ($px = 0; $px < $w; $px++) {
                    $bit = $px * $depth;
                    $idx = (ord($out[intdiv($bit, 8)]) >> (8 - $depth - $bit % 8)) & ((1 << $depth) - 1);
                    $rgb .= substr($palette, $idx * 3, 3) ?: "\0\0\0";
                    $alpha .= $idx < strlen($trns) ? $trns[$idx] : "\xFF";
                }
                continue;
            }
            $step = $depth / 8;
            for ($px = 0; $px < $w; $px++) {
                $base = $px * $channels * $step;
                $sample = fn($k) => $out[(int)($base + $k * $step)]; // high byte of 16-bit samples
                $colour = $gray ? $sample(0) : $sample(0) . $sample(1) . $sample(2);
                $rgb .= $colour;
                if ($channels === 2 || $channels === 4) {
                    $alpha .= $sample($channels - 1);
                }
            }
        }
        $space = $gray ? '/DeviceGray' : '/DeviceRGB';
        $smask = $alpha !== '' && strspn($alpha, "\xFF") !== strlen($alpha)
            ? ['dict' => "/Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode", 'data' => gzcompress($alpha)]
            : null;
        return ['w' => $w, 'h' => $h, 'data' => gzcompress($rgb), 'smask' => $smask,
            'dict' => "/Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace $space /BitsPerComponent 8 /Filter /FlateDecode"];
    }

    public function output(): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Title (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], self::encode($this->title)) . ') /Producer (Telecom CRM) /CreationDate (D:' . date('YmdHis') . ') >>';
        $n = 6;
        $xobjects = '';
        foreach ($this->images as $i => $img) {
            $id = $n++;
            $smask = '';
            if ($img['smask']) {
                $m = $n++;
                $objects[$m] = '<< ' . $img['smask']['dict'] . ' /Length ' . strlen($img['smask']['data']) . " >>\nstream\n" . $img['smask']['data'] . "\nendstream";
                $smask = " /SMask $m 0 R";
            }
            $objects[$id] = '<< ' . $img['dict'] . $smask . ' /Length ' . strlen($img['data']) . " >>\nstream\n" . $img['data'] . "\nendstream";
            $xobjects .= ' /Im' . ($i + 1) . " $id 0 R";
        }
        foreach ($this->pages as $content) {
            $page = $n++;
            $stream = $n++;
            $kids[] = "$page 0 R";
            $objects[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::num(self::W) . ' ' . self::num(self::H) . '] '
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >>" . ($xobjects !== '' ? " /XObject <<$xobjects >>" : '') . " >> /Contents $stream 0 R >>";
            $objects[$stream] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "$i 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        return $pdf . "trailer\n<< /Size $count /Root 1 0 R /Info 5 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
