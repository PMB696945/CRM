<?php
declare(strict_types=1);

/*
 * Word (.docx) contract templates: fill {{merge_fields}} and insert a table of
 * services where {{services_table}} appears. docx_to_html() turns a document
 * into simple HTML for the online signing page.
 *
 * Word often splits "{{customer_name}}" across several formatting runs, so any
 * paragraph containing a placeholder is rebuilt as a single run (keeping the
 * paragraph's style and the first run's formatting).
 */

function docx_require_zip(): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new IntegrationException('The PHP "zip" extension is needed for contract templates. Enable it in cPanel → Select PHP Version → Extensions.');
    }
}

/** Check an uploaded file is a real .docx. Returns an error message or null. */
function docx_validate(string $path): ?string
{
    docx_require_zip();
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return 'That file isn\'t a Word .docx document.';
    }
    $ok = $zip->locateName('word/document.xml') !== false;
    $zip->close();
    return $ok ? null : 'That file isn\'t a Word .docx document.';
}

/** Placeholders found in a template, e.g. ['customer_name', 'services_table']. */
function docx_placeholders(string $path): array
{
    docx_require_zip();
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }
    $found = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name)) {
            $text = strip_tags(str_replace('</w:p>', "\n", (string)$zip->getFromIndex($i)));
            preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $text, $m);
            array_push($found, ...$m[1]);
        }
    }
    $zip->close();
    return array_values(array_unique(array_map('strtolower', $found)));
}

/**
 * Fill a template. $fields maps placeholder => text (newlines become line
 * breaks); $tableRows fills {{services_table}}: list of [cells...] with the
 * first row as the header and an optional last row treated as a totals row.
 */
function docx_merge(string $templatePath, string $outPath, array $fields, array $tableRows = []): void
{
    docx_require_zip();
    if (!copy($templatePath, $outPath)) {
        throw new IntegrationException('Couldn\'t create the contract file. Check the storage folder is writable.');
    }
    $zip = new ZipArchive();
    if ($zip->open($outPath) !== true) {
        throw new IntegrationException('Couldn\'t open the contract template.');
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name)) {
            $xml = (string)$zip->getFromIndex($i);
            $zip->addFromString($name, docx_merge_xml($xml, $fields, $tableRows));
        }
    }
    $zip->close();
}

function docx_merge_xml(string $xml, array $fields, array $tableRows): string
{
    $fields = array_change_key_case($fields, CASE_LOWER);
    return preg_replace_callback('#<w:p\b[^>]*?(?:/>|>.*?</w:p>)#s', function ($m) use ($fields, $tableRows) {
        $p = $m[0];
        preg_match_all('#<w:t(?:\s[^>]*)?>(.*?)</w:t>#s', $p, $texts);
        $text = html_entity_decode(implode('', $texts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        if (!str_contains($text, '{{')) {
            return $p;
        }
        // A paragraph that is just {{services_table}} becomes a table.
        if (preg_match('/^\s*\{\{\s*services_table\s*\}\}\s*$/i', $text)) {
            return $tableRows ? docx_table($tableRows) : '<w:p/>';
        }
        $replaced = preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($f) use ($fields) {
            $key = strtolower($f[1]);
            return array_key_exists($key, $fields) ? (string)$fields[$key] : $f[0];
        }, $text);

        preg_match('#^<w:p\b[^>]*>#', $p, $open);
        preg_match('#<w:pPr>.*?</w:pPr>#s', $p, $pPr);
        preg_match('#<w:r\b[^>]*>\s*(<w:rPr>.*?</w:rPr>)#s', $p, $rPr);
        $runs = [];
        foreach (explode("\n", str_replace("\r", '', $replaced)) as $i => $line) {
            $runs[] = ($i ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . htmlspecialchars($line, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</w:t>';
        }
        return ($open[0] ?? '<w:p>') . ($pPr[0] ?? '') . '<w:r>' . ($rPr[1] ?? '') . implode('', $runs) . '</w:r></w:p>';
    }, $xml);
}

/** A simple bordered Word table. First row is bold (header); last row bold if $boldLast. */
function docx_table(array $rows, bool $boldLast = true): string
{
    $border = '<w:top w:val="single" w:sz="4" w:color="D0D5DD"/><w:left w:val="single" w:sz="4" w:color="D0D5DD"/><w:bottom w:val="single" w:sz="4" w:color="D0D5DD"/><w:right w:val="single" w:sz="4" w:color="D0D5DD"/><w:insideH w:val="single" w:sz="4" w:color="D0D5DD"/><w:insideV w:val="single" w:sz="4" w:color="D0D5DD"/>';
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>' . $border . '</w:tblBorders>'
        . '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>';
    $last = count($rows) - 1;
    foreach (array_values($rows) as $r => $cells) {
        $bold = $r === 0 || ($boldLast && $r === $last && $last > 0);
        $xml .= '<w:tr>';
        foreach ($cells as $c => $cell) {
            $shade = $r === 0 ? '<w:shd w:val="clear" w:color="auto" w:fill="F2F4F7"/>' : '';
            $align = ($c > 0 && $r > 0 && preg_match('/^[£$€\-\d]/u', (string)$cell)) ? '<w:jc w:val="right"/>' : '';
            $xml .= '<w:tc><w:tcPr>' . $shade . '</w:tcPr><w:p><w:pPr><w:spacing w:before="0" w:after="0"/>' . $align . '</w:pPr><w:r>'
                . ($bold ? '<w:rPr><w:b/></w:rPr>' : '') . '<w:t xml:space="preserve">' . htmlspecialchars((string)$cell, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p></w:tc>';
        }
        $xml .= '</w:tr>';
    }
    return $xml . '</w:tbl>';
}

/** Build a minimal .docx from paragraphs (used for the example template and tests). */
function docx_create(string $outPath, array $paragraphs): void
{
    docx_require_zip();
    $body = '';
    foreach ($paragraphs as $p) {
        [$text, $style] = is_array($p) ? $p : [$p, null];
        $body .= '<w:p>' . ($style ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '')
            . '<w:r><w:t xml:space="preserve">' . htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>';
    }
    $zip = new ZipArchive();
    if ($zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new IntegrationException('Couldn\'t create the document.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="21"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="160"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:spacing w:before="240"/></w:pPr><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style></w:styles>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . $body . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>');
    $zip->close();
}

/**
 * A Word document as simple, safe HTML for reading on screen: headings, paragraphs
 * (bold, italic, underline), line breaks, bulleted/numbered paragraphs and tables.
 * Formatting such as fonts, columns and images isn't reproduced.
 */
function docx_to_html(string $path): string
{
    docx_require_zip();
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $xml = (string)$zip->getFromName('word/document.xml');
    $zip->close();
    $doc = new DOMDocument();
    if ($xml === '' || !@$doc->loadXML($xml, LIBXML_NONET)) {
        return '';
    }
    $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', $w);
    $on = function (?DOMElement $el) use ($w): bool {
        if (!$el) {
            return false;
        }
        $val = $el->getAttributeNS($w, 'val');
        return !in_array($val, ['0', 'false', 'none'], true);
    };
    $paragraph = function (DOMElement $p) use ($xp, $on, $w): string {
        $style = strtolower((string)$xp->evaluate('string(w:pPr/w:pStyle/@w:val)', $p));
        $list = $xp->query('w:pPr/w:numPr', $p)->length > 0;
        $html = '';
        foreach ($xp->query('.//w:r | .//w:hyperlink/w:r', $p) as $r) {
            if ($r->parentNode->localName === 'hyperlink' && $r->parentNode->parentNode !== $p) {
                continue;
            }
            $text = '';
            foreach ($r->childNodes as $c) {
                if (!$c instanceof DOMElement) {
                    continue;
                }
                $text .= match ($c->localName) {
                    't' => h($c->textContent),
                    'tab' => ' ',
                    'br', 'cr' => '<br>',
                    default => '',
                };
            }
            if ($text === '') {
                continue;
            }
            $rpr = $xp->query('w:rPr', $r)->item(0);
            if ($rpr) {
                if ($on($xp->query('w:b', $rpr)->item(0))) { $text = "<b>$text</b>"; }
                if ($on($xp->query('w:i', $rpr)->item(0))) { $text = "<i>$text</i>"; }
                if ($on($xp->query('w:u', $rpr)->item(0))) { $text = "<u>$text</u>"; }
            }
            $html .= $text;
        }
        if (trim(strip_tags($html)) === '') {
            return '';
        }
        $tag = match (true) {
            $style === 'title' => 'h1',
            (bool)preg_match('/^heading ?1$/', $style) => 'h2',
            (bool)preg_match('/^heading ?[2-9]$/', $style) => 'h3',
            default => 'p',
        };
        return $list ? "<p class=\"doc-li\">• $html</p>" : "<$tag>$html</$tag>";
    };
    $render = function (DOMElement $parent) use (&$render, $paragraph, $xp): string {
        $out = '';
        foreach ($parent->childNodes as $n) {
            if (!$n instanceof DOMElement) {
                continue;
            }
            if ($n->localName === 'p') {
                $out .= $paragraph($n);
            } elseif ($n->localName === 'tbl') {
                $out .= '<div class="doc-table"><table>';
                foreach ($xp->query('w:tr', $n) as $tr) {
                    $out .= '<tr>';
                    foreach ($xp->query('w:tc', $tr) as $tc) {
                        $out .= '<td>' . $render($tc) . '</td>';
                    }
                    $out .= '</tr>';
                }
                $out .= '</table></div>';
            } elseif (in_array($n->localName, ['sdt', 'sdtContent', 'customXml', 'smartTag'], true)) {
                $out .= $render($n);
            }
        }
        return $out;
    };
    $body = $xp->query('/w:document/w:body')->item(0);
    return $body ? $render($body) : '';
}

/** Example contract template showing merge fields and where the customer signs. */
function docx_example_template(string $outPath): void
{
    docx_create($outPath, [
        ['{{our_company_name}} – Service Agreement', 'Title'],
        'Agreement reference: {{contract_reference}}    Quote: {{quote_reference}}    Date: {{date}}',
        ['1. Parties', 'Heading1'],
        '{{our_company_name}} ("we", "us") and {{customer_name}} (company no. {{company_number}}), {{customer_address}} ("you").',
        'Customer account number: {{account_number}}',
        ['2. Services', 'Heading1'],
        'We will provide the following services on the terms below:',
        '{{services_table}}',
        'Total monthly charges: {{monthly_total}} (excluding VAT). One-off charges: {{setup_total}} (excluding VAT).',
        'Minimum term: {{term_months}} months from the date each service goes live.',
        ['3. Terms', 'Heading1'],
        'Replace this section with your standard terms and conditions for this service type.',
        ['Signed for and on behalf of {{customer_name}}', 'Heading1'],
        'Name: {{signer_name}}',
        'This agreement is signed electronically. The signature certificate issued with it records who signed, when, and how.',
    ]);
}

/**
 * An example Contract Summary, laid out under the standard headings Ofcom's General Conditions use.
 * A starting point only: have the wording checked before using it.
 */
function docx_example_summary_template(string $outPath): void
{
    docx_create($outPath, [
        ['Contract Summary', 'Title'],
        '{{our_company_name}} · for {{customer_name}} ({{account_number}}) · Agreement {{contract_reference}} · {{date}}',
        'This Contract Summary sets out the main elements of this service offer, as required by Ofcom\'s General Conditions. It helps you compare offers. Full details are in the agreement and its terms, which follow this summary.',
        ['Services and equipment', 'Heading1'],
        '{{services_table}}',
        'Replace with a short description of each service and any equipment provided (and whether it\'s rented or bought).',
        ['Speed of the internet service and remedies', 'Heading1'],
        'Replace with the estimated speeds (download and upload) for any broadband services, and what you can do if the speed isn\'t delivered.',
        ['Price', 'Heading1'],
        'Monthly charges: {{monthly_total}} excluding VAT. One-off charges: {{setup_total}} excluding VAT.',
        'Replace with any usage charges, price rises during the contract, and how they\'re worked out.',
        ['Duration, renewal and termination', 'Heading1'],
        'Minimum term: {{term}} from the date each service goes live.',
        'Replace with what happens at the end of the minimum term, how to end the contract, and any early termination charges.',
        ['Features for end-users with disabilities', 'Heading1'],
        'Replace with the products and services available for customers with disabilities, or say none apply.',
        ['Other relevant information', 'Heading1'],
        'Replace with anything else the customer should know, e.g. switching, number porting, or how to complain.',
    ]);
}

