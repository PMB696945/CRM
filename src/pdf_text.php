<?php
declare(strict_types=1);

/*
 * Pulls the text out of a PDF without external tools: inflates the streams,
 * maps glyphs back to characters through each font's ToUnicode table, and
 * follows the text operators. Good enough for invoices produced by
 * accounting software; scanned (image-only) PDFs have no text to find.
 */

function pdf_extract_text(string $pdf): string
{
    // Every object, including ones packed inside object streams.
    $objects = [];
    if (preg_match_all('/(\d+)\s+\d+\s+obj\b(.*?)endobj/s', $pdf, $m, PREG_SET_ORDER)) {
        foreach ($m as $o) {
            $objects[(int)$o[1]] = $o[2];
        }
    }
    $streams = [];
    foreach ($objects as $num => $body) {
        if (($data = pdf_object_stream($body)) !== null) {
            $streams[$num] = $data;
            if (preg_match('#/Type\s*/ObjStm#', $body) && preg_match('#/N\s+(\d+)#', $body, $n) && preg_match('#/First\s+(\d+)#', $body, $f)) {
                $header = preg_split('/\s+/', trim(substr($data, 0, (int)$f[1])));
                for ($i = 0; $i + 1 < count($header) && $i / 2 < (int)$n[1]; $i += 2) {
                    $start = (int)$f[1] + (int)$header[$i + 1];
                    $end = isset($header[$i + 3]) ? (int)$f[1] + (int)$header[$i + 3] : strlen($data);
                    $objects[(int)$header[$i]] ??= substr($data, $start, $end - $start);
                }
            }
        }
    }

    // Fonts: resource name -> character map (from /ToUnicode), code width in bytes, and glyph widths.
    $resolve = fn(string $v) => preg_match('#^\s*(\d+)\s+\d+\s+R#', $v, $r) ? ($objects[(int)$r[1]] ?? '') : $v;
    $fontInfo = [];
    foreach ($objects as $num => $body) {
        if (!preg_match('#/Type\s*/Font\b#', $body) || preg_match('#/Subtype\s*/CIDFontType#', $body)) {
            continue;
        }
        $info = ['_bytes' => 1, '_w' => [], '_dw' => 500, '_map' => false];
        if (preg_match('#/ToUnicode\s+(\d+)\s+\d+\s+R#', $body, $t) && isset($streams[(int)$t[1]])) {
            $info = pdf_parse_cmap($streams[(int)$t[1]]) + $info;
            $info['_map'] = true;
        }
        if (preg_match('#/Subtype\s*/Type0#', $body)) {
            $info['_bytes'] = 2;
            $desc = preg_match('#/DescendantFonts\s*(\[[^\]]*\]|\d+\s+\d+\s+R)#', $body, $dd) ? $resolve(trim($dd[1], '[] ')) : '';
            if (preg_match('#/DW\s+(\d+)#', $desc, $dw)) {
                $info['_dw'] = (int)$dw[1];
            } else {
                $info['_dw'] = 1000;
            }
            if (preg_match('#/W\s*(\[(?:[^\[\]]|\[[^\]]*\])*\]|\d+\s+\d+\s+R)#', $desc, $w)) {
                $list = $resolve($w[1]);
                preg_match_all('/\[[^\]]*\]|-?[\d.]+/', trim($list, '[] '), $tok);
                $t = $tok[0];
                for ($i = 0; $i < count($t);) {
                    if (isset($t[$i + 1]) && $t[$i + 1][0] === '[') {
                        preg_match_all('/-?[\d.]+/', $t[$i + 1], $ws);
                        foreach ($ws[0] as $k => $wv) {
                            $info['_w'][(int)$t[$i] + $k] = (float)$wv;
                        }
                        $i += 2;
                    } elseif (isset($t[$i + 2])) {
                        for ($c = (int)$t[$i]; $c <= min((int)$t[$i + 1], (int)$t[$i] + 5000); $c++) {
                            $info['_w'][$c] = (float)$t[$i + 2];
                        }
                        $i += 3;
                    } else {
                        break;
                    }
                }
            }
        } elseif (preg_match('#/FirstChar\s+(\d+)#', $body, $fc) && preg_match('#/Widths\s*(\[[^\]]*\]|\d+\s+\d+\s+R)#', $body, $wd)) {
            preg_match_all('/-?[\d.]+/', $resolve($wd[1]), $ws);
            foreach ($ws[0] as $k => $wv) {
                $info['_w'][(int)$fc[1] + $k] = (float)$wv;
            }
        }
        $fontInfo[$num] = $info;
    }
    $resourceFonts = [];
    foreach ($objects as $body) {
        // "/Font << /F1 5 0 R ... >>", or "/Font 12 0 R" pointing at such a dictionary.
        if (preg_match('#/Font\s*<<(.*?)>>#s', $body, $fd) || (preg_match('#/Font\s+(\d+)\s+\d+\s+R#', $body, $ref) && isset($objects[(int)$ref[1]])
            && preg_match('#<<(.*)>>#s', $objects[(int)$ref[1]], $fd))) {
            if (preg_match_all('#/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R#', $fd[1], $fm, PREG_SET_ORDER)) {
                foreach ($fm as $f) {
                    if (isset($fontInfo[(int)$f[2]])) {
                        $resourceFonts[$f[1]] ??= $fontInfo[(int)$f[2]];
                    }
                }
            }
        }
    }

    $out = [];
    foreach ($streams as $data) {
        if (str_contains($data, 'begincmap') || !preg_match('/\bBT\b/', $data) || !preg_match('/T[Jj]/', $data)) {
            continue;
        }
        $out[] = pdf_content_text($data, $resourceFonts);
    }
    $text = implode("\n", array_filter($out, fn($t) => trim($t) !== ''));
    $text = preg_replace("/[ \t]+/", ' ', $text);
    return trim(preg_replace("/\n\s*\n+/", "\n", $text));
}

/** The decoded data of an object's stream, or null. */
function pdf_object_stream(string $body): ?string
{
    if (!preg_match('/^(.*?)stream\r?\n(.*?)\r?\n?endstream/s', $body, $s)) {
        return null;
    }
    [$dict, $data] = [$s[1], $s[2]];
    if (preg_match('#/Filter\s*(\[[^\]]*\]|/\w+)#', $dict, $f)) {
        if (str_contains($f[1], 'FlateDecode')) {
            $data = @gzuncompress($data) ?: (@gzinflate(substr($data, 2)) ?: (@gzinflate($data) ?: null));
            if ($data === null) {
                return null;
            }
        } elseif (!str_contains($f[1], 'ASCIIHexDecode')) {
            return null; // images and other encodings: no text
        }
        if (str_contains($f[1], 'ASCIIHexDecode')) {
            $data = (string)hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', $data));
        }
    }
    return $data;
}

/** A ToUnicode CMap as [code (hex, upper case) => UTF-8 text], plus '_bytes' => 1 or 2. */
function pdf_parse_cmap(string $cmap): array
{
    $map = ['_bytes' => 1];
    $hexToUtf8 = function (string $hex): string {
        $hex = preg_replace('/\s+/', '', $hex);
        $s = '';
        for ($i = 0; $i + 3 < strlen($hex); $i += 4) {
            $s .= hex2bin(substr($hex, $i, 4));
        }
        if (strlen($hex) === 2) {
            return chr(hexdec($hex));
        }
        return (string)@mb_convert_encoding($s, 'UTF-8', 'UTF-16BE');
    };
    if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $cs) && strlen($cs[1]) >= 4) {
        $map['_bytes'] = 2;
    }
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
        foreach ($blocks[1] as $b) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $b, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $p) {
                $map[strtoupper($p[1])] = $hexToUtf8($p[2]);
                if (strlen($p[1]) >= 4) {
                    $map['_bytes'] = 2;
                }
            }
        }
    }
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
        foreach ($blocks[1] as $b) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])/', $b, $ranges, PREG_SET_ORDER);
            foreach ($ranges as $r) {
                $lo = hexdec($r[1]);
                $hi = min(hexdec($r[2]), $lo + 2000);
                $width = strlen($r[1]);
                if ($width >= 4) {
                    $map['_bytes'] = 2;
                }
                if ($r[3][0] === '[') {
                    preg_match_all('/<([0-9A-Fa-f]+)>/', $r[3], $dst);
                    foreach ($dst[1] as $i => $d) {
                        $map[strtoupper(str_pad(dechex($lo + $i), $width, '0', STR_PAD_LEFT))] = $hexToUtf8($d);
                    }
                } else {
                    $start = hexdec(trim($r[3], '<>'));
                    $dw = strlen(trim($r[3], '<>'));
                    for ($c = $lo; $c <= $hi; $c++) {
                        $map[strtoupper(str_pad(dechex($c), $width, '0', STR_PAD_LEFT))] = $hexToUtf8(str_pad(dechex($start + $c - $lo), $dw, '0', STR_PAD_LEFT));
                    }
                }
            }
        }
    }
    return $map;
}

/** Text from one content stream, a line per text line. */
function pdf_content_text(string $data, array $fonts): string
{
    $out = '';
    $font = null;
    $size = 10.0;
    $len = strlen($data);
    $operands = [];
    $lineX = 0.0;   // start of the current line (from Tm / Td)
    $lineY = null;
    $endX = null;   // where the last text drawn ended
    $decode = function (string $bytes, float &$advance) use (&$font, &$size): string {
        $s = '';
        $step = $font['_bytes'] ?? 1;
        $width = 0.0;
        for ($i = 0; $i < strlen($bytes); $i += $step) {
            $chunk = substr($bytes, $i, $step);
            $code = strtoupper(bin2hex($chunk));
            $num = hexdec($code);
            $width += $font ? ($font['_w'][$num] ?? $font['_dw']) : 500;
            if ($font && $font['_map']) {
                $s .= $font[$code] ?? '';
            } else {
                $s .= (string)@mb_convert_encoding($step === 1 ? $chunk : chr($num & 0xFF), 'UTF-8', 'Windows-1252');
            }
        }
        $advance = $width / 1000 * $size;
        return $s;
    };
    // Text at a new position on the same line: a space if there's a visible gap.
    $moveTo = function (float $x, float $y) use (&$out, &$lineX, &$lineY, &$endX, &$size): void {
        if ($lineY !== null && abs($y - $lineY) > $size * 0.3) {
            $out .= "\n";
        } elseif ($endX !== null && $x - $endX > $size * 0.15) {
            $out .= ' ';
        }
        $lineX = $x;
        $lineY = $y;
        $endX = $x;
    };
    for ($i = 0; $i < $len;) {
        $c = $data[$i];
        if (ctype_space($c)) {
            $i++;
            continue;
        }
        if ($c === '%') { // comment
            $i = ($nl = strpos($data, "\n", $i)) === false ? $len : $nl + 1;
            continue;
        }
        if ($c === '(') { // literal string with nesting and escapes
            $depth = 1;
            $s = '';
            for ($i++; $i < $len && $depth > 0; $i++) {
                $ch = $data[$i];
                if ($ch === '\\' && $i + 1 < $len) {
                    $n = $data[++$i];
                    $esc = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f"];
                    if (isset($esc[$n])) {
                        $s .= $esc[$n];
                    } elseif (ctype_digit($n)) {
                        $oct = $n;
                        while (strlen($oct) < 3 && $i + 1 < $len && ctype_digit($data[$i + 1])) {
                            $oct .= $data[++$i];
                        }
                        $s .= chr(octdec($oct) & 0xFF);
                    } elseif ($n !== "\n" && $n !== "\r") {
                        $s .= $n;
                    }
                    continue;
                }
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')' && --$depth === 0) {
                    break;
                }
                $s .= $ch;
            }
            $i++;
            $operands[] = ['s', $s];
            continue;
        }
        if ($c === '<' && ($data[$i + 1] ?? '') !== '<') { // hex string
            $end = strpos($data, '>', $i);
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($data, $i + 1, $end === false ? 0 : $end - $i - 1));
            if (strlen($hex) % 2) {
                $hex .= '0';
            }
            $operands[] = ['s', (string)hex2bin($hex)];
            $i = $end === false ? $len : $end + 1;
            continue;
        }
        if ($c === '[') {
            $operands[] = ['['];
            $i++;
            continue;
        }
        if ($c === ']') {
            $arr = [];
            while ($operands && end($operands)[0] !== '[') {
                array_unshift($arr, array_pop($operands));
            }
            array_pop($operands);
            $operands[] = ['a', $arr];
            $i++;
            continue;
        }
        if ($c === '<' || $c === '>') { // dictionaries (inline images etc.): skip the brackets
            $i += 2;
            continue;
        }
        // A number, name or operator.
        preg_match('/\G[^\s()<>\[\]{}\/%]+|\G\/[^\s()<>\[\]{}\/%]*/', $data, $tok, 0, $i);
        $t = $tok[0] ?? $c;
        $i += max(1, strlen($t));
        if ($t[0] === '/' || is_numeric($t)) {
            $operands[] = $t[0] === '/' ? ['n', substr($t, 1)] : ['d', (float)$t];
            continue;
        }
        $num = fn(int $back) => (float)($operands[count($operands) - $back][1] ?? 0);
        switch ($t) {
            case 'Tf':
                $name = $operands[count($operands) - 2][1] ?? null;
                $font = is_string($name) ? ($fonts[$name] ?? null) : null;
                $size = abs($num(1)) ?: 10.0;
                break;
            case 'Tm':
                $moveTo($num(2), $num(1));
                break;
            case 'Td':
            case 'TD':
                $moveTo($lineX + $num(2), ($lineY ?? 0) + $num(1));
                break;
            case 'T*':
                $out .= "\n";
                $endX = null;
                break;
            case 'Tj':
            case "'":
            case '"':
                if ($t !== 'Tj') {
                    $out .= "\n";
                }
                $last = end($operands);
                if ($last && $last[0] === 's') {
                    $adv = 0.0;
                    $out .= $decode($last[1], $adv);
                    $endX = ($endX ?? $lineX) + $adv;
                }
                break;
            case 'TJ':
                $last = end($operands);
                foreach (($last && $last[0] === 'a') ? $last[1] : [] as $part) {
                    if ($part[0] === 's') {
                        $adv = 0.0;
                        $out .= $decode($part[1], $adv);
                        $endX = ($endX ?? $lineX) + $adv;
                    } elseif ($part[0] === 'd') {
                        if ($part[1] < -200) {
                            $out .= ' ';
                        }
                        $endX = ($endX ?? $lineX) - $part[1] / 1000 * $size;
                    }
                }
                break;
            case 'ET':
                $out .= "\n";
                $endX = null;
                $lineY = null;
                break;
        }
        $operands = [];
    }
    return $out;
}
