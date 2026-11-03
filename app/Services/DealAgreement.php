<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class DealAgreement
{
    // Demonstration options, not approved commercial rates. Basis: net stock subtotal.
    public const RATES = [0, 1, 2, 2.5, 5, 10];

    public static function pennies(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function totals(array $data): array
    {
        $base = self::pennies($data['unit_price']) * (int) $data['quantity'];
        $result = ['stock' => $base];
        foreach (['markup', 'management', 'brokerage'] as $fee) {
            $result[$fee] = (int) round($base * (float) $data[$fee.'_rate'] / 100, 0, PHP_ROUND_HALF_UP);
        }
        $result['total'] = array_sum($result);
        $result['deposit'] = self::pennies($data['deposit']);
        $result['balance'] = $result['total'] - $result['deposit'];

        return $result;
    }

    public static function money(int $pennies): string
    {
        return 'GBP '.number_format($pennies / 100, 2);
    }

    public function pdf(array $data, int $id): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', resource_path('views/agreements'));
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('agreements.deal', compact('data', 'id'))->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
