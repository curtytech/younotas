<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->longText('focus_nfse_response_secure')->nullable()->after('focus_nfse_response');
        });

        DB::table('services')->orderBy('id')->each(function (object $service): void {
            $updates = [];
            $response = $this->decodeJson($service->focus_nfse_response);
            $error = $this->decodeJson($service->focus_nfse_error);

            if ($response !== null) {
                $updates['focus_nfse_response_secure'] = Crypt::encryptString(json_encode($response, JSON_THROW_ON_ERROR));
                $updates['focus_nfse_response'] = null;
            }

            if ($error !== null) {
                $updates['focus_nfse_error'] = Crypt::encryptString(json_encode($error, JSON_THROW_ON_ERROR));
            }

            if ($updates !== []) {
                DB::table('services')->where('id', $service->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('focus_nfse_response_secure');
        });
    }

    private function decodeJson(mixed $value): ?array
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }
};
