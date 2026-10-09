<?php

namespace App\Support\Commission;

use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Dompdf\Frame;

class CommissionPdfFooter
{
    /** @return array<int, array{event: string, f: \Closure}> */
    public static function callbacks(): array
    {
        $agencies = [];
        $cells = [];

        return [
            [
                'event' => 'begin_frame',
                'f' => static function (Frame $frame, Canvas $canvas) use (&$agencies): void {
                    $node = $frame->get_node();
                    if ($node instanceof \DOMElement && $node->hasAttribute('data-footer-agency')) {
                        $agencies[$canvas->get_page_number()] = $node->getAttribute('data-footer-agency');
                    }
                },
            ],
            [
                'event' => 'end_frame',
                'f' => static function (Frame $frame, Canvas $canvas) use (&$cells): void {
                    $node = $frame->get_node();
                    if ($node instanceof \DOMElement && in_array($node->getAttribute('class'), ['footer-center', 'footer-right'], true)) {
                        $cells[$canvas->get_page_number()][$node->getAttribute('class')] = $frame->get_border_box();
                    }
                },
            ],
            [
                'event' => 'end_document',
                'f' => static function (int $page, int $count, Canvas $canvas, FontMetrics $metrics) use (&$agencies, &$cells): void {
                    $font = $metrics->getFont('PoppinsPdf', 'normal');
                    $size = 7;
                    $color = [100 / 255, 116 / 255, 139 / 255];
                    $center = $cells[$page]['footer-center'];
                    $right = $cells[$page]['footer-right'];
                    $pagination = 'Page '.$page.' / '.$count;
                    $width = $metrics->getTextWidth($pagination, $font, $size);
                    $canvas->text($center['x'] + ($center['w'] - $width) / 2, $center['y'] + 3, $pagination, $font, $size, $color);

                    $agency = '—';
                    foreach ($agencies as $startPage => $name) {
                        if ($startPage <= $page) {
                            $agency = $name;
                        }
                    }
                    $lines = self::wrap('Agence : '.$agency, $right['w'], $font, $size, $metrics);
                    $lineHeight = $metrics->getFontHeight($font, $size) * 1.1;
                    foreach ($lines as $line => $text) {
                        $width = $metrics->getTextWidth($text, $font, $size);
                        $canvas->text($right['x'] + $right['w'] - $width, $right['y'] + 3 + $line * $lineHeight, $text, $font, $size, $color);
                    }
                },
            ],
        ];
    }

    /** @return list<string> */
    private static function wrap(string $text, float $width, string $font, int $size, FontMetrics $metrics): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text) as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($metrics->getTextWidth($candidate, $font, $size) <= $width) {
                $line = $candidate;

                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
                $line = '';
            }
            foreach (mb_str_split($word) as $character) {
                if ($line !== '' && $metrics->getTextWidth($line.$character, $font, $size) > $width) {
                    $lines[] = $line;
                    $line = '';
                }
                $line .= $character;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }
}
