<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Tenancy\EditBrand;
use App\Filament\Pages\Tenancy\RegisterBrand;
use App\Http\Middleware\AutoLoginLocalOwner;
use App\Models\Brand;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('')
            // The login page is used only when the app is hosted (Phase 4); on the laptop the owner is signed in automatically.
            ->login()
            ->brandName('SocialMedia Manager')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('brand/favicon.png'))
            ->colors(['primary' => Color::hex('#1b4c86')])
            ->databaseNotifications()
            // Brands (Moggies International first): every screen works inside one brand, at /<brand-slug>.
            ->tenant(Brand::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterBrand::class)
            ->tenantProfile(EditBrand::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // Must sit right after StartSession: Laravel's middleware priority moves Authenticate up to
                // AuthenticateSession's place, so anything listed later would run after the login check.
                AutoLoginLocalOwner::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
