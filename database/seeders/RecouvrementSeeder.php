<?php

namespace Database\Seeders;

use App\Models\Recouvrement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RecouvrementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $recouvrements = [
            [
                'code' => 'REC_RESTO_BAR',
                'name' => 'RECOUVREMENT RESTO/BAR',
                'slug' => 'RESTO_BAR',
                'is_used_for_restaurant' => true,
            ],
            [
                'code' => 'REC_AUTRES',
                'name' => 'AUTRES RECOUVREMENTS',
                'slug' => 'AUTRES',
                'is_used_for_restaurant' => false,
            ],
        ];

        foreach ($recouvrements as $data) {
            $existing = Recouvrement::where('code', $data['code'])->first();

            Recouvrement::updateOrCreate(
                ['code' => $data['code']],
                [
                    'uuid' => $existing ? $existing->uuid : (string) Str::uuid(),
                    'name' => $data['name'],
                    'slug' => $data['slug'],
                    'is_used_for_restaurant' => $data['is_used_for_restaurant'],
                    'created_by' => null,
                    'updated_by' => null,
                ]
            );
        }
    }
}
