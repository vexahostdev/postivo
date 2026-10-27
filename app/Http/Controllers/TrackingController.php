<?php

namespace App\Http\Controllers;

use App\Models\EmailDispatch;
use App\Models\TrackingLink;
use App\Services\DispatchStateMachine;
use App\Services\QuotaService;
use App\Services\SuppressionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TrackingController extends Controller
{
    // GIF 1x1 transparan 43 byte.
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(private DispatchStateMachine $machine) {}

    public function open(Request $request, string $token): Response
    {
        $d = EmailDispatch::withoutGlobalScopes()->where('open_token', $token)->first();
        if ($d) {
            $bot = $this->isBot($request);
            if (! $bot && ! $d->first_opened_at) {
                $d->forceFill(['first_opened_at' => now()])->saveQuietly();
            }
            $this->machine->record($d, 'opened', [], $this->meta($request, $bot));
        }

        return response(base64_decode(self::GIF), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, string $token)
    {
        $link = TrackingLink::where('token', $token)->first();
        abort_unless($link && preg_match('#^https?://#i', $link->url), 404);

        $d = EmailDispatch::withoutGlobalScopes()->find($link->dispatch_id);
        if ($d) {
            $bot = $this->isBot($request);
            $link->increment('click_count');
            if (! $bot && ! $d->first_clicked_at) {
                $d->forceFill(['first_clicked_at' => now()])->saveQuietly();
            }
            $this->machine->record($d, 'clicked', ['url' => $link->url], $this->meta($request, $bot));
        }

        return redirect()->away($link->url, 302, ['Cache-Control' => 'no-store']);
    }

    /** GET → halaman konfirmasi; POST (RFC 8058 one-click) → berhenti langganan. */
    public function unsubscribe(Request $request, string $token, SuppressionService $supp, QuotaService $quota)
    {
        $d = EmailDispatch::withoutGlobalScopes()->where('unsubscribe_token', $token)->firstOrFail();

        if ($request->isMethod('post')) {
            $supp->add($d->organization_id, $d->recipient, 'UNSUBSCRIBE', $d->id);
            $this->machine->record($d, 'unsubscribed');

            return $request->hasHeader('List-Unsubscribe') || $request->input('List-Unsubscribe') ? response('', 200) : view('public.unsubscribed', ['done' => true]);
        }

        return view('public.unsubscribed', ['done' => false, 'token' => $token, 'recipient' => $d->recipient]);
    }

    private function isBot(Request $r): bool
    {
        return (bool) preg_match('/GoogleImageProxy|YahooMailProxy|Barracuda|Proofpoint|Mimecast|bot|crawler|spider|HeadlessChrome/i', (string) $r->userAgent());
    }

    private function meta(Request $r, bool $bot): array
    {
        return ['ip' => $r->ip(), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 512), 'is_bot' => $bot];
    }
}
