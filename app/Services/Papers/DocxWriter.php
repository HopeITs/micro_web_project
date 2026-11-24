<?php

declare(strict_types=1);

namespace App\Services\Papers;

use ZipArchive;

/**
 * A minimal Word (.docx) writer for the printed papers: headings, labelled lines, bordered tables, ticked lines; right-to-left in Arabic.
 * No library: a .docx is a zip of a few XML parts, and the papers need nothing more. Text is escaped; nothing is ever read from the user's
 * input as markup.
 */
final class DocxWriter
{
    private string $body = '';

    public function __construct(private bool $rtl) {}

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function run(string $text, bool $bold = false, int $size = 22): string
    {
        $rpr = ($bold ? '<w:b/><w:bCs/>' : '').'<w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/>'.($this->rtl ? '<w:rtl/>' : '');
        $out = '';
        foreach (preg_split('/\R/u', $text) as $i => $line) {
            $out .= '<w:r><w:rPr>'.$rpr.'</w:rPr>'.($i > 0 ? '<w:br/>' : '').'<w:t xml:space="preserve">'.self::esc($line).'</w:t></w:r>';
        }

        return $out;
    }

    private function p(string $runs, string $ppr = ''): string
    {
        return '<w:p><w:pPr>'.($this->rtl ? '<w:bidi/>' : '').$ppr.'</w:pPr>'.$runs.'</w:p>';
    }

    public function title(string $text): self
    {
        $this->body .= $this->p($this->run($text, true, 34), '<w:jc w:val="center"/><w:spacing w:after="160"/>');

        return $this;
    }

    /** A shaded section bar. */
    public function bar(string $text): self
    {
        $this->body .= $this->p($this->run($text, true, 24), '<w:shd w:val="clear" w:color="auto" w:fill="E8E8E8"/><w:keepNext/><w:spacing w:before="200" w:after="80"/>');

        return $this;
    }

    public function text(string $text, bool $bold = false): self
    {
        $this->body .= $this->p($this->run($text, $bold), '<w:spacing w:after="60"/>');

        return $this;
    }

    public function field(string $label, string $value): self
    {
        $this->body .= $this->p($this->run($label.': ', true).$this->run($value), '<w:spacing w:after="40"/>');

        return $this;
    }

    /** @param  list<string>  $header  @param  list<list<string>>  $rows  @param  int  $min  blank rows are added up to this count */
    public function table(array $header, array $rows, int $min = 0): self
    {
        $cols = count($header);
        $width = (int) floor(9000 / max(1, $cols));
        $cell = fn (string $t, bool $bold) => '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/></w:tcPr>'.$this->p($this->run($t, $bold, 20)).'</w:tc>';
        $tr = fn (array $cells, bool $bold) => '<w:tr>'.implode('', array_map(fn ($c) => $cell((string) $c, $bold), $cells)).'</w:tr>';
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>'.($this->rtl ? '<w:bidiVisual/>' : '').'<w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $edge) {
            $xml .= '<w:'.$edge.' w:val="single" w:sz="4" w:space="0" w:color="808080"/>';
        }
        $xml .= '</w:tblBorders></w:tblPr><w:tblGrid>'.str_repeat('<w:gridCol w:w="'.$width.'"/>', $cols).'</w:tblGrid>'.$tr($header, true);
        foreach ($rows as $row) {
            $xml .= $tr(array_values($row), false);
        }
        for ($i = count($rows); $i < $min; $i++) {
            $xml .= $tr(array_fill(0, $cols, ''), false);
        }
        $this->body .= $xml.'</w:tbl>'.$this->p('');

        return $this;
    }

    public function pageBreak(): self
    {
        $this->body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';

        return $this;
    }

    /** The .docx bytes. */
    public function toString(): string
    {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document '.$ns.'><w:body>'.$this->body
            .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1000" w:right="1000" w:bottom="1000" w:left="1000" w:header="500" w:footer="500" w:gutter="0"/>'.($this->rtl ? '<w:bidi/>' : '').'</w:sectPr></w:body></w:document>';
        $zip = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', $document);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
