<?php

namespace App\Http\Controllers\Internal;

use App\Exceptions\IngestException;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\ApiKeyService;
use App\Services\EmailIngestService;
use App\Services\MimeMessageParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Dipanggil smtp-engine (HMAC). Laravel tetap sumber kebenaran: key dimuat dari DB, bukan dari klien. */
class SmtpController extends Controller
{
    public function auth(Request $request, ApiKeyService $keys): JsonResponse
    {
        $key = $keys->findByPlain((string) $request->input('password'));
        $org = $key && ! $key->isRevoked() ? Organization::find($key->organization_id) : null;

        if (! $key || ! $org || ! $key->can('emails:send')) {
            return response()->json(['ok' => false], 401);
        }

        return response()->json(['ok' => true, 'api_key_id' => $key->id, 'org_status' => $org->status]);
    }

    public function ingest(Request $request, EmailIngestService $ingest, MimeMessageParser $parser): JsonResponse
    {
        $data = $request->validate([
            'ingest_id' => ['required', 'string'], 'api_key_id' => ['required', 'string'],
            'spool_path' => ['required', 'string'], 'rcpt_to' => ['required', 'array', 'min:1'], 'rcpt_to.*' => ['email'],
        ]);

        // Cegah path traversal: file harus di dalam direktori spool.
        $spool = realpath(config('postivo.smtp.spool'));
        $file = realpath($data['spool_path']);
        if (! $spool || ! $file || ! str_starts_with($file, $spool.DIRECTORY_SEPARATOR)) {
            return $this->fail('forbidden', 'Invalid spool path.', 403);
        }

        try {
            $key = \App\Models\ApiKey::withoutGlobalScopes()->find($data['api_key_id']);
            $org = $key && ! $key->isRevoked() && $key->can('emails:send') ? Organization::find($key->organization_id) : null;
            if (! $org) {
                return $this->fail('unauthenticated', 'Invalid API key.', 401);
            }
            if (filesize($file) > config('postivo.limits.max_payload_bytes')) {
                return $this->fail('payload_too_large', 'Message too large.', 413);
            }

            $payload = $parser->parse($file, $data['rcpt_to']);
            [$email] = $ingest->accept($org, $key, $payload, 'SMTP');
            @unlink($file);

            return response()->json(['email_id' => $email->id], 202);
        } catch (IngestException $e) {
            @unlink($file);

            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        // Error tak terduga → 500: engine menerjemahkan ke 451 (klien akan retry) dan spool dibuang di sisi engine.
    }

    private function fail(string $code, string $msg, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $msg]], $status);
    }
}
