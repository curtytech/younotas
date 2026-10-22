<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessFocusWebhookJob;
use App\Services\FocusWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FocusNfeWebhookController extends Controller
{
    public function __invoke(Request $request, FocusWebhookProcessor $processor): JsonResponse
    {
        $secret = (string) config('services.focus_nfe.webhook_secret');
        if (blank($secret)) {
            return response()->json(['message' => 'Webhook não configurado.'], 503);
        }
        $provided = (string) ($request->header('X-Focus-Nfe-Secret') ?: $request->query('token'));
        if (! hash_equals($secret, $provided)) {
            return response()->json(['message' => 'Não autorizado.'], 401);
        }

        // Query-string credentials must never be persisted with the fiscal event.
        $payload = $request->json()->all();
        $validator = Validator::make($payload, [
            'ref' => ['nullable', 'string', 'max:255', 'required_without:referencia'],
            'referencia' => ['nullable', 'string', 'max:255', 'required_without:ref'],
            'status' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Payload de webhook inválido.', 'errors' => $validator->errors()], 422);
        }

        $event = $processor->capture('NF-e', $payload);
        ProcessFocusWebhookJob::dispatch('NF-e', $event->id);

        return response()->json(['success' => true, 'message' => 'Webhook persistido para processamento.', 'event_id' => $event->id], 202);
    }
}
