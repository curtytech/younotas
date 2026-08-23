<?php

namespace Database\Seeders;

use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Database\Seeder;

class FiscalDocumentSeeder extends Seeder
{
    /**
     * Populates the local fiscal history without contacting Focus.
     *
     * These records represent imported/demo history only. They deliberately
     * have no source sale or service order, so the UI cannot dispatch an
     * emission, consultation or cancellation job for them.
     */
    public function run(): void
    {
        User::query()
            ->whereIn('email', [
                'test@example.com',
                'nfe-teste@example.com',
                'mil.mage.teste@example.com',
            ])
            ->get()
            ->each(function (User $user): void {
                $this->seedForUser($user);
            });
    }

    private function seedForUser(User $user): void
    {
        $issuerDocument = preg_replace('/\D+/', '', (string) $user->cnpj) ?: '28480405000193';
        $importedAt = now();

        $documents = [
            [
                'document_type' => 'NF-e',
                'focus_reference' => 'seednfe'.$user->id.'001',
                'source_label' => 'Documento demonstrativo NF-e',
                'document_number' => '900001',
                'series' => '1',
                'total_amount' => 99.90,
                'issued_at' => now()->subDays(2)->setTime(10, 0),
            ],
            [
                'document_type' => 'NFS-e',
                'focus_reference' => 'seednfse'.$user->id.'001',
                'source_label' => 'Documento demonstrativo NFS-e',
                'document_number' => '800001',
                'series' => null,
                'total_amount' => 1500.00,
                'issued_at' => now()->subDay()->setTime(14, 30),
            ],
        ];

        foreach ($documents as $document) {
            FiscalDocument::withTrashed()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'focus_reference' => $document['focus_reference'],
                ],
                [
                    'document_type' => $document['document_type'],
                    'provider' => 'focus',
                    'source_type' => null,
                    'source_id' => null,
                    'source_label' => $document['source_label'],
                    'access_key' => null,
                    'document_number' => $document['document_number'],
                    'series' => $document['series'],
                    'status' => 'autorizado',
                    'issued_at' => $document['issued_at'],
                    'authorized_at' => $document['issued_at']->copy()->addMinutes(2),
                    'issuer_document' => $issuerDocument,
                    'recipient_document' => '52998224725',
                    'total_amount' => $document['total_amount'],
                    'xml_path' => null,
                    'document_url' => null,
                    'cancelation_xml_path' => null,
                    'payload_hash' => null,
                    'raw_response' => null,
                    'metadata' => [
                        'origin' => 'seed',
                        'environment' => 'local',
                        'notice' => 'Registro demonstrativo; não foi consultado na Focus.',
                    ],
                    'imported_at' => $importedAt,
                    'last_synced_at' => null,
                ],
            );
        }
    }
}
