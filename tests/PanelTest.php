<?php

namespace Backstage\Debug\Tests;

use Backstage\Debug\Enums\ExceptionStatus;
use Backstage\Debug\Filament\Clusters\DebugCluster;
use Backstage\Debug\Filament\Resources\ExceptionResource;
use Backstage\Debug\Filament\Resources\ExceptionResource\Pages\ListExceptions;
use Backstage\Debug\Filament\Resources\IncomingWebhookResource;
use Backstage\Debug\Filament\Resources\LogResource;
use Backstage\Debug\Filament\Resources\OutgoingRequestResource;
use Backstage\Debug\Models\Exception;
use Backstage\Debug\Models\ExceptionState;
use Backstage\Debug\Models\IncomingWebhook;
use Backstage\Debug\Models\Log;
use Backstage\Debug\Models\OutgoingRequest;
use Backstage\Debug\Tests\Fixtures\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Collection;
use Livewire\Livewire;

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

    /**
     * The table opens on what nobody has put away yet, and the rest is a filter
     * away rather than gone.
     */
    public function test_the_exceptions_table_opens_on_the_problems_nobody_put_away(): void
    {
        $this->actingAs($this->admin());

        Filament::setCurrentPanel('admin');

        $open = Exception::factory()->create(['fingerprint' => md5('open')]);
        $ignored = Exception::factory()->create(['fingerprint' => md5('ignored')]);
        $ignored->ignore();

        Livewire::test(ListExceptions::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$ignored])
            ->filterTable('status', ExceptionStatus::Ignored->value)
            ->assertCanSeeTableRecords([$ignored])
            ->assertCanNotSeeTableRecords([$open]);
    }

    public function test_a_problem_can_be_ignored_from_the_modal(): void
    {
        $this->actingAs($this->admin());

        Filament::setCurrentPanel('admin');

        $exception = Exception::factory()->create();

        Livewire::test(ListExceptions::class)
            ->callAction([
                TestAction::make('view')->table($exception),
                TestAction::make('ignore'),
            ]);

        $this->assertSame(ExceptionStatus::Ignored, $exception->fresh()->status());
    }

    /**
     * A selection is decided about per problem: two occurrences of one failure
     * and one of another are two decisions, not three.
     */
    public function test_a_selection_can_be_marked_as_fixed_at_once(): void
    {
        $this->actingAs($this->admin());

        Filament::setCurrentPanel('admin');

        $first = Exception::factory()->create(['fingerprint' => md5('one')]);
        $alsoFirst = Exception::factory()->create(['fingerprint' => md5('one')]);
        $second = Exception::factory()->create(['fingerprint' => md5('two')]);

        Livewire::test(ListExceptions::class)
            ->callTableBulkAction('markFixed', [$first, $alsoFirst, $second]);

        $this->assertSame(2, ExceptionState::query()->count());
        $this->assertCount(0, Exception::query()->open()->get());
    }

    public function test_an_ignored_problem_can_be_reopened_in_bulk(): void
    {
        $this->actingAs($this->admin());

        Filament::setCurrentPanel('admin');

        $exception = Exception::factory()->create();
        $exception->ignore();

        Livewire::test(ListExceptions::class)
            ->filterTable('status', ExceptionStatus::Ignored->value)
            ->callTableBulkAction('reopen', [$exception]);

        $this->assertNull($exception->fresh()->status());
        $this->assertSame(0, ExceptionState::query()->count());
    }

    /**
     * The badge asks for attention, so it counts only what nobody has answered
     * for yet.
     */
    public function test_the_navigation_badge_leaves_out_what_has_been_put_away(): void
    {
        $this->actingAs($this->admin());

        Filament::setCurrentPanel('admin');

        Exception::factory()->create(['fingerprint' => md5('open')]);
        Exception::factory()->create(['fingerprint' => md5('ignored')])->ignore();

        $this->assertSame('1', ExceptionResource::getNavigationBadge());
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
