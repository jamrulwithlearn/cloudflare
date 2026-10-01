<?php

namespace Jamrul\Cloudflare;

use Illuminate\Support\ServiceProvider;

class R2ServiceProvider extends ServiceProvider
{
    /**
     * সার্ভিস রেজিস্টার করা (container-এ R2Service বাইন্ড করা)
     */
    public function register()
    {
        // প্যাকেজের ডিফল্ট config, ইউজারের config-এর সাথে মার্জ করা
        $this->mergeConfigFrom(__DIR__ . '/../config/r2.php', 'r2');

        // R2Service একবারই বানানো হবে (singleton), config থেকে মান নিয়ে
        $this->app->singleton(R2Service::class, function ($app) {
            $config = $app['config']->get('r2');

            return new R2Service(
                $config['account_id'] ?? null,
                $config['access_key_id'] ?? null,
                $config['secret_access_key'] ?? null,
                $config['bucket'] ?? null,
                $config['public_domain'] ?? '',
                $config['default_folder'] ?? '',
                (bool) ($config['permission_check'] ?? false)
            );
        });

        // ছোট নামেও পাওয়া যাবে: app('r2')
        $this->app->alias(R2Service::class, 'r2');
    }

    /**
     * অ্যাপ বুট হওয়ার সময় চলে (config publish করার ব্যবস্থা)
     */
    public function boot()
    {
        // শুধু artisan কমান্ড চালানোর সময় publish অপশন দরকার
        if ($this->app->runningInConsole()) {
            // php artisan vendor:publish --tag=r2-config দিলে config/r2.php প্রজেক্টে কপি হবে
            $this->publishes([
                __DIR__ . '/../config/r2.php' => config_path('r2.php'),
            ], 'r2-config');
        }
    }
}