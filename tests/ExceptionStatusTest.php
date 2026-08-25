<?php

namespace Backstage\Debug\Tests;

use Backstage\Debug\Enums\ExceptionStatus;
use Backstage\Debug\Models\Exception;
use Backstage\Debug\Models\ExceptionState;
use Backstage\Debug\Tests\Fixtures\User;

/**
 * Ignoring and fixing are decisions about a problem, not about one occurrence
 * of it, so both are keyed on the fingerprint every occurrence of one failure
 * shares.
 */
class ExceptionStatusTest extends TestCase
{
    public function test_an_exception_starts_without_a_decision(): void
    {
        $exception = Exception::factory()->create();

        $this->assertNull($exception->status());
        $this->assertFalse($exception->isPutAway());
        $this->assertSame([$exception->id], Exception::query()->open()->pluck('id')->all());
    }

    public function test_ignoring_covers_every_occurrence_of_the_same_problem(): void
    {
        $first = Exception::factory()->create();
        $second = Exception::factory()->create(['fingerprint' => $first->fingerprint]);
        $other = Exception::factory()->create(['fingerprint' => md5('something else')]);

        $first->ignore();

        $this->assertSame([$other->id], Exception::query()->open()->pluck('id')->all());
        $this->assertTrue($second->fresh()->isPutAway());
    }

    /**
     * The point of ignoring rather than deleting: the failure keeps being
     * written down, it is only kept out of the way.
     */
    public function test_an_ignored_problem_keeps_being_recorded_and_stays_out_of_the_way(): void
    {
        $exception = Exception::factory()->create();

        $exception->ignore();

        $later = Exception::factory()->create([
            'fingerprint' => $exception->fingerprint,
            'occurred_at' => now()->addHour(),
        ]);

        $this->assertSame(2, Exception::query()->count());
        $this->assertCount(0, Exception::query()->open()->get());
        $this->assertTrue($later->fresh()->isPutAway());
    }

    public function test_a_fixed_problem_leaves_the_table(): void
    {
        $exception = Exception::factory()->create(['occurred_at' => now()->subHour()]);

        $exception->markFixed();

        $this->assertCount(0, Exception::query()->open()->get());
        $this->assertSame(ExceptionStatus::Fixed, $exception->fresh()->status());
    }

    /**
     * A fix that did not hold is the one thing marking as fixed has to get
     * right: the occurrence after it is the failure saying so.
     */
    public function test_a_fixed_problem_that_happens_again_comes_back_on_its_own(): void
    {
        $exception = Exception::factory()->create(['occurred_at' => now()->subHour()]);

        $exception->markFixed();

        $again = Exception::factory()->create([
            'fingerprint' => $exception->fingerprint,
            'occurred_at' => now()->addHour(),
        ]);

        $this->assertSame([$again->id], Exception::query()->open()->pluck('id')->all());
        $this->assertFalse($again->isPutAway());
        $this->assertTrue($exception->fresh()->isPutAway());
    }

    public function test_marking_as_fixed_again_puts_the_recurrence_away_too(): void
    {
        $exception = Exception::factory()->create(['occurred_at' => now()->subHour()]);

        $exception->markFixed();

        $again = Exception::factory()->create([
            'fingerprint' => $exception->fingerprint,
            'occurred_at' => now(),
        ]);

        $again->markFixed();

        $this->assertCount(0, Exception::query()->open()->get());
    }

    public function test_reopening_puts_every_occurrence_back(): void
    {
        $exception = Exception::factory()->create();
        Exception::factory()->create(['fingerprint' => $exception->fingerprint]);

        $exception->ignore();
        $exception->reopen();

        $this->assertCount(2, Exception::query()->open()->get());
        $this->assertNull($exception->fresh()->status());
        $this->assertSame(0, ExceptionState::query()->count());
    }

    public function test_a_problem_holds_one_decision_at_a_time(): void
    {
        $exception = Exception::factory()->create();

        $exception->ignore();
        $exception->markFixed();

        $this->assertSame(1, ExceptionState::query()->count());
        $this->assertSame(ExceptionStatus::Fixed, $exception->fresh()->status());
    }

    public function test_the_decision_records_who_made_it(): void
    {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $this->actingAs($user);

        $exception = Exception::factory()->create();
        $exception->ignore();

        $this->assertSame($user->id, $exception->state->user_id);
        $this->assertNotNull($exception->state->marked_at);
    }

    public function test_the_table_can_be_narrowed_to_one_decision(): void
    {
        $ignored = Exception::factory()->create();
        $fixed = Exception::factory()->create(['fingerprint' => md5('fixed')]);
        Exception::factory()->create(['fingerprint' => md5('open')]);

        $ignored->ignore();
        $fixed->markFixed();

        $this->assertSame([$ignored->id], Exception::query()->inState(ExceptionStatus::Ignored)->pluck('id')->all());
        $this->assertSame([$fixed->id], Exception::query()->inState(ExceptionStatus::Fixed)->pluck('id')->all());
    }
}
