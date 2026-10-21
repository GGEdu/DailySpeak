<?php

namespace Tests\Feature\Providers;

use App\Notifications\QueueJobFailed;
use App\Providers\HorizonServiceProvider;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Notifications\LongWaitDetected;
use RuntimeException;
use Tests\TestCase;

class HorizonNotificationsTest extends TestCase
{
    private const JOB = 'App\\Jobs\\ProcessVoiceDebate';

    public function test_long_queue_waits_are_mailed_to_the_configured_address(): void
    {
        config(['monitoring.horizon.notifications_email' => 'ops@example.com']);

        (new HorizonServiceProvider(app()))->boot();

        $this->assertSame('ops@example.com', Horizon::$email);
        $this->assertContains('mail', (new LongWaitDetected('redis', 'debates', 30))->via(null));
    }

    public function test_without_an_address_horizon_sends_no_mail(): void
    {
        config(['monitoring.horizon.notifications_email' => null]);

        (new HorizonServiceProvider(app()))->boot();

        $this->assertNotContains('mail', (new LongWaitDetected('redis', 'debates', 30))->via(null));
    }

    public function test_a_failed_job_is_logged_as_critical_with_its_name(): void
    {
        Log::spy();

        event(new JobFailed('redis', $this->failedJob(self::JOB), new RuntimeException('boom')));

        Log::shouldHaveReceived('critical')
            ->withArgs(fn (string $message) => str_contains($message, 'ProcessVoiceDebate'))
            ->once();
    }

    public function test_a_failed_job_is_mailed_when_an_address_is_configured(): void
    {
        config(['monitoring.horizon.notifications_email' => 'ops@example.com']);
        Notification::fake();

        event(new JobFailed('redis', $this->failedJob(self::JOB), new RuntimeException('boom')));

        Notification::assertSentOnDemand(
            QueueJobFailed::class,
            fn (QueueJobFailed $notification, array $channels, $notifiable) => $notifiable->routeNotificationFor('mail') === 'ops@example.com',
        );
    }

    public function test_a_failed_job_is_not_mailed_without_an_address(): void
    {
        config(['monitoring.horizon.notifications_email' => null]);
        Notification::fake();

        event(new JobFailed('redis', $this->failedJob(self::JOB), new RuntimeException('boom')));

        Notification::assertNothingSent();
    }

    public function test_the_same_job_is_mailed_once_every_fifteen_minutes(): void
    {
        config(['monitoring.horizon.notifications_email' => 'ops@example.com']);
        Notification::fake();

        event(new JobFailed('redis', $this->failedJob(self::JOB), new RuntimeException('first')));
        event(new JobFailed('redis', $this->failedJob(self::JOB), new RuntimeException('second')));
        Notification::assertSentOnDemandTimes(QueueJobFailed::class, 1);

        event(new JobFailed('redis', $this->failedJob('App\\Jobs\\AnalyzeVocabulary'), new RuntimeException('third')));
        Notification::assertSentOnDemandTimes(QueueJobFailed::class, 2);

        $this->travel(16)->minutes();
        event(new JobFailed('redis', $this->failedJob(self::JOB), new RuntimeException('later')));
        Notification::assertSentOnDemandTimes(QueueJobFailed::class, 3);
    }

    public function test_the_mail_names_the_job_and_the_exception(): void
    {
        $mail = (new QueueJobFailed(self::JOB, 'debates', 'RuntimeException'))->toMail(new AnonymousNotifiable);

        $this->assertStringContainsString('a queue job failed', $mail->subject);
        $this->assertStringContainsString(self::JOB.' failed on the debates queue.', implode("\n", $mail->introLines));
        $this->assertStringContainsString('Exception: RuntimeException.', implode("\n", $mail->introLines));
    }

    public function test_a_mail_that_cannot_be_sent_does_not_break_the_failure_handling(): void
    {
        config(['monitoring.horizon.notifications_email' => 'ops@example.com']);
        Log::spy();
        Notification::shouldReceive('route')->andThrow(new RuntimeException('SMTP is down'));

        event(new JobFailed('redis', $this->failedJob(self::JOB), new RuntimeException('boom')));

        Log::shouldHaveReceived('critical')->once();
    }

    /**
     * A job whose payload names its class, as the queue worker would see it.
     */
    private function failedJob(string $displayName): FakeJob
    {
        return new class($displayName) extends FakeJob
        {
            public function __construct(private string $displayName) {}

            public function getRawBody()
            {
                return json_encode([
                    'displayName' => $this->displayName,
                    'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                    'data' => [],
                ]);
            }
        };
    }
}
