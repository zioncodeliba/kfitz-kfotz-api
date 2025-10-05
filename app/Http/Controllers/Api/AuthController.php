<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Role;
use App\Notifications\VerifyEmailNotification;

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
        ]);

        // הקצאת תפקיד ברירת מחדל (user)
        $defaultRole = Role::where('name', 'user')->first();
        if ($defaultRole) {
            $user->roles()->attach($defaultRole->id);
        }

        // Send email verification notification
        $user->sendEmailVerificationNotification();

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->createdResponse([
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
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

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ], 'Login successful');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        
        return $this->successResponse(null, 'Logged out successfully');
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('roles');
        
        return $this->successResponse(new UserResource($user));
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return $this->notFoundResponse('User not found');
        }
        $token = app('auth.password.broker')->createToken($user);
        // שליחת מייל (פשוטה, אפשר להחליף ל־Notification)
        \Mail::raw('Reset your password: ' . url('/reset-password?token=' . $token), function ($message) use ($user) {
            $message->to($user->email)->subject('Password Reset');
        });
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
}

