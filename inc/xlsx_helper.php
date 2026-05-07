<?php
/**
 * TEKCAN SATIŞ - Native XLSX Helper
 *
 * Bağımlılıksız (composer/vendor yok) XLSX okuma/yazma.
 * Sadece ZipArchive ve SimpleXML (PHP standart eklentiler) kullanır.
 */

/**
 * XLSX dosyası oluştur
 *
 * @param string $filepath Çıktı dosya yolu
 * @param array  $headers  Başlık satırı (string array)
 * @param array  $rows     Veri satırları (her biri array)
 * @param string $sheetName Sayfa adı
 * @throws Exception
 */
function xlsx_write(string $filepath, array $headers, array $rows, string $sheetName = 'Sheet1'): void
{
    if (!class_exists('ZipArchive')) {
        throw new Exception('ZipArchive eklentisi yüklü değil.');
    }

    // Sütun referansı: 1→A, 2→B, ..., 27→AA
    $cellRef = function (int $col, int $row): string {
        $letter = '';
        $col--;
        while ($col >= 0) {
            $letter = chr(65 + ($col % 26)) . $letter;
            $col = intdiv($col, 26) - 1;
        }
        return $letter . $row;
    };

    $xmlEsc = function ($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };

    // sheet1.xml içeriği
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $sheetXml .= '<sheetData>';

    // Header satırı (kalın)
    $sheetXml .= '<row r="1">';
    foreach ($headers as $i => $val) {
        $ref = $cellRef($i + 1, 1);
        $sheetXml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . $xmlEsc($val) . '</t></is></c>';
    }
    $sheetXml .= '</row>';

    // Veri satırları
    foreach ($rows as $rowIdx => $row) {
        $rNum = $rowIdx + 2;
        $sheetXml .= '<row r="' . $rNum . '">';
        $i = 0;
        foreach ($row as $val) {
            $ref = $cellRef($i + 1, $rNum);
            if ($val === null || $val === '') {
                // Boş hücreyi atla
                $i++;
                continue;
            }
            // Sayı mı string mi?
            if (is_int($val) || is_float($val) || (is_string($val) && is_numeric($val) && $val[0] !== '0' && strpos($val, ' ') === false)) {
                $sheetXml .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
            } else {
                $sheetXml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $xmlEsc($val) . '</t></is></c>';
            }
            $i++;
        }
        $sheetXml .= '</row>';
    }

    $sheetXml .= '</sheetData></worksheet>';

    // styles.xml - başlıklar için kalın stil
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="2">
<font><sz val="11"/><name val="Calibri"/></font>
<font><b/><sz val="11"/><name val="Calibri"/></font>
</fonts>
<fills count="2">
<fill><patternFill patternType="none"/></fill>
<fill><patternFill patternType="gray125"/></fill>
</fills>
<borders count="1"><border/></borders>
<cellStyleXfs count="1"><xf/></cellStyleXfs>
<cellXfs count="2">
<xf fontId="0" fillId="0" borderId="0"/>
<xf fontId="1" fillId="0" borderId="0" applyFont="1"/>
</cellXfs>
</styleSheet>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="' . htmlspecialchars($sheetName, ENT_QUOTES | ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets>
</workbook>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

    @unlink($filepath);
    $zip = new ZipArchive();
    if ($zip->open($filepath, ZipArchive::CREATE) !== true) {
        throw new Exception('XLSX dosyası oluşturulamadı: ' . $filepath);
    }
    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $relsXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();
}

/**
 * XLSX dosyasını oku
 *
 * @param string $filepath
 * @return array  [['Sutun A', 'Sutun B', ...], ...] - ilk satır başlık dahil
 * @throws Exception
 */
function xlsx_read(string $filepath): array
{
    if (!class_exists('ZipArchive')) {
        throw new Exception('ZipArchive eklentisi yüklü değil.');
    }
    if (!is_readable($filepath)) {
        throw new Exception('Dosya okunamıyor: ' . basename($filepath));
    }

    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        throw new Exception('XLSX dosyası açılamadı (geçersiz format).');
    }

    // Shared strings (opsiyonel)
    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        // Namespace temizliği parse'ı kolaylaştırır
        $ssClean = preg_replace('/xmlns="[^"]*"/', '', $ssXml, 1);
        libxml_use_internal_errors(true);
        $ss = simplexml_load_string($ssClean);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $strings[] = (string)$si->t;
                } else {
                    // Rich text: <si><r><t>...</t></r><r><t>...</t></r></si>
                    $txt = '';
                    foreach ($si->r as $r) {
                        $txt .= (string)$r->t;
                    }
                    $strings[] = $txt;
                }
            }
        }
    }

    // İlk sayfayı bul - workbook.xml.rels içinden
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $sheetXml = $zip->getFromName($sheetPath);
    if ($sheetXml === false) {
        // Bazı XLSX'lerde farklı yol olabilir
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet1?\.xml$#', $name)) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) {
        throw new Exception('XLSX içinde sayfa bulunamadı.');
    }

    // Namespace temizle
    $sheetClean = preg_replace('/xmlns="[^"]*"/', '', $sheetXml, 1);
    $sheetClean = preg_replace('/xmlns:r="[^"]*"/', '', $sheetClean, 1);
    $sheetClean = preg_replace('/r:id="/', 'rid="', $sheetClean);

    libxml_use_internal_errors(true);
    $sheet = simplexml_load_string($sheetClean);
    if ($sheet === false) {
        throw new Exception('Sayfa XML parse edilemedi.');
    }

    // Sütun harfini indekse dönüştür: A→0, B→1, AA→26, ...
    $colIdx = function (string $colLetter): int {
        $idx = 0;
        $colLetter = strtoupper($colLetter);
        for ($i = 0; $i < strlen($colLetter); $i++) {
            $idx = $idx * 26 + (ord($colLetter[$i]) - 64);
        }
        return $idx - 1;
    };

    $rows = [];
    if (!isset($sheet->sheetData) || !isset($sheet->sheetData->row)) {
        return [];
    }

    foreach ($sheet->sheetData->row as $row) {
        $rowData = [];
        $maxCol = -1;
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
            if (!$m) continue;
            $col = $colIdx($m[1]);
            $maxCol = max($maxCol, $col);

            $type = (string)$cell['t'];
            $val = '';
            if ($type === 's') {
                $idx = (int)$cell->v;
                $val = $strings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                if (isset($cell->is->t)) {
                    $val = (string)$cell->is->t;
                } elseif (isset($cell->is->r)) {
                    $val = '';
                    foreach ($cell->is->r as $r) {
                        $val .= (string)$r->t;
                    }
                }
            } elseif ($type === 'b') {
                $val = ((string)$cell->v === '1') ? 'TRUE' : 'FALSE';
            } else {
                // Number, date, formula
                $val = (string)$cell->v;
            }
            $rowData[$col] = $val;
        }

        // Boşlukları doldur
        $normalized = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $normalized[] = $rowData[$i] ?? '';
        }
        $rows[] = $normalized;
    }

    return $rows;
}

/**
 * CSV dosyası oku (UTF-8, BOM destekli, Excel dostu noktalı virgül veya virgül ayraç)
 */
function csv_read(string $filepath): array
{
    if (!is_readable($filepath)) {
        throw new Exception('Dosya okunamıyor: ' . basename($filepath));
    }
    $content = file_get_contents($filepath);
    // BOM kaldır
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    }
    // Ayraç tespiti: ilk satırda hangisi daha çok? (; veya ,)
    $firstLine = strstr($content, "\n", true) ?: $content;
    $delim = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $tmp = tmpfile();
    fwrite($tmp, $content);
    rewind($tmp);

    $rows = [];
    while (($row = fgetcsv($tmp, 0, $delim, '"', '\\')) !== false) {
        $rows[] = $row;
    }
    fclose($tmp);
    return $rows;
}

/**
 * CSV dosyası yaz (UTF-8 BOM'lu, Excel için noktalı virgül ayraç)
 */
function csv_write(string $filepath, array $headers, array $rows): void
{
    $fp = fopen($filepath, 'w');
    if (!$fp) throw new Exception('CSV yazılamadı.');
    fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel için)
    fputcsv($fp, $headers, ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($fp, $r, ';', '"', '\\');
    }
    fclose($fp);
}
