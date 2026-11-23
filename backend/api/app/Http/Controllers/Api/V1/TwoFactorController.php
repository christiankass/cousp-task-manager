<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Security\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Activation et désactivation de la double authentification par l'utilisateur lui-même. */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor, private AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'enabled' => $user->hasTwoFactor(),
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601ZuluString(),
            'recovery_codes_left' => $user->hasTwoFactor() ? count($user->two_factor_recovery_codes ?? []) : 0,
            'recommended' => $user->isPrivileged(),
        ]);
    }

    /** Étape 1 : nouvelle clé (pas encore active tant qu'elle n'est pas confirmée). */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->hasTwoFactor(), 409, __('auth.mfa_already_enabled'));

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        return ApiResponse::success([
            'secret' => $secret,
            'otpauth_url' => $this->twoFactor->otpauthUrl($user, $secret),
        ]);
    }

    /** Étape 2 : le premier code valide active la protection et donne les codes de secours. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $request->user();
        abort_if($user->hasTwoFactor(), 409, __('auth.mfa_already_enabled'));
        abort_unless($user->two_factor_secret, 409, __('auth.mfa_not_started'));

        if (! $this->twoFactor->verifyCode($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => [__('auth.mfa_invalid')]]);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        $this->audit->log('auth.mfa_enabled', $user, actor: $user);

        return ApiResponse::success(['recovery_codes' => $codes], __('auth.mfa_enabled'));
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:20']]);
        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => [__('auth.password')]]);
        }
        if ($user->hasTwoFactor() && ! $this->twoFactor->verify($user, $data['code'] ?? null, $data['code'] ?? null)) {
            throw ValidationException::withMessages(['code' => [__('auth.mfa_invalid')]]);
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        $this->audit->log('auth.mfa_disabled', $user, actor: $user);

        return ApiResponse::success(null, __('auth.mfa_disabled'));
    }
}
