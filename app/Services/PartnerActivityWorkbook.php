<?php

namespace App\Services;

class PartnerActivityWorkbook
{
    private function sheet(array $rows, bool $tabular): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach ($tabular ? [24, 14, 28, 18, 45, 22, 25, 16, 12, 26, 90] : [32, 110] as $column => $width) {
            $xml .= '<col min="'.($column + 1).'" max="'.($column + 1).'" width="'.$width.'" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';
        foreach ($rows as $index => $row) {
            $xml .= '<row r="'.($index + 1).'">';
            foreach ($row as $value) {
                $style = $index === 0 ? ' s="1"' : '';
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c t="n"'.$style.'><v>'.$value.'</v></c>';

                    continue;
                }
                // Inline strings cannot execute spreadsheet formulas, including =/+/-/@ prefixes.
                $text = mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value), 0, 32000);
                $xml .= '<c t="inlineStr"'.$style.'><is><t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData>'.($tabular ? '<autoFilter ref="A1:K'.count($rows).'"/>' : '').'</worksheet>';
    }

    public function download(array $report)
    {
        $header = ['Source', 'Date', 'Partner', 'Category', 'Subject / topic', 'Status', 'Assigned staff', 'Follow-up date', 'Overdue', 'Last updated', 'Details'];
        $map = fn ($r) => [$r['source_key'], $r['activity_on'], $r['partner'], $r['category'], $r['title'], $r['status'], $r['staff'], $r['follow_up_on'], $r['overdue'] ? 'Yes' : 'No', $r['updated_at'], json_encode($r['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        $summary = [['Partner activity report', $report['filters']['from'].' through '.$report['filters']['to']], ['Timezone', $report['timezone']], ['Partner filter', $report['partners']->firstWhere('id', $report['filters']['agent_id'])['name'] ?? 'All partners'], ['Category filter', $report['filters']['category'] ?: 'All categories'], ['Staff filter', $report['staff']->firstWhere('id', $report['filters']['assignee_id'])['name'] ?? 'All staff'],
            ['Workload scope', 'Pending/overdue and agreement cards use current workload; dated activity rows use selected period.'],
            ['Renewal window (days)', $report['filters']['renewal_days']]];
        foreach ($report['summary'] as $name => $value) {
            $summary[] = [str_replace('_', ' ', $name), $value];
        }
        $sheets = ['Summary' => $summary, 'Activities' => array_merge([$header], $report['rows']->map($map)->all()),
            'Agreements' => array_merge([$header], $report['agreements']->map($map)->all()),
            'Overdue workload' => array_merge([$header], $report['workload']->map($map)->all())];
        $path = tempnam(sys_get_temp_dir(), 'partner-report-');
        $zip = new \ZipArchive;
        try {
            if (! $path || $zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create report workbook.');
            }
            $types = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
            $book = '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $rels = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $index = 0;
            foreach ($sheets as $name => $rows) {
                $index++;
                $types .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $book .= '<sheet name="'.$name.'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
                $rels .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
                $zip->addFromString('xl/worksheets/sheet'.$index.'.xml', $this->sheet($rows, $index > 1));
            }
            $types .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $rels .= '<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
            $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF14532D"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            $zip->addFromString('[Content_Types].xml', $types.'</Types>');
            $zip->addFromString('xl/workbook.xml', $book.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $rels.'</Relationships>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            if (! $zip->close()) {
                throw new \RuntimeException('Could not finish report workbook.');
            }

            return response()->download($path, 'partner-activities.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            if ($path && is_file($path)) {
                @unlink($path);
            }
            throw $e;
        }
    }
}
