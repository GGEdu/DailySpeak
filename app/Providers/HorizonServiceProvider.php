<?php

namespace App\Providers;

use App\Notifications\QueueJobFailed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Throwable;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Empty means no mail for long waits. Read the address from config/monitoring.php.
        Horizon::routeMailNotificationsTo(config('monitoring.horizon.notifications_email'));

        // Horizon does not mail failed jobs, so they are reported here.
        Queue::failing(fn (JobFailed $event) => $this->reportFailedJob($event));

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => (bool) $user?->is_admin);
    }

    /**
     * Log every failed job as critical, and mail it when an address is configured. At most one mail
     * per job class every 15 minutes, so a job that keeps failing does not flood the inbox.
     */
    private function reportFailedJob(JobFailed $event): void
    {
        $job = $event->job->resolveName();
        $queue = (string) $event->job->getQueue();
        $exception = $event->exception::class;

        Log::critical("Queue job {$job} failed on the {$queue} queue.", [
            'connection' => $event->connectionName,
            'exception' => $exception,
        ]);

        $email = config('monitoring.horizon.notifications_email');

        if (blank($email) || ! Cache::add("monitoring:failed-job:{$job}", true, now()->addMinutes(15))) {
            return;
        }

        try {
            Notification::route('mail', $email)->notify(new QueueJobFailed($job, $queue, $exception));
        } catch (Throwable $e) {
            // The job has already failed; a mail problem must not stop the worker from recording it.
            report($e);
        }
    }
}
