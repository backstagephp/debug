<?php

namespace Backstage\Debug\Filament\Clusters;

use BackedEnum;
use Backstage\Debug\DebugPlugin;
use Filament\Clusters\Cluster;
use Filament\Facades\Filament;
use Filament\Pages\Enums\SubNavigationPosition;

/**
 * Groups the four logs that answer "what is broken right now": the exceptions
 * the application reported, the lines it logged, the calls it made to other
 * APIs, and the deliveries it received.
 */
class DebugCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-bug';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    /**
     * Whether the signed-in user may read the logs, which is the plugin's
     * `authorize()` and nothing else — the resources all defer to this.
     *
     * A panel without the plugin answers no rather than throwing: the cluster
     * is asked about from user menus and navigation builders that run for every
     * panel, not only for the one the plugin was registered on.
     */
    public static function canAccess(): bool
    {
        if (! static::isRegistered()) {
            return false;
        }

        return DebugPlugin::get()->userCanViewDebug();
    }

    /**
     * Whether the cluster carries an item in the panel's navigation. Off by
     * default: the logs are a place you go looking for, not a page the whole
     * team works in, so they are usually reached from the user menu instead.
     */
    public static function shouldRegisterNavigation(): bool
    {
        if (! static::isRegistered()) {
            return false;
        }

        return DebugPlugin::get()->hasNavigationItem()
            && parent::shouldRegisterNavigation();
    }

    /**
     * Whether the plugin is on the panel being asked about. A panel without it
     * answers no rather than throwing: the cluster is asked about from user
     * menus and navigation builders that run for every panel, not only for the
     * one the plugin was registered on.
     */
    protected static function isRegistered(): bool
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        return $panel !== null && $panel->hasPlugin(DebugPlugin::ID);
    }
}
