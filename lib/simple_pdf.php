<?php

class SimplePdfDocument {
  private array $pages = [];
  private string $current = '';
  private float $pageWidth = 595.28;
  private float $pageHeight = 841.89;
  private float $margin = 42.0;
  private float $cursorY = 42.0;
  private float $footerReserve = 34.0;
  private ?array $footerSpec = null;

  public function __construct(string $orientation = 'portrait') {
    if(strtolower($orientation) === 'landscape'){
      $this->pageWidth = 841.89;
      $this->pageHeight = 595.28;
      $this->margin = 34.0;
      $this->cursorY = $this->margin;
      $this->footerReserve = 30.0;
    }
    $this->addPage();
  }

  public function addPage(): void {
    if($this->current !== ''){
      $this->pages[] = $this->finalizeCurrentPage($this->current);
    }
    $this->current = '';
    $this->cursorY = $this->margin;
  }

  public function pageHeight(): float { return $this->pageHeight; }
  public function pageWidth(): float { return $this->pageWidth; }
  public function margin(): float { return $this->margin; }
  public function y(): float { return $this->cursorY; }
  public function setY(float $y): void { $this->cursorY = $y; }
  public function advance(float $delta): void { $this->cursorY += $delta; }
  public function contentWidth(): float { return $this->pageWidth - ($this->margin * 2); }

  public function setFooterText(string $text, int $size = 8, array $rgb = [71,84,103]): void {
    $text = trim($text);
    if($text === ''){
      $this->footerSpec = null;
      return;
    }
    $this->footerSpec = [
      'text' => $text,
      'size' => $size,
      'rgb' => $rgb,
    ];
  }

  public function ensureSpace(float $needed): void {
    if($this->cursorY + $needed > ($this->pageHeight - $this->margin - $this->footerReserve)){
      $this->addPage();
    }
  }

  public function drawRect(float $x, float $y, float $w, float $h, array $strokeRgb = [207,214,223], ?array $fillRgb = null): void {
    $cmd = '';
    if($fillRgb){
      $cmd .= sprintf("%.3F %.3F %.3F rg\n", $fillRgb[0]/255, $fillRgb[1]/255, $fillRgb[2]/255);
    }
    $cmd .= sprintf("%.3F %.3F %.3F RG\n", $strokeRgb[0]/255, $strokeRgb[1]/255, $strokeRgb[2]/255);
    $cmd .= sprintf("1 w %.2F %.2F %.2F %.2F re %s\n",
      $x,
      $this->pdfY($y + $h),
      $w,
      $h,
      $fillRgb ? 'B' : 'S'
    );
    $this->current .= $cmd;
  }

  public function text(float $x, float $y, string $text, int $size = 12, string $style = 'regular', array $rgb = [17,24,39]): void {
    $font = ($style === 'bold') ? 'F2' : 'F1';
    $encoded = $this->escapeText($text);
    $this->current .= sprintf(
      "BT /%s %d Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
      $font,
      $size,
      $rgb[0]/255, $rgb[1]/255, $rgb[2]/255,
      $x,
      $this->pdfY($y),
      $encoded
    );
  }

  public function wrappedText(float $x, float $y, float $width, string $text, int $size = 11, string $style = 'regular', array $rgb = [17,24,39], float $leading = 15.0): float {
    $lines = $this->wrapText($text, $width, $size, $style);
    $currentY = $y;
    foreach($lines as $line){
      $this->text($x, $currentY, $line, $size, $style, $rgb);
      $currentY += $leading;
    }
    return $currentY;
  }

  public function heading(string $text, int $size = 16): void {
    $this->ensureSpace(24);
    $this->text($this->margin, $this->cursorY, $text, $size, 'bold');
    $this->cursorY += $size + 8;
  }

  public function paragraph(string $text, int $size = 11, string $style = 'regular', array $rgb = [17,24,39], float $after = 8): void {
    $lines = $this->wrapText($text, $this->pageWidth - ($this->margin * 2), $size, $style);
    $needed = (count($lines) * 15) + $after;
    $this->ensureSpace($needed);
    $this->cursorY = $this->wrappedText($this->margin, $this->cursorY, $this->pageWidth - ($this->margin * 2), $text, $size, $style, $rgb, 15.0);
    $this->cursorY += $after;
  }

  public function boxedSection(string $title, array $lines, array $fillRgb, array $strokeRgb, array $titleRgb = [17,24,39]): void {
    $wrapped = [];
    foreach($lines as $line){
      $wrapped = array_merge($wrapped, $this->wrapText($line, $this->pageWidth - ($this->margin * 2) - 24, 11, 'regular'));
    }
    $height = 22 + (count($wrapped) * 15) + 14;
    $this->ensureSpace($height + 6);
    $top = $this->cursorY;
    $this->drawRect($this->margin, $top, $this->pageWidth - ($this->margin * 2), $height, $strokeRgb, $fillRgb);
    $this->text($this->margin + 12, $top + 16, $title, 13, 'bold', $titleRgb);
    $y = $top + 36;
    foreach($wrapped as $line){
      $this->text($this->margin + 12, $y, $line, 11, 'regular', [17,24,39]);
      $y += 15;
    }
    $this->cursorY = $top + $height + 10;
  }

  public function kvGrid(array $items): void {
    $colWidth = ($this->pageWidth - ($this->margin * 2) - 10) / 2;
    $rowHeight = 42;
    $rows = array_chunk($items, 2, true);
    $this->ensureSpace((count($rows) * ($rowHeight + 8)) + 8);
    foreach($rows as $row){
      $x = $this->margin;
      foreach($row as $label => $value){
        $this->drawRect($x, $this->cursorY, $colWidth, $rowHeight, [207,214,223], [248,250,252]);
        $this->text($x + 10, $this->cursorY + 14, $label, 9, 'regular', [71,84,103]);
        $this->wrappedText($x + 10, $this->cursorY + 28, $colWidth - 20, $value, 11, 'bold', [17,24,39], 13);
        $x += $colWidth + 10;
      }
      $this->cursorY += $rowHeight + 8;
    }
  }

  public function bulletList(array $rows, int $size = 11): void {
    foreach($rows as $row){
      $this->ensureSpace(18);
      $this->text($this->margin, $this->cursorY, '•', $size, 'bold');
      $endY = $this->wrappedText($this->margin + 12, $this->cursorY, $this->pageWidth - ($this->margin * 2) - 12, $row, $size);
      $this->cursorY = $endY + 4;
    }
  }

  public function table(array $headers, array $rows, array $widths, array $options = []): void {
    $headerSize = (int)($options['header_size'] ?? 8);
    $bodySize = (int)($options['body_size'] ?? 8);
    $rowPadding = (float)($options['padding'] ?? 4.0);
    $headerFill = $options['header_fill'] ?? [238,241,245];
    $headerStroke = $options['header_stroke'] ?? [195,203,214];
    $bodyStroke = $options['body_stroke'] ?? [223,228,235];
    $lineHeight = (float)($options['line_height'] ?? ($bodySize + 2));
    $headerHeight = (float)($options['header_height'] ?? ($headerSize + 8));
    $repeatHeader = (bool)($options['repeat_header'] ?? true);

    $renderHeader = function() use ($headers, $widths, $headerSize, $headerFill, $headerStroke, $headerHeight, $rowPadding): void {
      $x = $this->margin;
      foreach($headers as $index => $header){
        $width = (float)$widths[$index];
        $this->drawRect($x, $this->cursorY, $width, $headerHeight, $headerStroke, $headerFill);
        $this->wrappedText($x + $rowPadding, $this->cursorY + ($headerSize + 1), $width - ($rowPadding * 2), (string)$header, $headerSize, 'bold', [17,24,39], $headerSize + 1.5);
        $x += $width;
      }
      $this->cursorY += $headerHeight;
    };

    $this->ensureSpace($headerHeight + 10);
    $renderHeader();

    foreach($rows as $row){
      $cellLines = [];
      $rowHeight = 0.0;
      foreach($widths as $index => $width){
        $text = isset($row[$index]) ? (string)$row[$index] : '';
        $lines = $this->wrapText($text, $width - ($rowPadding * 2), $bodySize, 'regular');
        $cellLines[$index] = $lines;
        $cellHeight = max($lineHeight, count($lines) * $lineHeight) + ($rowPadding * 2);
        if($cellHeight > $rowHeight) $rowHeight = $cellHeight;
      }

      $this->ensureSpace($rowHeight);
      if($repeatHeader && $this->cursorY === $this->margin){
        $renderHeader();
      }

      $x = $this->margin;
      foreach($widths as $index => $width){
        $this->drawRect($x, $this->cursorY, $width, $rowHeight, $bodyStroke, null);
        $y = $this->cursorY + $rowPadding + $bodySize;
        foreach($cellLines[$index] as $line){
          $this->text($x + $rowPadding, $y, $line, $bodySize, 'regular', [17,24,39]);
          $y += $lineHeight;
        }
        $x += $width;
      }
      $this->cursorY += $rowHeight;
    }
    $this->cursorY += 8;
  }

  public function output(string $filename = 'document.pdf'): void {
    if($this->current !== ''){
      $this->pages[] = $this->finalizeCurrentPage($this->current);
      $this->current = '';
    }

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";

    $pageRefs = [];
    $nextObject = 5;
    foreach($this->pages as $_unused){
      $pageRefs[] = $nextObject . " 0 R";
      $nextObject += 2;
    }
    $objects[] = "<< /Type /Pages /Count ".count($this->pages)." /Kids [".implode(' ', $pageRefs)."] >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

    foreach($this->pages as $content){
      $contentObjectId = count($objects) + 2;
      $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ".sprintf('%.2F', $this->pageWidth)." ".sprintf('%.2F', $this->pageHeight)."] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ".$contentObjectId." 0 R >>";
      $objects[] = "<< /Length ".strlen($content)." >>\nstream\n".$content."endstream";
    }

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach($objects as $index => $object){
      $offsets[] = strlen($pdf);
      $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
    $pdf .= "0000000000 65535 f \n";
    for($i=1; $i<=count($objects); $i++){
      $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer << /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xrefOffset."\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.strlen($pdf));
    echo $pdf;
  }

  private function pdfY(float $topY): float {
    return $this->pageHeight - $topY;
  }

  private function finalizeCurrentPage(string $content): string {
    if(!$this->footerSpec) return $content;

    $text = (string)$this->footerSpec['text'];
    $size = (int)$this->footerSpec['size'];
    $rgb = $this->footerSpec['rgb'];
    $lines = $this->wrapText($text, $this->pageWidth - ($this->margin * 2), $size, 'regular');
    $lineY = $this->pageHeight - $this->margin - $this->footerReserve + 6;
    $content .= sprintf("%.3F %.3F %.3F RG 0.5 w %.2F %.2F m %.2F %.2F l S\n",
      207/255, 214/255, 223/255,
      $this->margin,
      $this->pdfY($lineY),
      $this->pageWidth - $this->margin,
      $this->pdfY($lineY)
    );
    $y = $lineY + 12;
    foreach($lines as $line){
      $font = 'F1';
      $encoded = $this->escapeText($line);
      $content .= sprintf(
        "BT /%s %d Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
        $font,
        $size,
        $rgb[0]/255, $rgb[1]/255, $rgb[2]/255,
        $this->margin,
        $this->pdfY($y),
        $encoded
      );
      $y += $size + 2;
    }
    return $content;
  }

  private function escapeText(string $text): string {
    $text = trim($text);
    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
    if($converted === false) $converted = $text;
    $converted = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $converted);
    return $converted;
  }

  private function wrapText(string $text, float $width, int $size, string $style): array {
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if($text === '') return [''];
    $words = preg_split('/\s+/u', $text) ?: [];
    $lines = [];
    $line = '';
    foreach($words as $word){
      $candidate = $line === '' ? $word : ($line.' '.$word);
      if($this->estimatedWidth($candidate, $size, $style) <= $width || $line === ''){
        $line = $candidate;
      } else {
        $lines[] = $line;
        $line = $word;
      }
    }
    if($line !== '') $lines[] = $line;
    return $lines ?: [''];
  }

  private function estimatedWidth(string $text, int $size, string $style): float {
    $chars = mb_strlen($text);
    $factor = ($style === 'bold') ? 0.57 : 0.53;
    return $chars * $size * $factor;
  }
}
