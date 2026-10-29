<?php

namespace App\Services;

use Illuminate\Support\Collection;

class HoldingMeasurements
{
    public static function number(float|int $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }

    public static function quantity(array $data): string
    {
        $quantity = (int) $data['quantity'];
        $unit = $data['type'] === 'whisky' ? 'cask' : 'bottle';

        return number_format($quantity).' '.$unit.($quantity === 1 ? '' : 's');
    }

    public static function bottleMl(array $data): ?float
    {
        if (isset($data['bottle_ml'])) {
            return (float) $data['bottle_ml'];
        }
        // Preserve explicitly recorded legacy sizes; never assume a standard bottle.
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(ml|cl|l)\b/i', $data['format'] ?? '', $match)) {
            return (float) $match[1] * match (strtolower($match[2])) {
                'cl' => 10, 'l' => 1000, default => 1
            };
        }

        return null;
    }

    public static function litres(array $data): ?float
    {
        if ($data['type'] === 'whisky') {
            return isset($data['litres']) ? (float) $data['litres'] : null;
        }
        $size = self::bottleMl($data);

        return $size === null ? null : $size * $data['quantity'] / 1000;
    }

    public static function volume(array $data): string
    {
        $litres = self::litres($data);

        return $litres === null ? 'Volume not recorded' : self::number($litres).' L'.($data['type'] === 'whisky' ? ' recorded bulk' : ' wine');
    }

    public static function totals(Collection $holdings): string
    {
        $casks = $holdings->where('data.type', 'whisky')->sum('data.quantity');
        $bottles = $holdings->where('data.type', 'wine')->sum('data.quantity');

        return self::quantity(['type' => 'whisky', 'quantity' => $casks]).' · '.self::quantity(['type' => 'wine', 'quantity' => $bottles]);
    }

    public static function totalVolume(Collection $holdings): string
    {
        if ($holdings->isEmpty()) {
            return 'No holdings';
        }
        $known = $holdings->filter(fn ($holding) => self::litres($holding->data) !== null);
        if ($known->isEmpty()) {
            return 'Volume not recorded';
        }
        $total = $known->sum(fn ($holding) => self::litres($holding->data));
        $missing = $holdings->count() - $known->count();

        return self::number($total).' L recorded'.($missing ? ' · '.$missing.' holding(s) missing volume' : '');
    }
}
