<?php
use App\Http\Controllers\{AdminAuthController,AdminWebController};use App\Http\Middleware\EnsureAdmin;use Illuminate\Support\Facades\Route;
Route::redirect('/','/admin');
Route::middleware('guest')->group(function(){Route::get('/admin/login',[AdminAuthController::class,'form'])->name('login');Route::post('/admin/login',[AdminAuthController::class,'login'])->middleware('throttle:6,1');});
Route::middleware(['auth',EnsureAdmin::class])->prefix('admin')->group(function(){Route::get('/',[AdminWebController::class,'dashboard'])->name('admin.dashboard');Route::post('/logout',[AdminAuthController::class,'logout'])->name('admin.logout');Route::get('/domain/{domain}',[AdminWebController::class,'domain'])->name('admin.domain');});

Route::get('/api/documentations', [\App\Http\Controllers\ApiDocumentationController::class, 'index'])->name('api.documentations');
Route::get('/api/documentations/openapi.json', [\App\Http\Controllers\ApiDocumentationController::class, 'specification'])->name('api.documentations.specification');
