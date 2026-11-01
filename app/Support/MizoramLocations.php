<?php

namespace App\Support;

final class MizoramLocations
{
    public function districts(): array
    {
        return json_decode(file_get_contents(base_path('Library/mizoram-districts-villages.json')), true, flags: JSON_THROW_ON_ERROR)['districts'];
    }

    public function find(?string $districtCode, ?string $villageCode): ?array
    {
        foreach ($this->districts() as $district) {
            if ($district['lgd_code'] !== $districtCode) {
                continue;
            }
            foreach ($district['villages'] as $village) {
                if ($village['lgd_code'] === $villageCode) {
                    return ['district' => $district['name'], 'village' => $village['name']];
                }
            }
        }

        return null;
    }
}
