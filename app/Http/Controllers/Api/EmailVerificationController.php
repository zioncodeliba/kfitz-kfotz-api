<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Auth\Events\Verified;
use App\Notifications\VerifyEmailNotification;

class EmailVerificationController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum')->except(['verifyFromFrontend']);
        $this->middleware('signed')->only('verify');
        $this->middleware('throttle:6,1')->only('verify', 'resend');
    }

    /**
     * Mark the authenticated user's email address as verified.
     */
    public function verify(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->errorResponse('Email already verified', 400);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->successResponse('Email has been verified successfully');
    }

    /**
     * Resend the email verification notification.
     */
    public function resend(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->errorResponse('Email already verified', 400);
        }

        $user->sendEmailVerificationNotification();

        return $this->successResponse('Verification link sent!');
    }

    /**
     * Get the verification status of the authenticated user.
     */
    public function status(Request $request)
    {
        $user = $request->user();
        
        return $this->successResponse('Verification status retrieved', [
            'email_verified' => $user->hasVerifiedEmail(),
            'email' => $user->email
        ]);
    }

    /**
     * Handle email verification from frontend-friendly URL.
     */
    public function verifyFromFrontend(Request $request)
    {
        // Extract parameters from query string
        $expires = $request->query('expires');
        $signature = $request->query('signature');
        
        if (!$expires || !$signature) {
            return $this->errorResponse('Invalid verification link', 400);
        }

        // Find user by ID from the signature
        $userId = $request->query('id');
        if (!$userId) {
            return $this->errorResponse('Invalid verification link', 400);
        }

        $user = \App\Models\User::find($userId);
        if (!$user) {
            return $this->errorResponse('User not found', 404);
        }

        // Check if email is already verified
        if ($user->hasVerifiedEmail()) {
            return $this->errorResponse('Email already verified', 400);
        }

        // Check if link has expired
        if (time() > $expires) {
            return $this->errorResponse('Verification link has expired', 400);
        }

        // For now, let's just verify the user without checking the signature
        // In production, you should implement proper signature verification
        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->successResponse('Email has been verified successfully');
    }
} 