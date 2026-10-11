<?php

use App\Http\Controllers\Api\Admin\AdminAccommodationController;
use App\Http\Controllers\Api\Admin\AdminAccommodationRoomController;
use App\Http\Controllers\Api\Admin\AdminActivityController;
use App\Http\Controllers\Api\Admin\AdminAnnouncementController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminDestinationController;
use App\Http\Controllers\Api\Admin\AdminEventController;
use App\Http\Controllers\Api\Admin\AdminFoodBeverageController;
use App\Http\Controllers\Api\Admin\AdminHeroSlideController;
use App\Http\Controllers\Api\Admin\AdminMenuItemController;
use App\Http\Controllers\Api\Admin\AdminRetailProductController;
use App\Http\Controllers\Api\Admin\AdminRetailShopController;
use App\Http\Controllers\Api\Admin\AdminSubmissionController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Business\Accommodation\AccommodationOwnerController;
use App\Http\Controllers\Api\Business\Accommodation\AccommodationRoomController;
use App\Http\Controllers\Api\Business\BusinessDashboardController;
use App\Http\Controllers\Api\Business\BusinessProfileController;
use App\Http\Controllers\Api\Business\BusinessSubmissionController;
use App\Http\Controllers\Api\Business\FoodBeverage\FoodBeverageOwnerController;
use App\Http\Controllers\Api\Business\FoodBeverage\MenuItemController;
use App\Http\Controllers\Api\Business\RetailShop\RetailShopOwnerController;
use App\Http\Controllers\Api\Business\RetailShop\RetailShopProductController;
use App\Http\Controllers\Api\Public\AccommodationController;
use App\Http\Controllers\Api\Public\ActivityController;
use App\Http\Controllers\Api\Public\AnnouncementController;
use App\Http\Controllers\Api\Public\DestinationController;
use App\Http\Controllers\Api\Public\EventController;
use App\Http\Controllers\Api\Public\FoodBeverageController;
use App\Http\Controllers\Api\Public\HeroSlideController;
use App\Http\Controllers\Api\Public\LocationController;
use App\Http\Controllers\Api\Public\RetailProductController;
use App\Http\Controllers\Api\Public\RetailShopController;
use App\Http\Controllers\Api\Public\SearchController;
use App\Http\Controllers\Api\Tourist\ItineraryController;
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

    // Guest itinerary planner: returns a preview only; the full itinerary is claimed after login.
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/itineraries/guest-generate', [ItineraryController::class, 'guestGenerate']);
        Route::get('/itineraries/guest-quota', [ItineraryController::class, 'guestGenerationQuota']);
    });
});

// ==================== AUTHENTICATED ROUTES ====================
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

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

        Route::post('/ratings', [RatingController::class, 'store']);
        Route::put('/ratings/{id}', [RatingController::class, 'update']);
        Route::delete('/ratings/{id}', [RatingController::class, 'destroy']);
        Route::get('/my-reviews', [RatingController::class, 'myReviews']);
    });

    // ---------- BUSINESS OWNER ROUTES ----------
    Route::middleware('role:business_owner')->prefix('business')->group(function () {
        Route::get('/dashboard', [BusinessDashboardController::class, 'index']);
        Route::get('/submissions', [BusinessSubmissionController::class, 'index']);
        Route::post('/submissions', [BusinessSubmissionController::class, 'store']);
        Route::get('/submissions/{id}', [BusinessSubmissionController::class, 'show']);
        Route::put('/submissions/{id}', [BusinessSubmissionController::class, 'update']);
        Route::delete('/submissions/{id}', [BusinessSubmissionController::class, 'destroy']);
        Route::get('/submissions/{id}/permit', [BusinessSubmissionController::class, 'downloadPermit']);
        Route::get('/profile', [BusinessProfileController::class, 'show']);
        Route::put('/profile', [BusinessProfileController::class, 'update']);

        Route::prefix('accommodation')->middleware('business.type:accommodation')->group(function () {
            Route::get('/', [AccommodationOwnerController::class, 'show']);
            Route::put('/', [AccommodationOwnerController::class, 'update']);
            Route::put('/location', [AccommodationOwnerController::class, 'updateLocation']);
            Route::delete('/images/{image}', [AccommodationOwnerController::class, 'destroyImage']);
            Route::get('/rooms', [AccommodationRoomController::class, 'index']);
            Route::post('/rooms', [AccommodationRoomController::class, 'store']);
            Route::put('/rooms/{room}', [AccommodationRoomController::class, 'update']);
            Route::delete('/rooms/{room}', [AccommodationRoomController::class, 'destroy']);
            Route::delete('/rooms/{room}/images/{image}', [AccommodationRoomController::class, 'destroyImage']);
            Route::patch('/rooms/{room}/occupancy', [AccommodationRoomController::class, 'toggleOccupancy']);
        });

        Route::prefix('food-beverage')->middleware('business.type:food_beverage')->group(function () {
            Route::get('/', [FoodBeverageOwnerController::class, 'show']);
            Route::put('/', [FoodBeverageOwnerController::class, 'update']);
            Route::put('/location', [FoodBeverageOwnerController::class, 'updateLocation']);
            Route::delete('/images/{image}', [FoodBeverageOwnerController::class, 'destroyImage']);
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
            Route::get('/products', [RetailShopProductController::class, 'index']);
            Route::post('/products', [RetailShopProductController::class, 'store']);
            Route::put('/products/{product}', [RetailShopProductController::class, 'update']);
            Route::delete('/products/{product}', [RetailShopProductController::class, 'destroy']);
        });
    });

    // ---------- ADMIN ROUTES ----------
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);

        // Content CRUD — explicitly named to avoid colliding with the public "show" route
        // names of the same resources (e.g. public `destinations.show` vs this `destinations.show`).
        Route::apiResource('/destinations', AdminDestinationController::class)->names('admin.destinations');
        Route::delete('/destinations/{id}/images/{imageId}', [AdminDestinationController::class, 'destroyImage']);
        Route::apiResource('/accommodations', AdminAccommodationController::class)->names('admin.accommodations');
        Route::apiResource('/food-beverages', AdminFoodBeverageController::class)->names('admin.food-beverages');
        Route::apiResource('/retail-products', AdminRetailProductController::class)->names('admin.retail-products');
        Route::apiResource('/events', AdminEventController::class)->names('admin.events');
        Route::apiResource('/announcements', AdminAnnouncementController::class)->names('admin.announcements');
        Route::apiResource('/activities', AdminActivityController::class)->names('admin.activities');
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
        Route::put('/retail-shops/{id}/approve', [AdminRetailShopController::class, 'approve']);
        Route::put('/retail-shops/{id}/reject', [AdminRetailShopController::class, 'reject']);
        Route::put('/retail-products/{id}/approve', [AdminRetailProductController::class, 'approve']);
        Route::put('/retail-products/{id}/reject', [AdminRetailProductController::class, 'reject']);

        // Accommodation room approvals
        Route::get('/accommodation-rooms', [AdminAccommodationRoomController::class, 'index']);
        Route::put('/accommodation-rooms/{id}/approve', [AdminAccommodationRoomController::class, 'approve']);
        Route::put('/accommodation-rooms/{id}/reject', [AdminAccommodationRoomController::class, 'reject']);

        // Menu item approvals
        Route::get('/menu-items', [AdminMenuItemController::class, 'index']);
        Route::put('/menu-items/{id}/approve', [AdminMenuItemController::class, 'approve']);
        Route::put('/menu-items/{id}/reject', [AdminMenuItemController::class, 'reject']);

        // Users
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::put('/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/users/{id}', [AdminUserController::class, 'destroy']);

        // Reports
        Route::get('/reports/overview', [ReportController::class, 'overview']);
        Route::get('/reports/visitors', [ReportController::class, 'visitors']);
        Route::get('/reports/popular', [ReportController::class, 'popular']);
        Route::get('/reports/searches', [ReportController::class, 'searches']);
        Route::get('/reports/export', [ReportController::class, 'export']);
    });
});
