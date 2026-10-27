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
            $p.'html' => ['nullable', 'string', 'required_without:'.$p.'text'],
            $p.'text' => ['nullable', 'string'],
            $p.'headers' => ['nullable', 'array'],
            $p.'attachments' => ['nullable', 'array', 'max:10'],
            $p.'attachments.*.filename' => ['required', 'string', 'max:255'],
            $p.'attachments.*.content' => ['required', 'string'],
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
            $q->where('tags', 'like', '%"'.str_replace(['%', '_', '"'], '', $tag).'"%');
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
        $email = Email::query()->with('dispatches')->findOrFail($id);
        $cancelable = $email->dispatches->filter(fn ($d) => $d->status === DispatchStatus::QUEUED);
        if ($cancelable->isEmpty()) {
            return ApiError::make('conflict', 'Email is no longer cancelable.', 409);
        }
        $email->update(['canceled_at' => now()]);
        foreach ($cancelable as $d) {
            $machine->transition($d, DispatchStatus::FAILED, 'failed', ['smtp_response' => 'canceled'], ['reason' => 'canceled']);
        }

        return response()->json(Present::email($email->refresh()));
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
