<?php

namespace App\Filament\Resources\ServiceOrderResource\Pages;

use App\Filament\Resources\ServiceOrderResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditServiceOrder extends EditRecord
{
    use \App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;

    protected static string $resource = ServiceOrderResource::class;

    /**
     * As regras do modelo usam os nomes dos atributos, mas o formulário espera os erros
     * no caminho de estado dos campos para exibi-los junto a eles.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $key): array => [
                        (str_starts_with($key, 'data.') ? $key : 'data.'.$key) => $messages,
                    ])
                    ->all(),
            );
        }
    }
}
