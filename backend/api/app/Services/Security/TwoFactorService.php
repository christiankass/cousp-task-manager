<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Codes à usage unique (TOTP, RFC 6238) et codes de secours. */
class TwoFactorService
{
    public function __construct(private Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(config('cousp.mfa_issuer'), $user->email, $secret);
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
    }

    /** Vérifie un code de l'application (tolérance ±30 s) sans permettre de le rejouer. */
    public function verifyCode(User $user, string $code, ?string $secret = null): bool
    {
        $secret ??= $user->two_factor_secret;
        $code = preg_replace('/\s+/', '', $code);
        if (! $secret || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $key = 'mfa:last:'.$user->id;
        $timestamp = $this->google2fa->verifyKeyNewer($secret, $code, Cache::get($key), 1);
        if ($timestamp === false) {
            return false;
        }

        Cache::put($key, $timestamp === true ? $this->google2fa->getTimestamp() : $timestamp, now()->addMinutes(5));

        return true;
    }

    /** Un code de secours ne sert qu'une fois. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalized = Str::upper(trim($code));
        $index = array_search($normalized, $codes, true);
        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    public function verify(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if ($code !== null && $code !== '') {
            return $this->verifyCode($user, $code);
        }

        return $recoveryCode !== null && $recoveryCode !== '' && $this->useRecoveryCode($user, $recoveryCode);
    }
}
