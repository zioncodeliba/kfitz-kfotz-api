<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\MerchantPopup;
use App\Services\InforuEmailService;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'merchant',
        ]);

        // Send email verification notification
        $user->sendEmailVerificationNotification();

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->createdResponse([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'User registered successfully. Please check your email to verify your account.');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->unauthorizedResponse('Invalid credentials');
        }

        if ($user->role === 'merchant' && $user->status === 'blocked') {
            return $this->forbiddenResponse('הכניסה שלך למערכת נחסמה. אנא פנה לתמיכה.');
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Login successful');
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user && $user->role === 'merchant') {
            $merchantId = optional($user->merchant)->id;

            MerchantPopup::query()
                ->where('is_active', true)
                ->where(function ($query) use ($merchantId) {
                    $query->whereNull('merchant_id');
                    if ($merchantId !== null) {
                        $query->orWhere('merchant_id', $merchantId);
                    }
                })
                ->update(['display_once' => false]);
        }

        $user?->currentAccessToken()?->delete();
        
        return $this->successResponse(null, 'Logged out successfully');
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return $this->successResponse(new UserResource($user));
    }

    public function forgotPassword(Request $request, InforuEmailService $emailService)
    {
        $request->validate(['email' => 'required|email']);
        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return $this->notFoundResponse('User not found');
        }
        $token = app('auth.password.broker')->createToken($user);
        $resetUrl = $this->buildPasswordResetUrl($token, $user->email);
        $body = $emailService->buildBody(null, 'Reset your password: ' . $resetUrl);
        $emailService->sendEmail([
            [
                'email' => $user->email,
                'name' => $user->name,
            ],
        ], 'Password Reset', $body, [
            'event_key' => 'auth.password_reset',
        ]);
        return $this->successResponse(null, 'Password reset email sent');
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:6|confirmed',
        ]);
        $status = \Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = bcrypt($password);
                $user->save();
            }
        );
        if ($status == \Password::PASSWORD_RESET) {
            return $this->successResponse(null, 'Password reset successful');
        }
        return $this->errorResponse('Invalid token or email', 400);
    }

    private function buildPasswordResetUrl(string $token, string $email): string
    {
        $frontendUrl = trim((string) config('app.frontend_url', config('app.url', '')));
        if ($frontendUrl === '') {
            $frontendUrl = (string) config('app.url');
        }

        $query = http_build_query([
            'token' => $token,
            'email' => $email,
        ], '', '&', PHP_QUERY_RFC3986);

        $base = $frontendUrl;
        $hashPath = '';

        if (str_contains($frontendUrl, '#')) {
            [$base, $hash] = explode('#', $frontendUrl, 2);
            $hashPath = trim($hash ?? '', '/');
        }

        $base = rtrim($base, '/');
        $hashPath = $hashPath === '' ? 'reset-password' : $hashPath . '/reset-password';

        return $base . '/#/' . $hashPath . '?' . $query;
    }
}
