<?php

namespace App\Providers;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Request::macro('workspace', function (): ?Workspace {
            return $this->attributes->get('workspace');
        });
    }
}
