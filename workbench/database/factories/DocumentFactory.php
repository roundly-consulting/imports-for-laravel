<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Document;

/**
 * @extends Factory<Document>
 */
final class DocumentFactory extends Factory
{
    /** @var class-string<Document> */
    protected $model = Document::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'team_id' => TeamFactory::new(),
            'name' => 'file.pdf',
            'path' => 'documents/file.pdf',
            'size' => 10,
        ];
    }
}
