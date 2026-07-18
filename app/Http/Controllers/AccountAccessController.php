<?php

namespace App\Http\Controllers;

use App\Actions\ConsumeAccountAccessChallenge;
use App\Actions\IssueAccountAccessChallenge;
use App\Http\Requests\RequestAccountAccess;
use App\Models\Account;
use App\Models\AccountAccessChallenge;
use App\Support\AccountAccessAbuseControl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountAccessController extends Controller
{
    public const NEUTRAL_STATUS = 'If the address can receive email, a sign-in code is on its way.';

    public const INVALID_STATUS = 'That sign-in code or link is invalid or has expired.';

    public function store(
        RequestAccountAccess $request,
        IssueAccountAccessChallenge $issueAccountAccessChallenge,
        AccountAccessAbuseControl $abuseControl,
    ): RedirectResponse {
        $email = $request->string('email')->value();

        if ($abuseControl->attemptSend($email, $request->ip() ?? 'unknown')) {
            $challenge = $issueAccountAccessChallenge->handle($email);

            $request->session()->put('account_access_challenge', $challenge->public_id);
        }

        return redirect()->route(Auth::guest() ? 'login' : 'password.confirm')
            ->with('status', self::NEUTRAL_STATUS);
    }

    public function consumeMagicLink(
        Request $request,
        string $challenge,
        string $token,
        ConsumeAccountAccessChallenge $consumeAccountAccessChallenge,
    ): RedirectResponse {
        if (! $request->hasValidSignature()) {
            return $this->invalidChallenge($request);
        }

        if (! $this->challengeBelongsToAuthenticatedAccount($challenge)) {
            return $this->invalidChallenge($request);
        }

        $account = $consumeAccountAccessChallenge->usingToken($challenge, $token);

        if ($account === null) {
            return $this->invalidChallenge($request);
        }

        return $this->establishSession($request, $account);
    }

    public function consumeCode(
        Request $request,
        ConsumeAccountAccessChallenge $consumeAccountAccessChallenge,
        AccountAccessAbuseControl $abuseControl,
    ): RedirectResponse {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        $publicId = $request->session()->get('account_access_challenge');

        if (! is_string($publicId)) {
            return $this->invalidChallenge($request);
        }

        $challenge = AccountAccessChallenge::query()
            ->where('public_id', $publicId)
            ->first();

        if ($challenge === null
            || ! $this->challengeBelongsToAuthenticatedAccount($publicId)
            || ! $abuseControl->attemptVerification($challenge->email, $request->ip() ?? 'unknown')) {
            return $this->invalidChallenge($request);
        }

        $account = $consumeAccountAccessChallenge->usingCode($publicId, $validated['code']);

        if ($account === null) {
            return $this->invalidChallenge($request);
        }

        return $this->establishSession($request, $account);
    }

    private function invalidChallenge(Request $request): RedirectResponse
    {
        return redirect()->route(Auth::guest() ? 'login' : 'password.confirm')
            ->withErrors(['access' => self::INVALID_STATUS]);
    }

    private function establishSession(Request $request, Account $account): RedirectResponse
    {
        $authenticatedAccount = Auth::user();

        if ($authenticatedAccount instanceof Account && ! $authenticatedAccount->is($account)) {
            return $this->invalidChallenge($request);
        }

        if ($authenticatedAccount === null) {
            Auth::login($account);
            $request->session()->regenerate();
        }

        $request->session()->passwordConfirmed();
        $request->session()->forget('account_access_challenge');

        $destination = $account->hasCompleteProfile()
            ? route('dashboard')
            : route('profile.edit');

        return redirect()->intended($destination);
    }

    private function challengeBelongsToAuthenticatedAccount(string $publicId): bool
    {
        $account = Auth::user();

        if (! $account instanceof Account) {
            return true;
        }

        return AccountAccessChallenge::query()
            ->where('public_id', $publicId)
            ->where('email', $account->email)
            ->exists();
    }
}
