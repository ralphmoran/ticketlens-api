<?php

namespace Database\Seeders;

use App\Models\BriefTemplate;
use Illuminate\Database\Seeder;

class BriefTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'slug'        => 'full',
                'name'        => 'Full Brief',
                'description' => 'All sections included — the default output.',
                'sections'    => [
                    'meta'        => true,
                    'description' => true,
                    'comments'    => ['enabled' => true, 'max' => 10],
                    'linked'      => true,
                    'code_refs'   => true,
                    'confluence'  => true,
                    'attachments' => true,
                ],
            ],
            [
                'slug'        => 'quick',
                'name'        => 'Quick Scan',
                'description' => 'Title, status, and last 2 comments. Fast daily triage.',
                'sections'    => [
                    'meta'        => true,
                    'description' => false,
                    'comments'    => ['enabled' => true, 'max' => 2],
                    'linked'      => false,
                    'code_refs'   => false,
                    'confluence'  => false,
                    'attachments' => false,
                ],
            ],
            [
                'slug'        => 'code-review',
                'name'        => 'Code Review',
                'description' => 'Title, meta, description, code refs, and linked tickets. Optimised for PR review.',
                'sections'    => [
                    'meta'        => true,
                    'description' => true,
                    'comments'    => ['enabled' => false, 'max' => 0],
                    'linked'      => true,
                    'code_refs'   => true,
                    'confluence'  => false,
                    'attachments' => false,
                ],
            ],
        ];

        $now = now();
        foreach ($templates as $data) {
            \DB::table('brief_templates')->upsert(
                [array_merge($data, [
                    'group_id'   => null,
                    'is_system'  => true,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'sections'   => json_encode($data['sections']),
                ])],
                ['slug', 'group_id'],
                ['name', 'description', 'sections', 'updated_at']
            );
        }
    }
}
