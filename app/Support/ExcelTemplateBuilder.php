<?php

namespace App\Support;

class ExcelTemplateBuilder
{
    /**
     * @param  array<int, array{name: string, headers: array<int, string>}>  $sheets
     */
    public static function build(array $sheets): string
    {
        $worksheets = '';

        foreach ($sheets as $sheet) {
            $worksheets .= self::buildWorksheet(
                $sheet['name'],
                $sheet['headers'],
            );
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<?mso-application progid="Excel.Sheet"?>'."\n"
            .'<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            .'xmlns:o="urn:schemas-microsoft-com:office:office" '
            .'xmlns:x="urn:schemas-microsoft-com:office:excel" '
            .'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n"
            .'  <Styles>'."\n"
            .'    <Style ss:ID="Header">'."\n"
            .'      <Font ss:Bold="1"/>'."\n"
            .'      <Interior ss:Color="#E5E7EB" ss:Pattern="Solid"/>'."\n"
            .'    </Style>'."\n"
            .'  </Styles>'."\n"
            .$worksheets
            .'</Workbook>';
    }

    /**
     * @param  array<int, string>  $headers
     */
    private static function buildWorksheet(string $name, array $headers): string
    {
        $sheetName = self::escape(substr($name, 0, 31));

        $headerCells = '';
        $emptyCells = '';

        foreach ($headers as $header) {
            $headerCells .= self::cell($header);
            $emptyCells .= '<Cell/>';
        }

        return '  <Worksheet ss:Name="'.$sheetName.'">'."\n"
            .'    <Table>'."\n"
            .'      <Row ss:StyleID="Header">'.$headerCells.'</Row>'."\n"
            .'      <Row>'.$emptyCells.'</Row>'."\n"
            .'    </Table>'."\n"
            .'  </Worksheet>'."\n";
    }

    private static function cell(string $value): string
    {
        return '<Cell><Data ss:Type="String">'.self::escape($value).'</Data></Cell>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
