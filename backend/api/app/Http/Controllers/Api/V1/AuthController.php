<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstitutionResource;
use App\Http\Resources\UserResource;
use App\Models\Institution;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 900;

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

        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 480));
        $token = $user->createToken($data['device_name'] ?? 'web', ['*'], $expiresAt);
        $token->accessToken->forceFill(['institution_id' => $institution->id])->save();

        $user->forceFill(['last_login_at' => now()])->save();
        $this->recordLogin($request, $email, $user, $institution, true);

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
