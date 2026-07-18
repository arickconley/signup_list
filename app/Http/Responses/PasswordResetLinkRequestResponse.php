<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetLinkRequestResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const STATUS = 'If an Account matches that email, a password reset link is on its way.';

    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => __(self::STATUS)]);
        }

        return back()
            ->withInput($request->only('email'))
            ->with('status', __(self::STATUS));
    }
}
