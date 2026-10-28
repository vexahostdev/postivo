<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DispatchStatus;
use App\Exceptions\IngestException;
use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Services\DispatchStateMachine;
use App\Services\EmailIngestService;
use App\Support\ApiError;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EmailController extends Controller
{
    public static function rules(string $p = ''): array
    {
        return [
            $p.'from' => ['required', 'string', 'max:255'],
            $p.'to' => ['required', 'array', 'min:1'],
            $p.'to.*' => ['required', 'email:rfc'],
            $p.'cc' => ['nullable', 'array'],
            $p.'cc.*' => ['email:rfc'],
            $p.'bcc' => ['nullable', 'array'],
            $p.'bcc.*' => ['email:rfc'],
            $p.'reply_to' => ['nullable', 'email:rfc'],
            $p.'subject' => ['required', 'string', 'max:255'],
            // mediumText MySQL ±16 MB; 3 juta karakter tetap aman untuk UTF-8.
            $p.'html' => ['nullable', 'string', 'max:3000000', 'required_without:'.$p.'text'],
            $p.'text' => ['nullable', 'string', 'max:3000000'],
            $p.'headers' => ['nullable', 'array', function (string $attribute, mixed $value, \Closure $fail) {
                foreach (array_keys((array) $value) as $name) {
                    if (! EmailIngestService::isValidHeaderName((string) $name)) {
                        $fail('Invalid email header field name.');

                        return;
                    }
                }
            }],
            $p.'headers.*' => ['string', 'max:2000'],
            $p.'attachments' => ['nullable', 'array', 'max:10', function (string $attribute, mixed $value, \Closure $fail) {
                $bytes = 0;
                foreach ((array) $value as $a) {
                    // Perkiraan ukuran ter-decode tanpa melakukan decode penuh di tahap validasi.
                    $bytes += is_array($a) && is_string($a['content'] ?? null) ? (int) ceil(strlen($a['content']) * 3 / 4) : 0;
                }
                if ($bytes > (int) config('postivo.limits.max_payload_bytes')) {
                    $fail('Total attachment size exceeds the maximum message size.');
                }
            }],
            $p.'attachments.*.filename' => ['required', 'string', 'max:255'],
            $p.'attachments.*.content' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || base64_decode($value, true) === false) {
                    $fail('Attachment content must be valid base64.');
                }
            }],
            $p.'attachments.*.content_type' => ['nullable', 'string', 'max:127'],
            $p.'tags' => ['nullable', 'array', 'max:10'],
            $p.'tags.*' => ['string', 'max:64'],
            $p.'metadata' => ['nullable', 'array'],
            $p.'track_opens' => ['nullable', 'boolean'],
            $p.'track_clicks' => ['nullable', 'boolean'],
            $p.'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function store(Request $request, EmailIngestService $ingest): JsonResponse
    {
        $data = Validator::make($request->all(), self::rules())->validate();
        [$email, $replay] = $this->accept($ingest, $request, $data, $request->header('Idempotency-Key'));

        return response()->json($this->accepted($email), $replay ? 200 : 202);
    }

    public function batch(Request $request, EmailIngestService $ingest): JsonResponse
    {
        $items = $request->input('emails');
        if (! is_array($items) || $items === [] || count($items) > config('postivo.limits.max_batch')) {
            return ApiError::make('validation_failed', 'emails must be an array of 1-'.config('postivo.limits.max_batch').' items.', 422);
        }

        $base = $request->header('Idempotency-Key');
        $results = [];
        foreach ($items as $i => $item) {
            $v = Validator::make(is_array($item) ? $item : [], self::rules());
            if ($v->fails()) {
                $results[] = ['index' => $i, 'status' => 422, 'error' => ['code' => 'validation_failed', 'details' => $v->errors()->toArray()]];

                continue;
            }
            try {
                [$email] = $this->accept($ingest, $request, $v->validated(), $base ? $base.':'.$i : null);
                $results[] = ['index' => $i, 'status' => 202, 'data' => $this->accepted($email)];
            } catch (IngestException $e) {
                $results[] = ['index' => $i, 'status' => $e->httpStatus, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
            }
        }

        return response()->json(['results' => $results], 207);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $q = Email::query()->with('dispatches', 'domain')->orderByDesc('created_at')->orderByDesc('id');

        if ($tag = $request->query('tag')) {
            $q->where('tags', 'like', '%"'.str_replace(['\\', '%', '_', '"'], '', $tag).'"%');
        }
        if ($status = $request->query('status')) {
            $q->whereHas('dispatches', fn ($d) => $d->where('status', strtoupper($status)));
        }
        if ($to = $request->query('to')) {
            $q->whereHas('dispatches', fn ($d) => $d->where('recipient', strtolower($to)));
        }
        if ($from = $request->query('from_date')) {
            $q->where('created_at', '>=', $from);
        }
        if ($until = $request->query('to_date')) {
            $q->where('created_at', '<=', $until);
        }

        $page = $q->cursorPaginate($limit)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($e) => Present::email($e))->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Present::email(Email::query()->findOrFail($id), true));
    }

    public function events(string $id): JsonResponse
    {
        $e = Email::query()->findOrFail($id);

        return response()->json(['data' => Present::email($e, true)['events']]);
    }

    public function cancel(string $id, DispatchStateMachine $machine): JsonResponse
    {
        return DB::transaction(function () use ($id, $machine) {
            // Same parent-first lock as the worker; never decide using eager-loaded stale state.
            $email = Email::query()->lockForUpdate()->findOrFail($id);
            $dispatches = $email->dispatches()->withoutGlobalScope('organization')->lockForUpdate()->get();
            $cancelable = $dispatches->filter(fn ($d) => $d->status === DispatchStatus::QUEUED);
            if ($email->canceled_at || $dispatches->contains(fn ($d) => $d->status === DispatchStatus::SENDING) || $cancelable->isEmpty()) {
                return ApiError::make('conflict', 'Email is no longer cancelable.', 409);
            }
            $email->update(['canceled_at' => now()]);
            foreach ($cancelable as $d) {
                $machine->transition($d, DispatchStatus::FAILED, null, ['smtp_response' => 'canceled']);
                // record() immediately fans out webhook jobs, so wait for the outermost commit.
                DB::afterCommit(fn () => $machine->record($d, 'failed', ['reason' => 'canceled']));
            }

            return response()->json(Present::email($email->refresh()));
        });
    }

    private function accept(EmailIngestService $ingest, Request $request, array $data, ?string $idem): array
    {
        $data['track_opens'] = $data['track_opens'] ?? null;
        $data['track_clicks'] = $data['track_clicks'] ?? null;

        return $ingest->accept($request->attributes->get('org'), $request->attributes->get('api_key'), $data, 'API', $idem);
    }

    private function accepted(Email $email): array
    {
        $p = Present::email($email);

        return ['id' => $p['id'], 'status' => 'queued', 'created_at' => $p['created_at'], 'recipients' => $p['recipients']];
    }
}
