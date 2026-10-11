import { lazy, Suspense } from 'react'
import { Routes, Route, Navigate } from 'react-router-dom'
import PublicLayout from './layouts/PublicLayout'
import AdminLayout from './layouts/AdminLayout'
import AccommodationOwnerLayout from './layouts/AccommodationOwnerLayout'
import FoodBeverageOwnerLayout from './layouts/FoodBeverageOwnerLayout'
import RetailShopOwnerLayout from './layouts/RetailShopOwnerLayout'
import ProtectedRoute from './routes/ProtectedRoute'
import LoadingSpinner from './components/common/LoadingSpinner'

// Public pages
const Home = lazy(() => import('./pages/public/Home'))
const ExploreDestinations = lazy(() => import('./pages/public/ExploreDestinations'))
const DestinationDetail = lazy(() => import('./pages/public/DestinationDetail'))
const Accommodations = lazy(() => import('./pages/public/Accommodations'))
const AccommodationDetail = lazy(() => import('./pages/public/AccommodationDetail'))
const FoodBeverage = lazy(() => import('./pages/public/FoodBeverage'))
const FoodBeverageDetail = lazy(() => import('./pages/public/FoodBeverageDetail'))
const RetailShops = lazy(() => import('./pages/public/RetailShops'))
const RetailShopDetail = lazy(() => import('./pages/public/RetailShopDetail'))
const Events = lazy(() => import('./pages/public/Events'))
const EventDetail = lazy(() => import('./pages/public/EventDetail'))
const ActivityDetail = lazy(() => import('./pages/public/ActivityDetail'))
const About = lazy(() => import('./pages/public/About'))
const Login = lazy(() => import('./pages/public/Login'))
const ForgotPassword = lazy(() => import('./pages/public/ForgotPassword'))
const ResetPassword = lazy(() => import('./pages/public/ResetPassword'))
const PrivacyPolicy = lazy(() => import('./pages/public/PrivacyPolicy'))
const TermsAndConditions = lazy(() => import('./pages/public/TermsAndConditions'))
const RoleSelection = lazy(() => import('./pages/public/RoleSelection'))
const AdminLogin = lazy(() => import('./pages/public/AdminLogin'))
const TouristRegister = lazy(() => import('./pages/public/TouristRegister'))
const BusinessOwnerRegister = lazy(() => import('./pages/public/BusinessOwnerRegister'))
const RegistrationPending = lazy(() => import('./pages/public/RegistrationPending'))
const Forbidden = lazy(() => import('./pages/public/Forbidden'))
const NotFound = lazy(() => import('./pages/public/NotFound'))

// Tourist pages
const TouristDashboard = lazy(() => import('./pages/tourist/TouristDashboard'))
const ItineraryPlanner = lazy(() => import('./pages/tourist/ItineraryPlanner'))
const ItineraryView = lazy(() => import('./pages/tourist/ItineraryView'))
const SavedItineraries = lazy(() => import('./pages/tourist/SavedItineraries'))
const MyReviews = lazy(() => import('./pages/tourist/MyReviews'))

// Business pages
const BusinessOnboarding = lazy(() => import('./pages/business/BusinessOnboarding'))
const PropertyOverview = lazy(() => import('./pages/business/accommodation/PropertyOverview'))
const MyProperty = lazy(() => import('./pages/business/accommodation/MyProperty'))
const RoomManagement = lazy(() => import('./pages/business/accommodation/RoomManagement'))
const EstablishmentOverview = lazy(() => import('./pages/business/foodBeverage/EstablishmentOverview'))
const MyEstablishment = lazy(() => import('./pages/business/foodBeverage/MyEstablishment'))
const MenuManagement = lazy(() => import('./pages/business/foodBeverage/MenuManagement'))
const ShopOverview = lazy(() => import('./pages/business/retailShop/ShopOverview'))
const MyShop = lazy(() => import('./pages/business/retailShop/MyShop'))
const ProductManagement = lazy(() => import('./pages/business/retailShop/ProductManagement'))

// Admin pages
const AdminDashboard = lazy(() => import('./pages/admin/AdminDashboard'))
const ManageDestinations = lazy(() => import('./pages/admin/ManageDestinations'))
const ManageActivities = lazy(() => import('./pages/admin/ManageActivities'))
const ManageEvents = lazy(() => import('./pages/admin/ManageEvents'))
const ManageHeroSlides = lazy(() => import('./pages/admin/ManageHeroSlides'))
const ManageAnnouncements = lazy(() => import('./pages/admin/ManageAnnouncements'))
const ReviewSubmissions = lazy(() => import('./pages/admin/ReviewSubmissions'))
const ManageUsers = lazy(() => import('./pages/admin/ManageUsers'))
const ReportsAnalytics = lazy(() => import('./pages/admin/ReportsAnalytics'))

export default function App() {
  return (
    <Suspense fallback={<LoadingSpinner size="lg" />}>
      <Routes>
        {/* Auth flow pages (no layout wrapper) */}
        <Route path="/get-started" element={<RoleSelection />} />
        <Route path="/login/admin" element={<AdminLogin />} />
        <Route path="/register/tourist" element={<TouristRegister />} />
        <Route path="/register/business_owner" element={<BusinessOwnerRegister />} />
        <Route path="/registration-pending" element={<RegistrationPending />} />
        <Route path="/forbidden" element={<ProtectedRoute roles={['business_owner', 'admin']}><Forbidden /></ProtectedRoute>} />

        {/* Public Routes */}
        <Route element={<PublicLayout />}>
          <Route path="/" element={<Home />} />
          <Route path="/destinations" element={<ExploreDestinations />} />
          <Route path="/destinations/:id" element={<DestinationDetail />} />
          <Route path="/accommodations" element={<Accommodations />} />
          <Route path="/accommodations/:id" element={<AccommodationDetail />} />
          <Route path="/food-beverage" element={<FoodBeverage />} />
          <Route path="/food-beverage/:id" element={<FoodBeverageDetail />} />
          <Route path="/products" element={<Navigate to="/shops" replace />} />
          <Route path="/shops" element={<RetailShops />} />
          <Route path="/shops/:id" element={<RetailShopDetail />} />
          <Route path="/events" element={<Events />} />
          <Route path="/events/:id" element={<EventDetail />} />
          <Route path="/activities/:id" element={<ActivityDetail />} />
          <Route path="/about" element={<About />} />
          {/* Public so guests can fill in the planner; generating still requires a tourist login. */}
          <Route path="/plan-trip" element={<ItineraryPlanner />} />
          <Route path="/login" element={<Login />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/reset-password" element={<ResetPassword />} />
          <Route path="/privacy-policy" element={<PrivacyPolicy />} />
          <Route path="/terms-and-conditions" element={<TermsAndConditions />} />
          {/* Any URL no other route matches (React Router ranks "*" last). */}
          <Route path="*" element={<NotFound />} />
        </Route>

        {/* Tourist Routes */}
        <Route element={<ProtectedRoute roles={['tourist']}><PublicLayout /></ProtectedRoute>}>
          <Route path="/dashboard" element={<TouristDashboard />} />
          <Route path="/itineraries/:id" element={<ItineraryView />} />
          <Route path="/my-itineraries" element={<SavedItineraries />} />
          <Route path="/my-reviews" element={<MyReviews />} />
        </Route>

        {/* Business Owner entry point - full-screen onboarding/submission flow, no dashboard chrome */}
        <Route path="/business" element={<ProtectedRoute roles={['business_owner', 'admin']}><BusinessOnboarding /></ProtectedRoute>} />

        {/* Accommodation Owner Routes - specialized dashboard once a property is approved */}
        <Route path="/business/accommodation" element={<ProtectedRoute roles={['business_owner', 'admin']} businessType="accommodation"><AccommodationOwnerLayout /></ProtectedRoute>}>
          <Route index element={<PropertyOverview />} />
          <Route path="property" element={<MyProperty />} />
          <Route path="rooms" element={<RoomManagement />} />
        </Route>

        {/* Food & Beverage Owner Routes - specialized dashboard once an establishment is approved */}
        <Route path="/business/food-beverage" element={<ProtectedRoute roles={['business_owner', 'admin']} businessType="food_beverage"><FoodBeverageOwnerLayout /></ProtectedRoute>}>
          <Route index element={<EstablishmentOverview />} />
          <Route path="establishment" element={<MyEstablishment />} />
          <Route path="menu" element={<MenuManagement />} />
        </Route>

        {/* Local Products Owner Routes - specialized dashboard once a shop is approved */}
        <Route path="/business/retail-shop" element={<ProtectedRoute roles={['business_owner', 'admin']} businessType="retail_product"><RetailShopOwnerLayout /></ProtectedRoute>}>
          <Route index element={<ShopOverview />} />
          <Route path="shop" element={<MyShop />} />
          <Route path="products" element={<ProductManagement />} />
        </Route>

        {/* Admin Routes */}
        <Route path="/admin" element={<ProtectedRoute roles={['admin']}><AdminLayout /></ProtectedRoute>}>
          <Route index element={<AdminDashboard />} />
          <Route path="destinations" element={<ManageDestinations />} />
          <Route path="activities" element={<ManageActivities />} />
          <Route path="events" element={<ManageEvents />} />
          <Route path="hero-slides" element={<ManageHeroSlides />} />
          <Route path="announcements" element={<ManageAnnouncements />} />
          <Route path="submissions" element={<ReviewSubmissions />} />
          <Route path="users" element={<ManageUsers />} />
          <Route path="reports" element={<ReportsAnalytics />} />
        </Route>
      </Routes>
    </Suspense>
  )
}