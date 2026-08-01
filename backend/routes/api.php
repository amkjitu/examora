<?php

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\LessonController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PredictionController;
use App\Http\Controllers\Api\V1\QuizController;
use App\Http\Controllers\Api\V1\TutorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Wave API
|--------------------------------------------------------------------------
| Wave ships JWT auth (POST /api/login, /register, /refresh, /logout) plus its
| own resource routes. The SPA and, later, the React Native app authenticate
| through these and then call the /api/v1 routes below with the same bearer
| token. One auth surface, two clients.
*/

Wave::api();

Route::middleware('auth:api')->get('/user', fn () => auth()->user());

/*
|--------------------------------------------------------------------------
| Examora v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Public: the exam catalogue is marketing surface as much as product.
    Route::get('exams', [ExamController::class, 'index']);
    Route::get('exams/{exam}', [ExamController::class, 'show']);
    Route::get('exams/{exam}/topics', [ExamController::class, 'topics']);
    Route::get('exams/{exam}/predictions', [PredictionController::class, 'index']);
    Route::get('exams/{exam}/predictions/backtest', [PredictionController::class, 'backtest']);

    Route::middleware('auth:api')->group(function () {
        Route::get('me', [MeController::class, 'show']);

        Route::post('exams/{exam}/enroll', [ExamController::class, 'enroll']);

        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::post('plan-items/{item}/complete', [DashboardController::class, 'completePlanItem']);

        // Lesson generation is the expensive path, so it is the metered one.
        Route::get('topics/{topic}/lesson', [LessonController::class, 'show'])
            ->middleware('lesson.quota');
        Route::post('lessons/{lesson}/complete', [LessonController::class, 'complete']);

        Route::post('quizzes', [QuizController::class, 'start']);
        Route::get('quizzes/{session}', [QuizController::class, 'show']);
        Route::post('quizzes/{session}/answer', [QuizController::class, 'answer']);
        Route::post('quizzes/{session}/complete', [QuizController::class, 'complete']);
        Route::get('quizzes/{session}/analysis', [QuizController::class, 'analysis']);

        Route::post('tutor/ask', [TutorController::class, 'ask']);

        // Uploads are always private. Promotion into the shared question bank is
        // an admin action in the Filament review queue, never an API parameter.
        Route::get('documents', [DocumentController::class, 'index']);
        Route::get('my-questions', [DocumentController::class, 'myQuestions']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{document}', [DocumentController::class, 'show']);
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
    });
});
