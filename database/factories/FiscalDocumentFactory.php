<?php

namespace Database\Factories;

use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FiscalDocument> */
class FiscalDocumentFactory extends Factory
{
    protected $model = FiscalDocument::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'document_type' => 'NF-e',
            'provider' => 'focus',
            'source_type' => null,
            'source_id' => null,
            'source_label' => 'NF-e '.fake()->numberBetween(1000, 9999),
            'focus_reference' => 'imp-'.fake()->unique()->numerify('########'),
            'access_key' => null,
            'document_number' => (string) fake()->numberBetween(100, 99999),
            'series' => '1',
            'status' => 'autorizado',
            'issued_at' => now()->subDay(),
            'authorized_at' => now()->subDay()->addHour(),
            'issuer_document' => '28480405000193',
            'recipient_document' => '52998224725',
            'total_amount' => fake()->randomFloat(2, 10, 99999),
            'xml_path' => null,
            'document_url' => null,
            'cancelation_xml_path' => null,
            'payload_hash' => null,
            'raw_response' => [],
            'metadata' => ['origin' => 'manual'],
            'imported_at' => now(),
            'last_synced_at' => now(),
        ];
    }

    public function nfse(): static
    {
        return $this->state(fn (): array => [
            'document_type' => 'NFS-e',
            'focus_reference' => 'imp-'.fake()->unique()->numerify('########'),
            'access_key' => null,
            'status' => 'autorizado',
            'issuer_document' => '28480405000193',
        ]);
    }
}
