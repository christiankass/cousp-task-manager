<?php

namespace App\Services\Reports;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Classeur Excel simple : une feuille par tableau, première ligne en en-tête. */
class XlsxBuilder
{
    /** @param  array<string, list<list<scalar|null>>>  $sheets  nom de feuille => lignes (en-tête comprise) */
    public function download(array $sheets, string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'cousp-xlsx-');
        $writer = new Writer;
        $writer->openToFile($path);
        $header = (new Style)->withFontBold(true)->withFontColor(Color::WHITE)->withBackgroundColor('0F4C81');

        $first = true;
        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            // Excel limite les noms de feuilles à 31 caractères sans certains symboles.
            $sheet->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $name), 0, 31));
            $first = false;

            foreach (array_values($rows) as $i => $values) {
                $values = array_map(fn ($v) => $v ?? '', $values);
                $writer->addRow($i === 0 ? Row::fromValuesWithStyle($values, $header) : Row::fromValues($values));
            }
        }

        $writer->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
