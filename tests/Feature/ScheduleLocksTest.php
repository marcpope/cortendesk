<?php

use Illuminate\Console\Scheduling\Schedule;

/*
 * Laravel's signal handlers call pcntl_signal whenever the pcntl extension is
 * loaded, even when the function is disabled, which strands the task's lock
 * (#70). Handlers are only requested when callable, and locks expire soon.
 */

it('only asks for signal handlers when pcntl_signal can be called', function () {
    $callable = function_exists('pcntl_signal') && function_exists('pcntl_async_signals');

    $locked = collect(app(Schedule::class)->events())->filter->withoutOverlapping;

    expect($locked)->not->toBeEmpty();
    foreach ($locked as $event) {
        expect($event->releaseOnTerminationSignals)->toBe($callable)
            ->and($event->expiresAt)->toBeLessThanOrEqual(30);
    }
});
