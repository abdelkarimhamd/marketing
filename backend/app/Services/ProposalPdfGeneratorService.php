<?php

namespace App\Services;

class ProposalPdfGeneratorService
{
    /**
     * Generate a lightweight PDF document from title/body text.
     */
    public function generate(string $title, string $bodyText): string
    {
        $lines = $this->normalizeLines($title, $bodyText);
        $content = $this->buildContentStream($lines);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        $offsets[1] = strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        $offsets[2] = strlen($pdf);
        $pdf .= "2 0 obj\n<< /Type /Pages /Count 1 /Kids [3 0 R] >>\nendobj\n";

        $offsets[3] = strlen($pdf);
        $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";

        $offsets[4] = strlen($pdf);
        $pdf .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

        $offsets[5] = strlen($pdf);
        $pdf .= "5 0 obj\n<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream\nendobj\n";

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    /**
     * @return list<string>
     */
    private function normalizeLines(string $title, string $bodyText): array
    {
        $title = trim($this->sanitizeText($title));
        $bodyText = trim($this->sanitizeText($bodyText));
        $lines = [];

        if ($title !== '') {
            $lines[] = $title;
            $lines[] = '';
        }

        $paragraphs = preg_split('/\R+/', $bodyText) ?: [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);

            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }

            $wrapped = wordwrap($paragraph, 92, "\n", true);
            foreach (preg_split('/\n/', $wrapped) ?: [] as $wrappedLine) {
                $lines[] = trim((string) $wrappedLine);
            }
        }

        if ($lines === []) {
            $lines[] = 'Proposal';
        }

        return array_slice($lines, 0, 220);
    }

    /**
     * @param list<string> $lines
     */
    private function buildContentStream(array $lines): string
    {
        $streamLines = [
            'BT',
            '/F1 11 Tf',
            '50 760 Td',
        ];

        $count = 0;

        foreach ($lines as $line) {
            $escaped = $this->escapePdfText($line);
            $streamLines[] = "({$escaped}) Tj";
            $streamLines[] = 'T*';
            $count++;

            // Keep text on one page.
            if ($count >= 220) {
                break;
            }
        }

        $streamLines[] = 'ET';

        return implode("\n", $streamLines);
    }

    private function sanitizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function escapePdfText(string $value): string
    {
        return str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $value,
        );
    }
}
