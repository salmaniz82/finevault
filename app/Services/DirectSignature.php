<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;

class DirectSignature
{
    public const SIGNING_TIMEZONE = 'Asia/Karachi';

    public static function signingDate(string $utcTime): string
    {
        return \Carbon\CarbonImmutable::parse($utcTime, 'UTC')->setTimezone(self::SIGNING_TIMEZONE)->format('d M Y');
    }

    public function sample(string $variant = 'standard'): string
    {
        abort_unless(in_array($variant, ['standard', 'extended']), 422);
        return resource_path('demo/direct-'.$variant.'.pdf');
    }

    public function hotspots(string $variant = 'standard'): array
    {
        $path = $this->sample($variant);
        $manifest = json_decode(file_get_contents($path.'.json'), true, flags: JSON_THROW_ON_ERROR);
        app(SigningDocument::class)->validate($manifest, file_get_contents($path));
        return $manifest;
    }

    public function image(string $value): string
    {
        $invalid = fn () => ValidationException::withMessages(['signature' => 'Please provide a visible PNG signature.']);
        if (! str_starts_with($value, 'data:image/png;base64,')) {
            throw $invalid();
        }
        $bytes = base64_decode(substr($value, 22), true);
        $size = $bytes ? @getimagesizefromstring($bytes) : false;
        if (! $size || $size[2] !== IMAGETYPE_PNG || $size[0] > 1600 || $size[1] > 600 || $size[0] < 20 || $size[1] < 10) {
            throw $invalid();
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw $invalid();
        }
        $ink = 0;
        for ($y = 0; $y < $size[1]; $y++) {
            for ($x = 0; $x < $size[0]; $x++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($color['alpha'] < 100 && min($color['red'], $color['green'], $color['blue']) < 200) {
                    $ink++;
                }
            }
        }
        if ($ink < 30) {
            imagedestroy($image);
            throw $invalid();
        }
        // Decode and re-encode rather than retaining arbitrary uploaded metadata.
        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        return $png;
    }

    public function render(array $input, string $imagePath, string $time, string $sourcePath, array $manifest): string
    {
        app(SigningDocument::class)->validate($manifest, file_get_contents($sourcePath));
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $count = $pdf->setSourceFile($sourcePath);
        if ($count !== $manifest['page_count']) throw new \RuntimeException('PDF page count does not match its hotspots.');
        $templates = [];
        for ($page = 1; $page <= $count; $page++) $templates[$page] = $pdf->importPage($page);
        $fields = $manifest['fields'];
        $html = '<html><head><meta charset="utf-8"><style>@page{margin:0}body{margin:0;font-family:DejaVu Sans;color:#24352d}.field{position:absolute;white-space:nowrap;line-height:1.3}</style></head><body>';
        foreach (['full_name' => $input['name'], 'signed_date' => self::signingDate($time)] as $id => $value) {
            $field = $fields[$id];
            $fontSize = min(11, ($field['width'] - 8) / max(1, mb_strlen($value)));
            $html .= '<div class="field" style="left:'.($field['x'] + 4).'pt;top:'.($field['y'] + 2).'pt;font-size:'.$fontSize.'pt">'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div>';
        }
        $overlay = new \Dompdf\Dompdf();
        $overlay->setPaper([0, 0, $manifest['page_width'], $manifest['page_height']]);
        $overlay->loadHtml($html.'</body></html>');
        $overlay->render();
        $pdf->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($overlay->output()));
        $overlayTemplate = $pdf->importPage(1);
        for ($page = 1; $page <= $count; $page++) {
            $size = $pdf->getTemplateSize($templates[$page]);
            if (abs($size['width'] - $manifest['page_width']) > .1 || abs($size['height'] - $manifest['page_height']) > .1) {
                throw new \RuntimeException('PDF page size does not match its hotspots.');
            }
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templates[$page]);
            if ($page === $fields['signature']['page']) {
                $pdf->useTemplate($overlayTemplate);
                $field = $fields['signature'];
                $width = min($field['width'], $field['height'] * 4);
                $height = $width / 4;
                $pdf->Image($imagePath, $field['x'] + ($field['width'] - $width) / 2, $field['y'] + ($field['height'] - $height) / 2, $width, $height, 'PNG');
                $pdf->SetFont('Helvetica', '', 7);
                $pdf->SetTextColor(60, 70, 60);
                $pdf->Text(40, $size['height'] - 20, 'Direct signing DEMO | Saved '.$time.' UTC | Not certificate-signed');
            }
        }
        return $pdf->Output('S');
    }
}
