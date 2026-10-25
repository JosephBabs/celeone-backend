<?php

use App\Http\Controllers\Api\V1\Marketplace\AdminCareerController;
use App\Http\Controllers\Api\V1\Marketplace\AdminMarketplaceController;
use App\Http\Controllers\Api\V1\Marketplace\CatalogController;
use App\Http\Controllers\Api\V1\Marketplace\CompanyController;
use App\Http\Controllers\Api\V1\Marketplace\DiscoveryController;
use App\Http\Controllers\Api\V1\Marketplace\FedaPayWebhookController;
use App\Http\Controllers\Api\V1\Marketplace\JobController;
use App\Http\Controllers\Api\V1\Marketplace\ListingController;
use App\Http\Controllers\Api\V1\Marketplace\OrderController;
use App\Http\Controllers\Api\V1\Marketplace\PaymentController;
use App\Http\Controllers\Api\V1\Marketplace\PayoutRequestController;
use App\Http\Controllers\Api\V1\Marketplace\ProfessionalController;
use App\Http\Controllers\Api\V1\Marketplace\QuoteController;
use App\Http\Controllers\Api\V1\Marketplace\SellerController;
use App\Http\Controllers\Api\V1\Marketplace\ShopController;
use App\Http\Controllers\Api\V1\Marketplace\UploadController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/marketplace')->group(function () {
    Route::get('/feed', [CatalogController::class, 'feed']);
    Route::get('/shops/{shop}', [CatalogController::class, 'shop']);
    Route::get('/shops/{shop}/listings', [CatalogController::class, 'shopListings']);
    Route::get('/listings/{listing}', [CatalogController::class, 'listing']);
    Route::get('/public/professional-profiles/{profile}', [ProfessionalController::class, 'publicShare']);

    // Public career feed; approved/active offers only.
    Route::get('/jobs', [JobController::class, 'feed']);
    Route::get('/jobs/{job}', [JobController::class, 'show']);
    Route::post('/jobs/{job}/guest-applications', [JobController::class, 'applyGuest'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'throttle:marketplace'])->group(function () {
        Route::get('/recommendations', [CatalogController::class, 'recommendations']);
        Route::post('/discovery-events', [DiscoveryController::class, 'store']);

        Route::get('/me/shop', [ShopController::class, 'mine']);
        Route::post('/shops', [ShopController::class, 'store']);
        Route::patch('/shops/{shop}', [ShopController::class, 'update']);
        Route::post('/shops/{shop}/submit', [ShopController::class, 'submit']);

        Route::get('/me/company', [CompanyController::class, 'mine']);
        Route::patch('/me/company/capabilities', [CompanyController::class, 'updateCapabilities']);

        Route::get('/me/listings', [SellerController::class, 'listings']);
        Route::get('/me/dashboard', [SellerController::class, 'dashboard']);
        Route::get('/me/revenue', [SellerController::class, 'revenue']);

        Route::get('/me/payout-requests', [PayoutRequestController::class, 'index']);
        Route::post('/me/payout-requests', [PayoutRequestController::class, 'store']);

        Route::post('/shops/{shop}/listings', [ListingController::class, 'store']);
        Route::patch('/listings/{listing}', [ListingController::class, 'update']);
        Route::post('/listings/{listing}/submit', [ListingController::class, 'submit']);

        Route::post('/uploads/image', [UploadController::class, 'image'])->middleware('throttle:uploads');
        Route::post('/uploads/document', [UploadController::class, 'document'])->middleware('throttle:uploads');
        Route::get('/documents/{document}', [UploadController::class, 'downloadDocument']);
        Route::get('/documents/{document}/link', [UploadController::class, 'documentLink']);

        Route::get('/me/professional-profile', [ProfessionalController::class, 'mine']);
        Route::put('/me/professional-profile', [ProfessionalController::class, 'upsert']);
        Route::post('/me/professional-profile/photo', [ProfessionalController::class, 'photo']);
        Route::post('/me/professional-profile/submit', [ProfessionalController::class, 'submit']);
        Route::get('/professional-profiles/{profile}', [ProfessionalController::class, 'show']);
        Route::get('/company/candidates', [ProfessionalController::class, 'directory']);

        Route::get('/me/jobs', [JobController::class, 'mine']);
        Route::post('/jobs', [JobController::class, 'store']);
        Route::patch('/jobs/{job}', [JobController::class, 'update']);
        Route::post('/jobs/{job}/submit', [JobController::class, 'submit']);
        Route::post('/jobs/{job}/apply', [JobController::class, 'apply']);
        Route::get('/jobs/{job}/candidates', [JobController::class, 'candidates']);
        Route::get('/me/job-applications', [JobController::class, 'myApplications']);

        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/me/orders', [OrderController::class, 'mine']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/seller-status', [OrderController::class, 'sellerStatus']);
        Route::post('/orders/{order}/received', [OrderController::class, 'received']);
        Route::post('/orders/{order}/disputes', [OrderController::class, 'dispute']);

        Route::post('/orders/{order}/checkout', [PaymentController::class, 'checkout']);
        Route::post('/payments/{payment}/confirm', [PaymentController::class, 'confirm']);

        Route::post('/listings/{listing}/quote-requests', [QuoteController::class, 'store']);
    });
});

Route::get('v1/marketplace/documents/{document}/download', [UploadController::class, 'downloadSignedDocument'])
    ->middleware('signed')
    ->name('marketplace.documents.download');

Route::post('v1/marketplace/webhooks/fedapay', FedaPayWebhookController::class)
    ->name('marketplace.webhooks.fedapay');

Route::prefix('v1/admin/marketplace')
    ->middleware(['auth:sanctum', EnsureAdmin::class, 'throttle:admin'])
    ->group(function () {
        Route::get('/dashboard', [AdminMarketplaceController::class, 'dashboard']);
        Route::get('/notifications', [AdminMarketplaceController::class, 'notifications']);
        Route::get('/moderation', [AdminMarketplaceController::class, 'moderation']);

        Route::patch('/shops/{shop}/review', [AdminMarketplaceController::class, 'reviewShop']);
        Route::patch('/listings/{listing}/review', [AdminMarketplaceController::class, 'reviewListing']);

        Route::post('/orders/{order}/verify-delivery', [AdminMarketplaceController::class, 'verifyDelivery']);
        Route::post('/disputes/{dispute}/resolve', [AdminMarketplaceController::class, 'resolveDispute']);

        Route::patch('/payout-requests/{payout}/review', [AdminMarketplaceController::class, 'reviewPayoutRequest']);
        Route::post('/payout-batches', [AdminMarketplaceController::class, 'createPayoutBatch']);
        Route::post('/payout-batches/{batch}/mark-paid', [AdminMarketplaceController::class, 'markPayoutPaid']);

        // Career / enterprise moderation.
        Route::get('/career/moderation', [AdminCareerController::class, 'moderation']);
        Route::get('/detail/{kind}/{id}', [AdminCareerController::class, 'showEntity']);
        Route::patch('/jobs/{job}/review', [AdminCareerController::class, 'reviewJob']);
        Route::patch('/professional-profiles/{profile}/review', [AdminCareerController::class, 'reviewProfile']);
        Route::patch('/job-applications/{application}', [AdminCareerController::class, 'applicationStatus']);
    });
