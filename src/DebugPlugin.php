<?php

namespace Backstage\Debug;

use Backstage\Debug\Filament\Clusters\DebugCluster;
use Backstage\Debug\Filament\Resources\ExceptionResource;
use Backstage\Debug\Filament\Resources\IncomingWebhookResource;
use Backstage\Debug\Filament\Resources\LogResource;
use Backstage\Debug\Filament\Resources\OutgoingRequestResource;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;

class DebugPlugin implements Plugin
{
    use EvaluatesClosures;

    public const ID = 'debug';

    /**
     * Who may read the logs. Everything recorded here is readable by whoever
     * can open the tables — request bodies, log context, the URLs somebody
     * visited — so an application is expected to narrow this down rather than
     * leave it as it is.
     */
    protected bool|Closure $authorize = true;

    /**
     * Whether the cluster claims a spot in the panel's navigation. Off by
     * default: the logs are a place you go looking for when something is
     * wrong, so most panels reach them from the user menu instead.
     */
    protected bool $hasNavigationItem = false;

    public function getId(): string
    {
        return static::ID;
    }

    public function register(Panel $panel): void
    {
        $panel
            // A cluster is a page, and registering it as one is what gives it
            // its route; `discoverClusters()` is the only other way in, and a
            // package has no directory of the application's to be discovered in.
            ->pages([
                DebugCluster::class,
            ])
            ->resources([
                ExceptionResource::class,
                LogResource::class,
                OutgoingRequestResource::class,
                IncomingWebhookResource::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(static::ID);

        return $plugin;
    }

    /**
     * Narrow down who may read the logs.
     */
    public function authorize(bool|Closure $callback = true): static
    {
        $this->authorize = $callback;

        return $this;
    }

    public function userCanViewDebug(): bool
    {
        return (bool) $this->evaluate($this->authorize);
    }

    /**
     * Give the cluster an item in the panel's navigation after all.
     */
    public function navigationItem(bool $condition = true): static
    {
        $this->hasNavigationItem = $condition;

        return $this;
    }

    public function hasNavigationItem(): bool
    {
        return $this->hasNavigationItem;
    }
}
