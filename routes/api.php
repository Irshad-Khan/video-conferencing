<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\MeetingControlController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MeetingJoinController;
use App\Http\Controllers\Api\V1\MeetingMessageController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsNotDisabled;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return response()->json(['status' => 'ok']);
    });

    Route::post('/auth/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:register');
    Route::post('/auth/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/meetings/lookup/{code}', [MeetingJoinController::class, 'lookup'])
        ->middleware('throttle:30,1');
    Route::post('/meetings/{code}/join', [MeetingJoinController::class, 'join'])
        ->middleware('throttle:20,1');
    Route::post('/meetings/{code}/leave', [MeetingJoinController::class, 'leave'])
        ->middleware('throttle:30,1');

    Route::get('/meetings/{code}/messages', [MeetingMessageController::class, 'index'])
        ->middleware('throttle:60,1');
    Route::post('/meetings/{code}/messages', [MeetingMessageController::class, 'store'])
        ->middleware('throttle:30,1');
    Route::get('/meetings/{code}/messages/{message}/attachment', [MeetingMessageController::class, 'attachment'])
        ->middleware('throttle:60,1');

    Route::middleware(['auth:sanctum', EnsureUserIsNotDisabled::class])->group(function () {
        Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);

        Route::get('/meetings', [MeetingController::class, 'index']);
        Route::post('/meetings', [MeetingController::class, 'store']);
        Route::get('/meetings/{meeting}', [MeetingController::class, 'show']);
        Route::get('/meetings/{meeting}/participants', [MeetingController::class, 'participants']);
        Route::put('/meetings/{meeting}', [MeetingController::class, 'update']);
        Route::delete('/meetings/{meeting}', [MeetingController::class, 'destroy']);
        Route::post('/meetings/{meeting}/cancel', [MeetingController::class, 'cancel']);
        Route::post('/meetings/{meeting}/start', [MeetingController::class, 'start']);
        Route::post('/meetings/{meeting}/end', [MeetingController::class, 'end']);
        Route::post('/meetings/{meeting}/lock', [MeetingControlController::class, 'lock']);
        Route::post('/meetings/{meeting}/unlock', [MeetingControlController::class, 'unlock']);
        Route::post('/meetings/{meeting}/guests/enable', [MeetingControlController::class, 'enableGuests']);
        Route::post('/meetings/{meeting}/guests/disable', [MeetingControlController::class, 'disableGuests']);
        Route::post('/meetings/{meeting}/waiting-room/enable', [MeetingControlController::class, 'enableWaitingRoom']);
        Route::post('/meetings/{meeting}/waiting-room/disable', [MeetingControlController::class, 'disableWaitingRoom']);
        Route::get('/meetings/{meeting}/participants/waiting', [MeetingControlController::class, 'waitingParticipants']);
        Route::post('/meetings/{meeting}/participants/{participant}/admit', [MeetingControlController::class, 'admitParticipant']);
        Route::post('/meetings/{meeting}/participants/{participant}/reject', [MeetingControlController::class, 'rejectParticipant']);
        Route::post('/meetings/{meeting}/participants/{participant}/promote', [MeetingControlController::class, 'promoteToCoHost']);
        Route::post('/meetings/{meeting}/participants/{participant}/demote', [MeetingControlController::class, 'demoteFromCoHost']);
        Route::post('/meetings/{meeting}/participants/{participant}/mute', [MeetingControlController::class, 'muteParticipant']);
        Route::post('/meetings/{meeting}/participants/{participant}/spotlight', [MeetingControlController::class, 'spotlightParticipant']);
        Route::post('/meetings/{meeting}/spotlight/clear', [MeetingControlController::class, 'clearSpotlight']);
        Route::post('/meetings/{meeting}/participants/{participant}/remove', [MeetingControlController::class, 'removeParticipant']);

        Route::prefix('admin')->middleware(EnsureUserIsAdmin::class)->group(function () {
            Route::get('/stats', [AdminController::class, 'stats']);
            Route::get('/users', [AdminController::class, 'users']);
            Route::post('/users/{user}/disable', [AdminController::class, 'disableUser']);
            Route::post('/users/{user}/enable', [AdminController::class, 'enableUser']);
            Route::get('/meetings', [AdminController::class, 'meetings']);
            Route::get('/meetings/{meeting}/participants', [AdminController::class, 'meetingParticipants']);
            Route::delete('/meetings/{meeting}', [AdminController::class, 'deleteMeeting']);
        });
    });
});
