<?php

namespace App\Support;

final class MizoramLocations
{
    // App additions to the LGD village register; these are not LGD village codes.
    private const DISTRICT_CAPITALS = [
        '261' => 'Aizawl',
        '262' => 'Champhai',
        '726' => 'Hnahthial',
        '728' => 'Khawzawl',
        '263' => 'Kolasib',
        '264' => 'Lawngtlai',
        '265' => 'Lunglei',
        '266' => 'Mamit',
        '727' => 'Saitual',
        '268' => 'Serchhip',
        '267' => 'Siaha',
    ];

    public function districts(): array
    {
        $districts = json_decode(file_get_contents(base_path('Library/mizoram-districts-villages.json')), true, flags: JSON_THROW_ON_ERROR)['districts'];

        foreach ($districts as &$district) {
            $capital = self::DISTRICT_CAPITALS[$district['lgd_code']] ?? null;
            if ($capital === null) {
                continue;
            }

            foreach ($district['villages'] as $village) {
                if (strcasecmp(trim($village['name']), $capital) === 0) {
                    continue 2;
                }
            }

            // Keep the existing API shape for mobile clients, with a namespaced app ID.
            array_unshift($district['villages'], [
                'name' => $capital,
                'lgd_code' => 'capital-'.$district['lgd_code'],
                'subdistrict' => 'District headquarters',
                'subdistrict_lgd_code' => null,
                'category' => 'Urban',
                'source' => 'app_district_headquarters',
            ]);
            $district['village_count'] = count($district['villages']);
        }
        unset($district);

        return $districts;
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
