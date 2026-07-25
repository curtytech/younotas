<?php

namespace App\Http\Controllers;

use App\Actions\HandleFocusNfeWebhookAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FocusNfeWebhookController extends Controller
{
    public function __invoke(Request $request, HandleFocusNfeWebhookAction $action): JsonResponse
    {
        $secret = (string) config('services.focus_nfe.webhook_secret');
        $provided = (string) ($request->header('X-Focus-Nfe-Secret') ?: $request->query('token'));
        if (blank($secret)) {
            return response()->json(['message' => 'Webhook não configurado.'], 503);
        }
        if (! hash_equals($secret, $provided)) {
            return response()->json(['message' => 'Não autorizado.'], 401);
        }

        $payload = $request->all();
        $validator = Validator::make($payload, [
            'ref' => ['nullable', 'string', 'max:255', 'required_without:referencia'],
            'referencia' => ['nullable', 'string', 'max:255', 'required_without:ref'],
            'status' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Payload de webhook inválido.', 'errors' => $validator->errors()], 422);
        }

        $sale = $action->execute($payload);

        return response()->json([
            'success' => true,
            'message' => $sale ? 'Webhook NF-e processado com sucesso.' : 'Webhook recebido para reconciliação.',
            'sale_id' => $sale?->id,
            'status' => $sale?->focus_nfe_status,
        ], $sale ? 200 : 202);
    }
}
