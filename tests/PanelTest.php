<?php

namespace Backstage\Debug\Tests;

use Backstage\Debug\Filament\Clusters\DebugCluster;
use Backstage\Debug\Filament\Resources\ExceptionResource;
use Backstage\Debug\Filament\Resources\IncomingWebhookResource;
use Backstage\Debug\Filament\Resources\LogResource;
use Backstage\Debug\Filament\Resources\OutgoingRequestResource;
use Backstage\Debug\Models\Exception;
use Backstage\Debug\Models\IncomingWebhook;
use Backstage\Debug\Models\Log;
use Backstage\Debug\Models\OutgoingRequest;
use Backstage\Debug\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Collection;

class PanelTest extends TestCase
{
    public function test_an_authorized_user_can_open_every_debug_table(): void
    {
        $this->actingAs($this->admin());

        Exception::factory()->create();
        Log::factory()->create();
        OutgoingRequest::factory()->create();
        IncomingWebhook::factory()->create();

        $this->get(ExceptionResource::getUrl('index'))->assertOk();
        $this->get(LogResource::getUrl('index'))->assertOk();
        $this->get(OutgoingRequestResource::getUrl('index'))->assertOk();
        $this->get(IncomingWebhookResource::getUrl('index'))->assertOk();
    }

    public function test_an_authorized_user_can_open_a_single_record(): void
    {
        $this->actingAs($this->admin());

        $exception = Exception::factory()->create();
        $entry = Log::factory()->level('error')->create();
        $request = OutgoingRequest::factory()->failed()->create();
        $webhook = IncomingWebhook::factory()->rejected()->create();

        $this->get(ExceptionResource::getUrl('view', ['record' => $exception]))->assertOk();
        $this->get(LogResource::getUrl('view', ['record' => $entry]))->assertOk();
        $this->get(OutgoingRequestResource::getUrl('view', ['record' => $request]))->assertOk();
        $this->get(IncomingWebhookResource::getUrl('view', ['record' => $webhook]))->assertOk();
    }

    public function test_the_cluster_opens_on_the_first_of_its_tables(): void
    {
        $this->actingAs($this->admin());

        $this->get(DebugCluster::getUrl())
            ->assertRedirect(ExceptionResource::getUrl('index'));
    }

    /**
     * The plugin registers the four tables itself, so an application that also
     * discovers resources would otherwise list every tab twice.
     */
    public function test_every_debug_table_is_registered_to_the_cluster_once(): void
    {
        $this->actingAs($this->admin());

        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();

        $components = DebugCluster::getClusteredComponents();

        sort($components);

        $this->assertSame([
            ExceptionResource::class,
            IncomingWebhookResource::class,
            LogResource::class,
            OutgoingRequestResource::class,
        ], $components);
    }

    public function test_a_user_the_plugin_does_not_authorize_is_kept_out(): void
    {
        $this->actingAs($this->user(isAdmin: false));

        $this->assertFalse(DebugCluster::canAccess());

        $this->get(ExceptionResource::getUrl('index'))->assertForbidden();
        $this->get(LogResource::getUrl('index'))->assertForbidden();
        $this->get(OutgoingRequestResource::getUrl('index'))->assertForbidden();
        $this->get(IncomingWebhookResource::getUrl('index'))->assertForbidden();
    }

    /**
     * The logs are a place you go looking for when something is wrong, not a
     * page the whole team works in, so the cluster claims no navigation item
     * unless the application asks for one.
     */
    public function test_the_cluster_stays_out_of_the_navigation_by_default(): void
    {
        $this->actingAs($this->admin());

        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();

        $keys = collect(Filament::getNavigation())
            ->flatMap(fn (NavigationGroup $group): Collection => collect($group->getItems()))
            ->map(fn (NavigationItem $item): string => $item->getKey())
            ->all();

        $this->assertNotContains(DebugCluster::class, $keys);
        $this->assertNotContains(ExceptionResource::class, $keys);
    }

    private function admin(): User
    {
        return $this->user(isAdmin: true);
    }

    private function user(bool $isAdmin): User
    {
        return User::query()->create([
            'name' => 'Ada Lovelace',
            'email' => $isAdmin ? 'admin@example.com' : 'user@example.com',
            'is_admin' => $isAdmin,
        ]);
    }
}
