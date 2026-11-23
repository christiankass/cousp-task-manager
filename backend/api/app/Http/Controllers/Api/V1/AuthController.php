<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstitutionResource;
use App\Http\Resources\UserResource;
use App\Models\DeviceToken;
use App\Models\Institution;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Security\TwoFactorService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 900;

    public const MFA_CHALLENGE_MINUTES = 5;

    public function __construct(private TenantContext $context, private AuditLogger $audit) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string'],
            'institution_code' => ['nullable', 'string', 'max:30'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $email = Str::lower($data['email']);
        $throttleKey = 'login:'.$email.'|'.$request->ip();

        // Verrouillage après plusieurs tentatives infructueuses (§8.4).
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $this->recordLogin($request, $email, null, null, false, 'locked');

            return ApiResponse::error(
                __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)]),
                'ACCOUNT_LOCKED',
                429
            );
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);
            $this->recordLogin($request, $email, $user, null, false, 'bad_credentials');

            return ApiResponse::error(__('auth.failed'), 'INVALID_CREDENTIALS', 401);
        }

        if (! $user->isActive()) {
            $this->recordLogin($request, $email, $user, null, false, 'inactive');

            return ApiResponse::error(__('messages.account_inactive'), 'ACCOUNT_INACTIVE', 403);
        }

        $institution = $this->resolveInstitution($user, $data['institution_code'] ?? null);

        if (! $institution) {
            $this->recordLogin($request, $email, $user, null, false, 'no_institution');

            return ApiResponse::error(__('messages.institution_required'), 'INSTITUTION_REQUIRED', 422, [
                'institution_code' => [__('messages.institution_required')],
            ]);
        }

        RateLimiter::clear($throttleKey);

        if ($user->hasTwoFactor()) {
            // Mot de passe correct : on demande encore le code de l'application (§29.2).
            $challenge = Str::random(64);
            Cache::put('mfa:challenge:'.hash('sha256', $challenge), [
                'user_id' => $user->id, 'institution_id' => $institution->id,
                'device_name' => $data['device_name'] ?? 'web', 'attempts' => 0,
            ], now()->addMinutes(self::MFA_CHALLENGE_MINUTES));

            return ApiResponse::success(['mfa_required' => true, 'challenge_token' => $challenge], __('auth.mfa_code_required'));
        }

        return $this->issueToken($request, $user, $institution, $data['device_name'] ?? 'web');
    }

    /** Deuxième étape de connexion : code de l'application ou code de secours. */
    public function verifyTwoFactor(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        $key = 'mfa:challenge:'.hash('sha256', $data['challenge_token']);
        $challenge = Cache::get($key);
        if (! $challenge) {
            return ApiResponse::error(__('auth.mfa_expired'), 'MFA_EXPIRED', 401);
        }

        $user = User::query()->find($challenge['user_id']);
        $institution = Institution::query()->find($challenge['institution_id']);

        if (! $user || ! $institution || ! $twoFactor->verify($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            $challenge['attempts']++;
            $challenge['attempts'] >= self::MAX_ATTEMPTS
                ? Cache::forget($key)
                : Cache::put($key, $challenge, now()->addMinutes(self::MFA_CHALLENGE_MINUTES));
            $this->recordLogin($request, $user?->email ?? '', $user, null, false, 'bad_mfa_code');

            return ApiResponse::error(__('auth.mfa_invalid'), 'MFA_INVALID', 401);
        }

        Cache::forget($key);

        return $this->issueToken($request, $user, $institution, $challenge['device_name']);
    }

    private function issueToken(Request $request, User $user, Institution $institution, string $deviceName): JsonResponse
    {
        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 480));
        $token = $user->createToken($deviceName, ['*'], $expiresAt);
        $token->accessToken->forceFill(['institution_id' => $institution->id])->save();

        $user->forceFill(['last_login_at' => now()])->save();
        $this->recordLogin($request, $user->email, $user, $institution, true);

        $this->context->runAs($institution, fn () => $this->audit->log('auth.login', $user, actor: $user));

        return ApiResponse::success([
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->utc()->toIso8601ZuluString(),
            'user' => (new UserResource($user))->resolve($request),
            'institution' => (new InstitutionResource($institution))->resolve($request),
        ], __('messages.login_success'));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->log('auth.logout', $request->user());

        // Le téléphone ne reçoit plus les notifications de ce compte.
        if ($request->filled('device_token')) {
            DeviceToken::query()->where('user_id', $request->user()->id)->where('token', $request->string('device_token'))->delete();
        }
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, __('messages.logout_success'));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['organizationUnits', 'roleAssignments.role']);

        return ApiResponse::success([
            'user' => (new UserResource($user))->resolve($request),
            'institution' => (new InstitutionResource($this->context->institution()))->resolve($request),
            'permissions' => $user->permissionCodes(),
            'is_platform_admin' => $user->is_platform_admin,
            'security' => [
                'mfa_enabled' => $user->hasTwoFactor(),
                'mfa_recommended' => ! $user->hasTwoFactor() && $user->isPrivileged(),
                'mfa_enforced' => ! $user->hasTwoFactor() && $user->isPrivileged() && (bool) config('cousp.mfa_enforce_privileged'),
            ],
        ]);
    }

    /** Institutions auxquelles le compte connecté a accès. */
    public function institutions(Request $request): JsonResponse
    {
        $institutions = $this->context->withoutIsolation(fn () => $request->user()->institutions()
            ->wherePivot('status', 'active')
            ->where('institutions.status', 'active')
            ->orderBy('name')
            ->get());

        return ApiResponse::success(InstitutionResource::collection($institutions)->resolve($request));
    }

    /** L'utilisateur choisit sa langue (interface, e-mails, notifications). */
    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate(['preferred_language' => ['required', 'in:fr,en']]);
        $request->user()->forceFill($data)->save();
        app()->setLocale($data['preferred_language']);

        return ApiResponse::success(['preferred_language' => $data['preferred_language']], __('messages.updated'));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $data['password']])->save();

        // Révoque toutes les autres sessions.
        $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();
        $this->audit->log('auth.password_changed', $user);

        return ApiResponse::success(null, __('messages.password_changed'));
    }

    private function resolveInstitution(User $user, ?string $code): ?Institution
    {
        return $this->context->withoutIsolation(function () use ($user, $code) {
            $query = $user->institutions()
                ->wherePivot('status', 'active')
                ->where('institutions.status', 'active');

            if ($code !== null && $code !== '') {
                return $query->where('institutions.code', Str::upper($code))->first();
            }

            // Sans code, la connexion n'est possible que si le compte n'a qu'une institution.
            $institutions = $query->limit(2)->get();

            return $institutions->count() === 1 ? $institutions->first() : null;
        });
    }

    private function recordLogin(Request $request, string $email, ?User $user, ?Institution $institution, bool $ok, ?string $reason = null): void
    {
        LoginHistory::query()->create([
            'user_id' => $user?->id,
            'institution_id' => $institution?->id,
            'email' => $email,
            'succeeded' => $ok,
            'failure_reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
