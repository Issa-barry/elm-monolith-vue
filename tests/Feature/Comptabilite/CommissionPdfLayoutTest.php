<?php

namespace Tests\Feature\Comptabilite;

use App\Support\Commission\CommissionPdfFooter;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Tests\TestCase;

class CommissionPdfLayoutTest extends TestCase
{
    public function test_sales_pdf_keeps_names_and_amounts_inside_printable_columns(): void
    {
        $this->checkLayout(true);
    }

    public function test_print_margins_are_kept_on_every_agency_page(): void
    {
        $this->checkLayout(true, 30, 2);
    }

    public function test_shared_logistics_template_keeps_its_print_margins(): void
    {
        $this->checkLayout(false);
    }

    public function test_long_metadata_and_vehicle_names_stay_inside_their_cells(): void
    {
        $this->checkLayout(true, 3, 1, 'long');
    }

    public function test_unbroken_text_and_large_amounts_wrap_without_clipping(): void
    {
        $this->checkLayout(true, 3, 1, 'unbroken');
    }

    public function test_empty_report_preserves_printable_table_and_signature(): void
    {
        $this->checkLayout(true, 0, 1, 'empty');
    }

    private function checkLayout(bool $validation, int $rowCount = 2, int $siteCount = 1, string $scenario = 'standard'): void
    {
        $row = [
            'beneficiaire_nom' => 'Mamadou Alpha Oumar Diallo de la grande équipe de livraison',
            'telephone' => '+224622123456',
            'vehicules' => [
                ['nom' => 'Thierno-Moto', 'immatriculation' => 'IU123'],
                ['nom' => 'ADAMA', 'immatriculation' => 'OU114'],
            ],
            'total_cumule' => $validation ? 123456789 : 190000,
            'frais' => $validation ? 1000000 : 10000,
            'net_valide' => 122456789,
            'deja_paye' => $validation ? 2000000 : 20000,
            'reste' => $validation ? 120456789 : 190000,
            'statut' => 'Partiellement payée',
        ];
        $site = [
            'site_nom' => 'Matoto',
            'rows' => array_fill(0, $rowCount, $row),
            'totaux' => [
                'total_cumule' => $validation ? 125302989 : 380000,
                'total_frais' => $validation ? 1000000 : 20000,
                'total_net_valide' => 124302989,
                'total_deja_paye' => $validation ? 2000000 : 40000,
                'total_reste' => $validation ? 122302989 : 380000,
            ],
        ];
        $data = [
            'title' => 'Commissions livreur vente',
            'org' => (object) ['name' => 'Formation-Eau La Maman'],
            'periode_label' => 'P1 du 01 au 15 Septembre 2026',
            'filters' => [],
            'sites' => array_fill(0, $siteCount, $site),
            'show_validation_columns' => $validation,
            'printed_by' => 'Aperçu UI',
            'generated_at' => new \DateTimeImmutable('2026-10-07 15:50:00'),
        ];
        if (in_array($scenario, ['long', 'unbroken'], true)) {
            $separator = $scenario === 'long' ? ' ' : '';
            $row['beneficiaire_nom'] = implode($separator, array_fill(0, 10, 'MamadouDiallo'));
            $row['telephone'] = '+224622123456 poste 12345678901234567890';
            $row['vehicules'] = [[
                'nom' => implode($separator, array_fill(0, 8, 'CamionLivraison')),
                'immatriculation' => str_repeat('GN123456', 6),
            ]];
            foreach (['total_cumule', 'frais', 'net_valide', 'deja_paye', 'reste'] as $field) {
                $row[$field] = 123456789012345;
            }
            $data['org']->name = implode($separator, array_fill(0, 15, 'Organisation'));
            $data['sites'][0]['site_nom'] = implode($separator, array_fill(0, 12, 'AgenceMatoto'));
            $data['sites'][0]['rows'] = array_fill(0, $rowCount, $row);
            $data['sites'][0]['totaux'] = array_fill_keys(array_keys($site['totaux']), 370370367037035);
            $data['printed_by'] = implode($separator, array_fill(0, 8, 'Administrateur'));
            $data['periode_label'] = implode($separator, array_fill(0, 8, 'PeriodeRapport'));
            $data['filters']['search'] = str_repeat('Recherche', 15);
        }
        if ($siteCount > 1) {
            $data['sites'][1]['site_nom'] = 'Kouria';
        }
        $pdf = Pdf::loadView('pdf.commissions.index', $data)->setPaper('a4', 'landscape');

        $beneficiaries = [];
        $vehicles = [];
        $amounts = [];
        $text = [];
        $footers = [];
        $printedText = [];
        $signatures = [];
        $headerPages = [];
        $tablePages = [];
        $fonts = [];
        $headingLines = [];
        $footerText = [];
        $displayedPhones = [];
        $metadataText = [];
        $footerCallbacks = CommissionPdfFooter::callbacks();
        foreach ($footerCallbacks as &$callback) {
            if ($callback['event'] !== 'end_document') {
                continue;
            }
            $draw = $callback['f'];
            $callback['f'] = static function (int $page, int $count, Canvas $canvas, FontMetrics $metrics) use ($draw, &$footerText): void {
                $spy = \Mockery::mock(Canvas::class);
                $spy->shouldReceive('text')->andReturnUsing(static function ($x, $y, $text, $font, $size, $color) use ($canvas, $metrics, $page, &$footerText): void {
                    $footerText[$page][] = [$text, $x, $y, $metrics->getTextWidth($text, $font, $size), $metrics->getFontHeight($font, $size)];
                    $canvas->text($x, $y, $text, $font, $size, $color);
                });
                $draw($page, $count, $spy, $metrics);
            };
        }
        unset($callback);
        $dompdf = $pdf->getDomPDF();
        $dompdf->setCallbacks([...$footerCallbacks, [
            'event' => 'end_frame',
            'f' => static function (Frame $frame) use (&$beneficiaries, &$vehicles, &$amounts, &$text, &$footers, &$printedText, &$signatures, &$headerPages, &$tablePages, &$fonts, &$headingLines, &$displayedPhones, &$metadataText, $dompdf): void {
                $node = $frame->get_node();
                if ($node instanceof \DOMElement && in_array($node->tagName, ['td', 'span'], true) && in_array($node->getAttribute('class'), ['ben-phone', 'col-tel center'], true)) {
                    $displayedPhones[] = trim($node->textContent);
                }
                if ($node instanceof \DOMElement && in_array($node->getAttribute('class'), ['column-label', 'column-unit'], true)) {
                    $headingLines[$dompdf->getCanvas()->get_page_number()][$node->getAttribute('class')][] = $frame->get_border_box();
                }
                if ($node instanceof \DOMElement && $node->getAttribute('class') === 'page-footer') {
                    $footers[] = $frame->get_border_box();
                }
                if ($node instanceof \DOMElement && $node->getAttribute('class') === 'col-sig center') {
                    $signatures[] = $frame->get_border_box();
                    $headerPages[$dompdf->getCanvas()->get_page_number()] = true;
                }
                if ($node instanceof \DOMElement && $node->getAttribute('class') === 'total-row') {
                    $tablePages[$dompdf->getCanvas()->get_page_number()] = true;
                }
                if ($node instanceof \DOMElement && $node->tagName === 'td') {
                    if ($node->getAttribute('class') === 'col-ben') {
                        $beneficiaries[] = $frame->get_border_box();
                        $tablePages[$dompdf->getCanvas()->get_page_number()] = true;
                    }
                    if ($node->getAttribute('class') === 'col-veh') {
                        $vehicles[] = $frame->get_border_box();
                    }
                }
                if ($node->nodeName !== '#text' || trim($node->textContent) === '') {
                    return;
                }
                $text[] = trim($node->textContent);
                $fonts[$frame->get_style()->font_family] = true;
                $printedText[] = [$frame->get_border_box(), null, trim($node->textContent)];
                for ($parent = $frame->get_parent(); $parent; $parent = $parent->get_parent()) {
                    $ancestor = $parent->get_node();
                    if ($ancestor instanceof \DOMElement && $ancestor->getAttribute('class') === 'header-details') {
                        $metadataText[$dompdf->getCanvas()->get_page_number()][] = [$frame->get_border_box(), trim($node->textContent)];
                        break;
                    }
                }
                for ($parent = $frame->get_parent(); $parent; $parent = $parent->get_parent()) {
                    $cell = $parent->get_node();
                    if ($cell instanceof \DOMElement && in_array($cell->tagName, ['td', 'th'], true)) {
                        $printedText[array_key_last($printedText)][1] = $parent->get_border_box();
                        break;
                    }
                }
                if (! preg_match('/^\d[\d\s\x{00a0}]*$/u', trim($node->textContent))) {
                    return;
                }
                for ($parent = $frame->get_parent(); $parent; $parent = $parent->get_parent()) {
                    $cell = $parent->get_node();
                    if ($cell instanceof \DOMElement && $cell->tagName === 'td') {
                        if (str_contains($cell->getAttribute('class'), 'right')) {
                            $amounts[] = [$frame->get_border_box(), $parent->get_border_box()];
                        }
                        break;
                    }
                }
            },
        ]]);
        $output = $pdf->output();
        $previewDirectory = base_path('test-results/pdf');
        if (! is_dir($previewDirectory)) {
            mkdir($previewDirectory, 0777, true);
        }
        file_put_contents($previewDirectory.'/commissions-'.$scenario.($validation ? '-vente' : '-logistique').'-'.$rowCount.'x'.$siteCount.'.pdf', $output);
        $this->assertStringStartsWith('%PDF-', $output);
        foreach (array_keys($fonts) as $font) {
            $this->assertStringContainsString('poppinspdf_', strtolower($font), 'La police Apollo doit etre integree au PDF sans remplacement silencieux.');
        }
        $margin = 12 * 72 / 25.4;
        $pageWidth = $dompdf->getCanvas()->get_width();
        $pageHeight = $dompdf->getCanvas()->get_height();
        $this->assertEqualsWithDelta(841.89, $pageWidth, 0.01);
        $this->assertEqualsWithDelta(595.28, $pageHeight, 0.01);
        if ($scenario === 'standard') {
            $this->assertSame(array_fill(0, $rowCount * $siteCount, '+224622123456'), array_map(fn ($phone) => preg_replace('/\s+/u', '', $phone), $displayedPhones));
            if ($rowCount > 0) {
                $this->assertStringContainsString('+224 622 12 34 56', view('pdf.commissions.index', $data)->render());
            }
            foreach ($metadataText as $items) {
                foreach ($items as [$labelBox, $label]) {
                    if (! in_array($label, ['Agence', 'Période', 'Imprimé le', 'Imprimé par'], true)) {
                        continue;
                    }
                    $values = array_values(array_filter($items, fn ($item) => ! in_array($item[1], ['Agence', 'Période', 'Imprimé le', 'Imprimé par'], true) && abs($item[0]['y'] - $labelBox['y']) < 1));
                    $this->assertCount(1, $values, 'Chaque libelle doit etre aligne avec sa valeur.');
                    $gap = $values[0][0]['x'] - ($labelBox['x'] + $labelBox['w']);
                    $this->assertGreaterThanOrEqual(8, $gap);
                    $this->assertLessThanOrEqual(16, $gap, 'Le libelle est trop eloigne de sa valeur.');
                }
            }
        }
        $pageCount = $dompdf->getCanvas()->get_page_count();
        $this->assertCount($pageCount, $footerText);
        foreach ($footerText as $page => $lines) {
            $this->assertSame('Page '.$page.' / '.$pageCount, $lines[0][0]);
            $agency = $siteCount > 1 && $page > $pageCount / 2 ? 'Kouria' : $data['sites'][0]['site_nom'];
            $this->assertSame(preg_replace('/\s+/u', '', 'Agence : '.$agency), preg_replace('/\s+/u', '', implode(' ', array_column(array_slice($lines, 1), 0))));
            foreach ($lines as [$content, $x, $y, $width, $height]) {
                $this->assertGreaterThanOrEqual($margin - 1, $x, $content);
                $this->assertLessThanOrEqual($pageWidth - $margin + 1, $x + $width, $content);
                $this->assertLessThanOrEqual($pageHeight - 16 * 72 / 25.4, $y + $height, $content);
            }
        }
        $this->assertNotEmpty($signatures);
        foreach (array_keys($tablePages) as $page) {
            $this->assertArrayHasKey($page, $headerPages, 'Les en-tetes doivent accompagner les lignes du tableau, meme apres un en-tete de document long.');
        }
        $this->assertNotEmpty($headingLines);
        foreach ($headingLines as $lines) {
            $this->assertCount($validation ? 5 : 4, $lines['column-label']);
            $this->assertCount($validation ? 5 : 4, $lines['column-unit']);
            foreach (['column-label', 'column-unit'] as $kind) {
                foreach ($lines[$kind] as $box) {
                    $this->assertEqualsWithDelta($lines[$kind][0]['y'], $box['y'], 0.1, 'Les titres financiers et leur devise doivent etre alignes sur deux lignes distinctes.');
                    $this->assertEqualsWithDelta($lines[$kind][0]['h'], $box['h'], 0.1, 'Un titre financier ne doit pas se decaler en revenant a la ligne.');
                }
            }
            $this->assertGreaterThan($lines['column-label'][0]['y'] + $lines['column-label'][0]['h'], $lines['column-unit'][0]['y']);
        }
        foreach ($signatures as $signature) {
            $this->assertLessThanOrEqual($pageWidth - $margin + 1, $signature['x'] + $signature['w']);
            if ($validation) {
                $this->assertGreaterThanOrEqual(20 * 72 / 25.4, $signature['w']);
            }
        }
        foreach ($printedText as [$box, $cell, $content]) {
            $this->assertGreaterThanOrEqual($margin - 1, $box['x'], $content);
            $this->assertLessThanOrEqual($pageWidth - $margin + 1, $box['x'] + $box['w'], $content);
            $this->assertGreaterThanOrEqual(10 * 72 / 25.4 - 1, $box['y'], $content);
            $this->assertLessThanOrEqual($pageHeight - 17 * 72 / 25.4 + 1, $box['y'] + $box['h'], $content);
            if ($cell) {
                $this->assertGreaterThanOrEqual($cell['x'] - 1, $box['x'], $content);
                $this->assertLessThanOrEqual($cell['x'] + $cell['w'] + 1, $box['x'] + $box['w'], $content);
            }
        }
        $this->assertNotEmpty($footers);
        $footerTop = min(array_column($footers, 'y'));
        $this->assertCount($rowCount * $siteCount, $beneficiaries);
        foreach ($beneficiaries as $box) {
            $this->assertGreaterThanOrEqual($margin - 1, $box['x']);
            $this->assertLessThanOrEqual($pageWidth - $margin + 1, $box['x'] + $box['w']);
            $this->assertGreaterThanOrEqual(10 * 72 / 25.4 - 1, $box['y']);
            $this->assertLessThanOrEqual($pageHeight - 32 * 72 / 25.4 + 1, $box['y'] + $box['h']);
            $this->assertLessThan($footerTop, $box['y'] + $box['h'], 'Le pied de page recouvre une ligne du tableau.');
        }
        if ($validation && $rowCount > 0) {
            $this->assertGreaterThan(2 * $vehicles[0]['w'], $beneficiaries[0]['w']);
        }
        if ($rowCount > 0) {
            $this->assertNotEmpty($amounts);
        }
        foreach ($amounts as [$number, $cell]) {
            $this->assertGreaterThanOrEqual($cell['x'] + 2, $number['x'], 'Le montant empiete sur la colonne precedente.');
            $this->assertLessThanOrEqual($cell['x'] + $cell['w'] - 2, $number['x'] + $number['w'], 'Le montant empiete sur la colonne suivante.');
        }
        $fullText = preg_replace('/\s+/u', ' ', implode(' ', $text));
        $compactText = preg_replace('/\s+/u', '', $fullText);
        foreach ([$data['title'], strtoupper($data['org']->name), $data['periode_label'], $data['printed_by'], $data['sites'][0]['site_nom'], $data['filters']['search'] ?? ''] as $value) {
            $this->assertStringContainsString(preg_replace('/\s+/u', '', $value), $compactText);
        }
        if ($rowCount > 0) {
            foreach ([$row['beneficiaire_nom'], $row['telephone'], $row['vehicules'][0]['nom'], $row['vehicules'][0]['immatriculation'], $row['statut']] as $value) {
                $this->assertStringContainsString(preg_replace('/\s+/u', '', $value), $compactText);
            }
        }
        if (in_array($scenario, ['long', 'unbroken'], true)) {
            $this->assertStringContainsString('123456789012345', $compactText);
            $this->assertStringContainsString('370370367037035', $compactText);
        }
        if ($siteCount > 1) {
            $this->assertGreaterThanOrEqual($siteCount, $dompdf->getCanvas()->get_page_count());
        }
    }
}
