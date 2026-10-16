<?php

namespace Database\Seeders;

use App\Models\DeliveryDocument;
use App\Services\ApprovalConfiguration;
use Illuminate\Database\Seeder;

class ExistingDocumentApprovalSeeder extends Seeder
{
    public function run(): void
    {
        DeliveryDocument::whereNull('approval_snapshot')->chunkById(100, function ($documents) {
            foreach ($documents as $document) {
                if ($document->type === 'SCRAP' && ! in_array($document->status, ['DRAFT', 'COMPLETED'])) {
                    $document->approval_snapshot = app(ApprovalConfiguration::class)->active('SCRAP');
                }
                $document->save();
            }
        });
    }
}
