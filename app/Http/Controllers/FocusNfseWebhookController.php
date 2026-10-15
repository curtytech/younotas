<?php

namespace App\Http\Controllers;

use App\Actions\HandleFocusNfseWebhookAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FocusNfseWebhookController extends Controller
{
    public function __invoke(Request $request, HandleFocusNfseWebhookAction $action): JsonResponse
    {
        $secret = (string) config('services.focus_nfe.webhook_secret');

        if (blank($secret)) {
            return response()->json(['message' => 'Webhook não configurado.'], 503);
        }

        if (! hash_equals($secret, (string) ($request->header('X-Focus-Nfse-Secret') ?: $request->query('token')))) {
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

        $service = $action->execute($payload);

        return response()->json([
            'success' => true,
            'message' => $service ? 'Webhook NFS-e processado com sucesso.' : 'Webhook recebido para reconciliação.',
            'service_order_id' => $service?->id,
            'status' => $service?->focus_nfse_status,
        ], $service ? 200 : 202);
    }
}
