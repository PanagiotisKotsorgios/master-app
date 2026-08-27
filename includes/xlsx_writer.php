<?php
/**
 * includes/xlsx_writer.php — Minimal pure-PHP XLSX writer.
 *
 * No PhpSpreadsheet dependency. Produces valid Office Open XML .xlsx files
 * that Excel/LibreOffice/Google Sheets open cleanly.
 *
 * Usage:
 *   $x = new XlsxWriter();
 *   $x->addSheet('Analytics', [
 *       ['Athlete', 'Total', 'Paid', 'Pending'],   // header row
 *       ['Nikos',   120.00,  90.00,   30.00],
 *       ['Maria',   150.00, 150.00,    0.00],
 *   ], ['freezeHeader' => true]);
 *   $x->send('report.xlsx');   // streams to browser
 *
 * Types: PHP int/float → number; string → shared string; DateTime → date.
 */

class XlsxWriter
{
    /** @var array<int, array{name:string, rows:array<int,array<int,mixed>>, freezeHeader:bool}> */
    private array $sheets = [];

    /** @var array<string,int> */
    private array $sharedStrings = [];
    private int   $sharedStringsCount = 0;

    public function addSheet(string $name, array $rows, array $opts = []): void
    {
        // Sanitize sheet name (max 31 chars, no : \ / ? * [ ])
        $name = preg_replace('/[:\\\\\\/\\?\\*\\[\\]]/u', '_', $name);
        if (mb_strlen($name) > 31) $name = mb_substr($name, 0, 31);
        if ($name === '') $name = 'Sheet' . (count($this->sheets) + 1);
        $this->sheets[] = [
            'name'         => $name,
            'rows'         => $rows,
            'freezeHeader' => !empty($opts['freezeHeader']),
        ];
    }

    private function ssIndex(string $s): int
    {
        if (isset($this->sharedStrings[$s])) return $this->sharedStrings[$s];
        $idx = $this->sharedStringsCount++;
        $this->sharedStrings[$s] = $idx;
        return $idx;
    }

    private static function colLetter(int $col): string
    {
        $s = '';
        while ($col > 0) {
            $r  = ($col - 1) % 26;
            $s  = chr(65 + $r) . $s;
            $col = intdiv($col - 1, 26);
        }
        return $s;
    }

    private function sheetXml(array $sheet): string
    {
        $rowsXml = '';
        foreach ($sheet['rows'] as $rIndex => $row) {
            $rowNum = $rIndex + 1;
            $cellsXml = '';
            foreach (array_values($row) as $cIndex => $val) {
                $ref = self::colLetter($cIndex + 1) . $rowNum;
                if ($val === null || $val === '') {
                    // skip empty
                    continue;
                }
                if ($val instanceof DateTimeInterface) {
                    // Excel serial date: days since 1899-12-30
                    $ts   = $val->getTimestamp();
                    $days = ($ts / 86400) + 25569;
                    $cellsXml .= '<c r="' . $ref . '" s="1"><v>' . $days . '</v></c>';
                } elseif (is_int($val) || is_float($val)) {
                    $cellsXml .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
                } elseif (is_bool($val)) {
                    $cellsXml .= '<c r="' . $ref . '" t="b"><v>' . ($val ? 1 : 0) . '</v></c>';
                } else {
                    $ssIdx = $this->ssIndex((string)$val);
                    $cellsXml .= '<c r="' . $ref . '" t="s"><v>' . $ssIdx . '</v></c>';
                }
            }
            $rowsXml .= '<row r="' . $rowNum . '">' . $cellsXml . '</row>';
        }

        $freeze = '';
        if ($sheet['freezeHeader']) {
            $freeze = '<sheetViews><sheetView workbookViewId="0" tabSelected="1">'
                    . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                    . '</sheetView></sheetViews>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . $freeze
             . '<sheetData>' . $rowsXml . '</sheetData>'
             . '</worksheet>';
    }

    private function workbookXml(): string
    {
        $sheetsXml = '';
        foreach ($this->sheets as $i => $s) {
            $sid  = $i + 1;
            $name = htmlspecialchars($s['name'], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $sheetsXml .= '<sheet name="' . $name . '" sheetId="' . $sid . '" r:id="rId' . $sid . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
             . '<sheets>' . $sheetsXml . '</sheets>'
             . '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $_s) {
            $sid  = $i + 1;
            $rels .= '<Relationship Id="rId' . $sid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sid . '.xml"/>';
        }
        // styles + sharedStrings after sheets
        $stylesId = count($this->sheets) + 1;
        $sstId    = count($this->sheets) + 2;
        $rels .= '<Relationship Id="rId' . $stylesId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $rels .= '<Relationship Id="rId' . $sstId    . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . $rels
             . '</Relationships>';
    }

    private function contentTypesXml(): string
    {
        $overrides = '';
        foreach ($this->sheets as $i => $_s) {
            $sid = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $sid . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
             . '<Default Extension="xml"  ContentType="application/xml"/>'
             . '<Override PartName="/xl/workbook.xml"      ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
             . $overrides
             . '<Override PartName="/xl/styles.xml"        ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
             . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
             . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
             . '</Relationships>';
    }

    private function stylesXml(): string
    {
        // Style index 0 = default. Index 1 = date short (yyyy-mm-dd).
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts>'
             . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
             . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
             . '<borders count="1"><border/></borders>'
             . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
             . '<cellXfs count="2">'
             . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>'
             . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
             . '</cellXfs>'
             . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
             . '</styleSheet>';
    }

    private function sharedStringsXml(): string
    {
        $items = '';
        foreach (array_keys($this->sharedStrings) as $s) {
            $items .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t></si>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' count="' . $this->sharedStringsCount . '"'
             . ' uniqueCount="' . $this->sharedStringsCount . '">'
             . $items
             . '</sst>';
    }

    /**
     * Builds the xlsx zip into a temporary file and returns its absolute path.
     * Caller is responsible for unlinking it.
     */
    public function toFile(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension is required for XLSX export.');
        }

        // Render sheets first so shared strings are populated before we write them.
        $sheetXmls = [];
        foreach ($this->sheets as $sheet) {
            $sheetXmls[] = $this->sheetXml($sheet);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmp === false) throw new RuntimeException('Cannot create temp file for XLSX.');
        // ZipArchive needs the file to not exist or be empty; tempnam creates an empty file, that's fine.

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Cannot open ZipArchive for XLSX.');
        }

        $zip->addFromString('[Content_Types].xml',       $this->contentTypesXml());
        $zip->addFromString('_rels/.rels',               $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml',           $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels',$this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml',             $this->stylesXml());
        $zip->addFromString('xl/sharedStrings.xml',      $this->sharedStringsXml());
        foreach ($sheetXmls as $i => $xml) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
        }
        $zip->close();
        return $tmp;
    }

    /**
     * Stream the xlsx to the browser and exit.
     */
    public function send(string $filename): void
    {
        if (headers_sent()) throw new RuntimeException('Cannot send XLSX — headers already sent.');
        $path = $this->toFile();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        readfile($path);
        @unlink($path);
        exit;
    }
}
