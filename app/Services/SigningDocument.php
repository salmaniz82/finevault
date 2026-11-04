<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

class SigningDocument
{
    /** Measure the final rendered boxes, not HTML estimates or a presumed page number. */
    public function generate(string $html): array
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', resource_path('views/agreements'));
        $pdf = new Dompdf($options);
        $fields = [];
        $pdf->setCallbacks([['event' => 'end_frame', 'f' => function ($frame, $canvas) use (&$fields) {
            $node = $frame->get_node();
            if (! $node instanceof \DOMElement || ! $node->hasAttribute('data-signing-field')) return;
            $id = $node->getAttribute('data-signing-field');
            if (isset($fields[$id])) throw new RuntimeException('Signing field repeated or split across pages: '.$id);
            [$x, $y, $width, $height] = $frame->get_content_box();
            $fields[$id] = ['page' => $canvas->get_page_number(), 'x' => $x, 'y' => $y,
                'width' => $width, 'height' => $height];
        }]]);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4');
        $pdf->render();
        $bytes = $pdf->output();
        $manifest = ['version' => 1, 'units' => 'pt', 'origin' => 'top-left',
            'source_sha256' => hash('sha256', $bytes), 'page_count' => $pdf->getCanvas()->get_page_count(),
            'page_width' => $pdf->getCanvas()->get_width(), 'page_height' => $pdf->getCanvas()->get_height(), 'fields' => $fields];
        $this->validate($manifest, $bytes);
        return ['pdf' => $bytes, 'hotspots' => $manifest];
    }

    public function validate(array $manifest, string $bytes): void
    {
        if (($manifest['version'] ?? null) !== 1 || ($manifest['units'] ?? '') !== 'pt'
            || ($manifest['origin'] ?? '') !== 'top-left'
            || ! hash_equals($manifest['source_sha256'] ?? '', hash('sha256', $bytes))) {
            throw new RuntimeException('Signing hotspots do not match this PDF. Generate a new agreement.');
        }
        foreach (['signature', 'full_name', 'signed_date'] as $id) {
            $field = $manifest['fields'][$id] ?? null;
            if (! $field || $field['page'] < 1 || $field['page'] > $manifest['page_count']
                || $field['x'] < 0 || $field['y'] < 0 || $field['width'] <= 0 || $field['height'] <= 0
                || $field['x'] + $field['width'] > $manifest['page_width']
                || $field['y'] + $field['height'] > $manifest['page_height']) {
                throw new RuntimeException('Missing or out-of-page signing hotspot: '.$id);
            }
        }
        $fields = array_values($manifest['fields']);
        foreach ($fields as $i => $a) foreach (array_slice($fields, $i + 1) as $b) {
            if ($a['page'] !== $b['page']) throw new RuntimeException('Signing block must remain on one page.');
            if ($a['x'] < $b['x'] + $b['width'] && $a['x'] + $a['width'] > $b['x']
                && $a['y'] < $b['y'] + $b['height'] && $a['y'] + $a['height'] > $b['y']) {
                throw new RuntimeException('Signing hotspots overlap.');
            }
        }
    }
}
