<?php

use App\Http\Controllers\Api\AccountStatusController;
use App\Http\Controllers\Api\Admin\AdminAccommodationController;
use App\Http\Controllers\Api\Admin\AdminAccommodationRoomController;
use App\Http\Controllers\Api\Admin\AdminAccountEnforcementController;
use App\Http\Controllers\Api\Admin\AdminAmenityController;
use App\Http\Controllers\Api\Admin\AdminAnnouncementController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminDestinationController;
use App\Http\Controllers\Api\Admin\AdminEventController;
use App\Http\Controllers\Api\Admin\AdminFoodBeverageController;
use App\Http\Controllers\Api\Admin\AdminHeroSlideController;
use App\Http\Controllers\Api\Admin\AdminItemReviewController;
use App\Http\Controllers\Api\Admin\AdminListingLocationController;
use App\Http\Controllers\Api\Admin\AdminMenuItemController;
use App\Http\Controllers\Api\Admin\AdminOwnerApplicationController;
use App\Http\Controllers\Api\Admin\AdminRetailProductController;
use App\Http\Controllers\Api\Admin\AdminRetailShopController;
use App\Http\Controllers\Api\Admin\AdminReviewController;
use App\Http\Controllers\Api\Admin\AdminSubmissionController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminViolationController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Business\Accommodation\AccommodationOwnerController;
use App\Http\Controllers\Api\Business\Accommodation\AccommodationRoomController;
use App\Http\Controllers\Api\Business\BusinessDashboardController;
use App\Http\Controllers\Api\Business\BusinessProfileController;
use App\Http\Controllers\Api\Business\BusinessSubmissionController;
use App\Http\Controllers\Api\Business\FoodBeverage\FoodBeverageOwnerController;
use App\Http\Controllers\Api\Business\FoodBeverage\MenuItemController;
use App\Http\Controllers\Api\Business\OwnerApplicationController;
use App\Http\Controllers\Api\Business\RatingReplyController;
use App\Http\Controllers\Api\Business\RetailShop\RetailShopOwnerController;
use App\Http\Controllers\Api\Business\RetailShop\RetailShopProductController;
use App\Http\Controllers\Api\Public\AccommodationController;
use App\Http\Controllers\Api\Public\ActivityController;
use App\Http\Controllers\Api\Public\AmenitySuggestionController;
use App\Http\Controllers\Api\Public\AnnouncementController;
use App\Http\Controllers\Api\Public\DestinationController;
use App\Http\Controllers\Api\Public\EventController;
use App\Http\Controllers\Api\Public\FoodBeverageController;
use App\Http\Controllers\Api\Public\HeroSlideController;
use App\Http\Controllers\Api\Public\LocationController;
use App\Http\Controllers\Api\Public\PhotoCreditController;
use App\Http\Controllers\Api\Public\RetailProductController;
use App\Http\Controllers\Api\Public\RetailShopController;
use App\Http\Controllers\Api\Public\SearchController;
use App\Http\Controllers\Api\Public\SharedItineraryController;
use App\Http\Controllers\Api\Tourist\ItineraryController;
use App\Http\Controllers\Api\Tourist\ItineraryDocumentController;
use App\Http\Controllers\Api\Tourist\RatingController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

// ==================== PUBLIC ROUTES ====================
Route::prefix('v1')->group(function () {

    // Auth — throttled to slow down brute force / spam registration / email-bombing
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    });

    // Public listings
    Route::get('/destinations', [DestinationController::class, 'index']);
    Route::get('/destinations/{id}', [DestinationController::class, 'show'])->name('destinations.show');
    Route::get('/accommodations', [AccommodationController::class, 'index']);
    Route::get('/accommodations/{id}', [AccommodationController::class, 'show'])->name('accommodations.show');
    Route::get('/food-beverages', [FoodBeverageController::class, 'index']);
    Route::get('/food-beverages/{id}', [FoodBeverageController::class, 'show'])->name('food-beverages.show');
    Route::get('/retail-products', [RetailProductController::class, 'index']);
    Route::get('/retail-products/{id}', [RetailProductController::class, 'show'])->name('retail-products.show');
    Route::get('/retail-shops', [RetailShopController::class, 'index']);
    Route::get('/retail-shops/{id}', [RetailShopController::class, 'show'])->name('retail-shops.show');
    Route::get('/activities/{id}', [ActivityController::class, 'show']);
    Route::get('/amenity-suggestions', [AmenitySuggestionController::class, 'index']);
    Route::get('/photo-credits', [PhotoCreditController::class, 'index']);
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/calendar', [EventController::class, 'calendar']);
    Route::get('/events/{id}', [EventController::class, 'show']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/hero-slides', [HeroSlideController::class, 'index']);

    // Locations
    Route::get('/municipalities', [LocationController::class, 'municipalities']);
    Route::get('/municipalities/{id}/barangays', [LocationController::class, 'barangays']);
    Route::get('/categories', [LocationController::class, 'categories']);

    // Search
    Route::get('/search', [SearchController::class, 'search']);

    // A shared itinerary, read-only, for whoever holds the secret link its owner created.
    Route::middleware('throttle:60,1')->where(['token' => '[A-Za-z0-9]{48}'])->group(function () {
        Route::get('/shared-itineraries/{token}', [SharedItineraryController::class, 'show']);
        Route::get('/shared-itineraries/{token}/pdf', [SharedItineraryController::class, 'pdf'])->middleware('throttle:20,1');
    });

    // Guest itinerary planner: returns a preview only; the full itinerary is claimed after login.
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/itineraries/guest-generate', [ItineraryController::class, 'guestGenerate']);
        Route::get('/itineraries/guest-quota', [ItineraryController::class, 'guestGenerationQuota']);
    });
});

// ==================== AUTHENTICATED ROUTES ====================
Route::prefix('v1')->middleware(['auth:sanctum', 'account.active'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // ---------- ACCOUNT HEALTH (any signed-in user; still reachable while suspended) ----------
    Route::get('/account/status', [AccountStatusController::class, 'show']);
    Route::post('/account/notifications/read', [AccountStatusController::class, 'markNotificationsRead']);
    Route::post('/account/violations/{id}/appeal', [AccountStatusController::class, 'appeal'])->middleware('throttle:10,1');
    Route::get('/account/appeals/{id}/attachments/{index}', [AccountStatusController::class, 'appealAttachment']);

    // ---------- TOURIST ROUTES ----------
    Route::middleware('role:tourist')->group(function () {
        Route::post('/itineraries/generate', [ItineraryController::class, 'generate']);
        Route::get('/itineraries', [ItineraryController::class, 'index']);
        Route::get('/itineraries/generation-quota', [ItineraryController::class, 'generationQuota']);
        Route::post('/itineraries/{id}/claim', [ItineraryController::class, 'claim']);
        Route::get('/itineraries/{id}', [ItineraryController::class, 'show']);
        Route::put('/itineraries/{id}', [ItineraryController::class, 'update']);
        Route::delete('/itineraries/{id}', [ItineraryController::class, 'destroy']);
        Route::get('/itineraries/{id}/eligible-listings', [ItineraryController::class, 'eligibleListings']);

        // Save, print and share. Only the owner's itineraries are found; a link stays off until asked for.
        Route::post('/itineraries/{id}/save', [ItineraryDocumentController::class, 'save']);
        Route::get('/itineraries/{id}/document', [ItineraryDocumentController::class, 'document'])->middleware('throttle:30,1');
        Route::get('/itineraries/{id}/pdf', [ItineraryDocumentController::class, 'pdf'])->middleware('throttle:20,1');
        Route::get('/itineraries/{id}/share', [ItineraryDocumentController::class, 'shareStatus']);
        Route::post('/itineraries/{id}/share', [ItineraryDocumentController::class, 'share']);
        Route::delete('/itineraries/{id}/share', [ItineraryDocumentController::class, 'unshare']);

        Route::post('/ratings', [RatingController::class, 'store']);
        Route::put('/ratings/{id}', [RatingController::class, 'update']);
        Route::delete('/ratings/{id}', [RatingController::class, 'destroy']);
        Route::get('/my-reviews', [RatingController::class, 'myReviews']);
    });

    // ---------- BUSINESS OWNER ROUTES ----------
    Route::middleware('role:business_owner')->prefix('business')->group(function () {
        // Own registration application: reachable while pending review or after a
        // rejection, so the owner can see the reason and resubmit.
        Route::get('/application', [OwnerApplicationController::class, 'show']);
        Route::get('/application/permit', [OwnerApplicationController::class, 'permit']);
        Route::post('/application/resubmit', [OwnerApplicationController::class, 'resubmit']);

        // Everything else requires an approved (active) Business Owner account.
        Route::middleware('owner.approved')->group(function () {
            Route::get('/dashboard', [BusinessDashboardController::class, 'index']);
            Route::get('/submissions', [BusinessSubmissionController::class, 'index']);
            Route::post('/submissions', [BusinessSubmissionController::class, 'store']);
            Route::get('/submissions/{id}', [BusinessSubmissionController::class, 'show']);
            Route::put('/submissions/{id}', [BusinessSubmissionController::class, 'update']);
            Route::delete('/submissions/{id}', [BusinessSubmissionController::class, 'destroy']);
            Route::get('/submissions/{id}/permit', [BusinessSubmissionController::class, 'downloadPermit']);
            Route::get('/profile', [BusinessProfileController::class, 'show']);
            Route::put('/profile', [BusinessProfileController::class, 'update']);

            // Owner responses to reviews on their own listings (ownership checked in the controller)
            Route::get('/reviews', [RatingReplyController::class, 'index']);
            Route::post('/reviews/{rating}/reply', [RatingReplyController::class, 'store'])->whereNumber('rating');
            Route::put('/reviews/{rating}/reply', [RatingReplyController::class, 'update'])->whereNumber('rating');

            Route::prefix('accommodation')->middleware('business.type:accommodation')->group(function () {
                Route::get('/', [AccommodationOwnerController::class, 'show']);
                Route::put('/', [AccommodationOwnerController::class, 'update']);
                Route::put('/location', [AccommodationOwnerController::class, 'updateLocation']);
                Route::delete('/images/{image}', [AccommodationOwnerController::class, 'destroyImage']);

                // Accommodation gallery management
                Route::patch('/images/{image}/cover', [AccommodationOwnerController::class, 'setCover']);
                Route::put('/images/reorder', [AccommodationOwnerController::class, 'reorderImages']);

                // Owner submits completed listing for admin publication review
                Route::post('/submit-for-review', [AccommodationOwnerController::class, 'submitForReview']);

                Route::get('/rooms', [AccommodationRoomController::class, 'index']);
                Route::post('/rooms', [AccommodationRoomController::class, 'store']);
                Route::put('/rooms/{room}', [AccommodationRoomController::class, 'update']);
                Route::delete('/rooms/{room}', [AccommodationRoomController::class, 'destroy']);
                Route::delete('/rooms/{room}/images/{image}', [AccommodationRoomController::class, 'destroyImage']);
            });

            Route::prefix('food-beverage')->middleware('business.type:food_beverage')->group(function () {
                Route::get('/', [FoodBeverageOwnerController::class, 'show']);
                Route::put('/', [FoodBeverageOwnerController::class, 'update']);
                Route::put('/location', [FoodBeverageOwnerController::class, 'updateLocation']);
                Route::delete('/images/{image}', [FoodBeverageOwnerController::class, 'destroyImage']);

                // Food & Beverage gallery management
                Route::patch('/images/{image}/cover', [FoodBeverageOwnerController::class, 'setCover']);
                Route::put('/images/reorder', [FoodBeverageOwnerController::class, 'reorderImages']);

                // Owner submits completed listing for admin publication review
                Route::post('/submit-for-review', [FoodBeverageOwnerController::class, 'submitForReview']);

                Route::get('/menu-items', [MenuItemController::class, 'index']);
                Route::post('/menu-items', [MenuItemController::class, 'store']);
                Route::put('/menu-items/{item}', [MenuItemController::class, 'update']);
                Route::delete('/menu-items/{item}', [MenuItemController::class, 'destroy']);
            });

            Route::prefix('retail-shop')->middleware('business.type:retail_product')->group(function () {
                Route::get('/', [RetailShopOwnerController::class, 'show']);
                Route::put('/', [RetailShopOwnerController::class, 'update']);
                Route::put('/location', [RetailShopOwnerController::class, 'updateLocation']);
                Route::delete('/images/{image}', [RetailShopOwnerController::class, 'destroyImage']);
                Route::patch('/images/{image}/cover', [RetailShopOwnerController::class, 'setCover']);
                Route::put('/images/reorder', [RetailShopOwnerController::class, 'reorderImages']);
                Route::post('/submit-for-review', [RetailShopOwnerController::class, 'submitForReview']);
                Route::get('/products', [RetailShopProductController::class, 'index']);
                Route::post('/products', [RetailShopProductController::class, 'store']);
                Route::put('/products/{product}', [RetailShopProductController::class, 'update']);
                Route::delete('/products/{product}', [RetailShopProductController::class, 'destroy']);
            });
        }); // owner.approved
    });

    // ---------- ADMIN ROUTES ----------
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);

        // Read-only overview of guest reviews and owner replies across all listing types
        Route::get('/guest-reviews', [AdminReviewController::class, 'index']);

        // Content CRUD — explicitly named to avoid colliding with the public "show" route
        // names of the same resources (e.g. public `destinations.show` vs this `destinations.show`).
        Route::apiResource('/destinations', AdminDestinationController::class)->names('admin.destinations');
        Route::delete('/destinations/{id}/images/{imageId}', [AdminDestinationController::class, 'destroyImage']);
        Route::patch('/destinations/{id}/images/{imageId}/cover', [AdminDestinationController::class, 'setCoverImage']);
        Route::apiResource('/accommodations', AdminAccommodationController::class)->names('admin.accommodations');
        Route::apiResource('/food-beverages', AdminFoodBeverageController::class)->names('admin.food-beverages');
        Route::apiResource('/retail-products', AdminRetailProductController::class)->names('admin.retail-products');
        Route::apiResource('/events', AdminEventController::class)->names('admin.events');
        Route::apiResource('/announcements', AdminAnnouncementController::class)->names('admin.announcements');
        Route::apiResource('/hero-slides', AdminHeroSlideController::class)->names('admin.hero-slides');

        // Submissions
        Route::get('/submissions', [AdminSubmissionController::class, 'index']);
        Route::get('/submissions/{id}/permit', [AdminSubmissionController::class, 'downloadPermit']);
        Route::put('/submissions/{id}/approve', [AdminSubmissionController::class, 'approve']);
        Route::put('/submissions/{id}/reject', [AdminSubmissionController::class, 'reject']);
        Route::put('/submissions/{id}/warn', [AdminSubmissionController::class, 'warn']);
        Route::put('/submissions/{id}/clear-warning', [AdminSubmissionController::class, 'clearWarning']);
        Route::put('/submissions/{id}/remove', [AdminSubmissionController::class, 'remove']);

        // Retail shop / product approvals
        Route::get('/retail-shops', [AdminRetailShopController::class, 'index']);

        // NEW: Admin can view full retail shop details and photo gallery
        Route::get('/retail-shops/{id}', [AdminRetailShopController::class, 'show']);

        Route::put('/retail-shops/{id}/approve', [AdminRetailShopController::class, 'approve']);
        Route::put('/retail-shops/{id}/reject', [AdminRetailShopController::class, 'reject']);
        Route::put('/retail-shops/{id}/approve-revision', [AdminRetailShopController::class, 'approveRevision']);
        Route::put('/retail-shops/{id}/request-revision-changes', [AdminRetailShopController::class, 'requestRevisionChanges']);
        Route::put('/retail-products/{id}/approve', [AdminRetailProductController::class, 'approve']);
        Route::put('/retail-products/{id}/reject', [AdminRetailProductController::class, 'reject']);

        // Accommodation room approvals
        Route::get('/accommodation-rooms', [AdminAccommodationRoomController::class, 'index']);
        Route::put('/accommodation-rooms/{id}', [AdminAccommodationRoomController::class, 'update'])->whereNumber('id');
        Route::put('/accommodation-rooms/{id}/approve', [AdminAccommodationRoomController::class, 'approve']);

        // Amenities owners typed themselves, and promoting one to a shared suggestion
        Route::get('/amenities/custom', [AdminAmenityController::class, 'custom']);
        Route::post('/amenity-suggestions', [AdminAmenityController::class, 'promote']);
        Route::put('/accommodation-rooms/{id}/reject', [AdminAccommodationRoomController::class, 'reject']);

        // Menu item approvals
        Route::get('/menu-items', [AdminMenuItemController::class, 'index']);
        Route::put('/menu-items/{id}/approve', [AdminMenuItemController::class, 'approve']);
        Route::put('/menu-items/{id}/reject', [AdminMenuItemController::class, 'reject']);

        // Business Owner registrations (account approval + business permit)
        Route::get('/owner-applications', [AdminOwnerApplicationController::class, 'index']);
        Route::get('/owner-applications/{id}', [AdminOwnerApplicationController::class, 'show']);
        Route::get('/owner-applications/{id}/permit', [AdminOwnerApplicationController::class, 'permit']);
        Route::put('/owner-applications/{id}/approve', [AdminOwnerApplicationController::class, 'approve']);
        Route::put('/owner-applications/{id}/reject', [AdminOwnerApplicationController::class, 'reject']);

        // One queue over rooms, menu items and products, with bulk decisions
        Route::get('/item-reviews', [AdminItemReviewController::class, 'index']);
        Route::post('/item-reviews/bulk', [AdminItemReviewController::class, 'bulk']);

        // Proposed changes to approved rooms, menu items and products
        Route::put('/accommodation-rooms/{id}/approve-changes', [AdminAccommodationRoomController::class, 'approveChanges']);
        Route::put('/accommodation-rooms/{id}/reject-changes', [AdminAccommodationRoomController::class, 'rejectChanges']);
        Route::put('/menu-items/{id}/approve-changes', [AdminMenuItemController::class, 'approveChanges']);
        Route::put('/menu-items/{id}/reject-changes', [AdminMenuItemController::class, 'rejectChanges']);
        Route::put('/retail-products/{id}/approve-changes', [AdminRetailProductController::class, 'approveChanges']);
        Route::put('/retail-products/{id}/reject-changes', [AdminRetailProductController::class, 'rejectChanges']);

        // Users and account enforcement (no instant delete: closing an account is a separate, confirmed step)
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::put('/users/{id}', [AdminUserController::class, 'update']);
        Route::get('/account-enforcement/meta', [AdminAccountEnforcementController::class, 'meta']);
        Route::get('/users/{id}/account', [AdminAccountEnforcementController::class, 'show']);
        Route::post('/users/{id}/enforcement', [AdminAccountEnforcementController::class, 'enforce'])->middleware('throttle:30,1');
        Route::post('/users/{id}/reactivate', [AdminAccountEnforcementController::class, 'reactivate']);
        Route::post('/users/{id}/close', [AdminAccountEnforcementController::class, 'close']);
        Route::get('/account-violations', [AdminViolationController::class, 'violations']);
        Route::get('/account-violations/{id}/evidence/{index}', [AdminAccountEnforcementController::class, 'evidence']);
        Route::get('/account-appeals', [AdminViolationController::class, 'appeals']);
        Route::post('/account-appeals/{id}/decision', [AdminViolationController::class, 'decideAppeal']);
        Route::get('/account-appeals/{id}/attachments/{index}', [AdminAccountEnforcementController::class, 'appealAttachment']);
        Route::get('/account-log', [AdminViolationController::class, 'log']);

        // Exact map locations of destinations, accommodations, food places and shops
        Route::get('/listing-locations/{type}', [AdminListingLocationController::class, 'index']);
        Route::put('/listing-locations/{type}/{id}', [AdminListingLocationController::class, 'update']);

        // Reports
        Route::get('/reports/overview', [ReportController::class, 'overview']);
        Route::get('/reports/visitors', [ReportController::class, 'visitors']);
        Route::get('/reports/popular', [ReportController::class, 'popular']);
        Route::get('/reports/searches', [ReportController::class, 'searches']);
        Route::get('/reports/export', [ReportController::class, 'export']);
    });
});
