<?php


namespace SmallRabbit\Utils;

use Illuminate\Support\Facades\Log;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use PhpAmqpLib\Message\AMQPMessage;
use SmallRabbit\Services\Rabbit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

abstract class AbstractConsumer {

    public ?string $queue;
    protected int $maxTime = 30;
    protected int $tries = 3;
    public ?AMQPMessage $amqpMessage;
    public ?string $message;

    /**
     * Handle using consumer class in message consuming as callable
     *
     * @param AMQPMessage $message
     * @return void
     */
    final function __invoke(AMQPMessage $message)
    {
        $this->amqpMessage = $message;
        $this->message = $message->body;
        register_shutdown_function([$this, 'handleShutdown']);
        $attempt = 0;
        $pcntlAvailable = extension_loaded('pcntl');

        if ($pcntlAvailable) {
            function timeoutHandler()
            {
                throw new SmallRabbitException('Message processing timed out.');
            }

            declare(ticks = 1);
            pcntl_signal(SIGALRM, "timeoutHandler");
        }

        while ($attempt < $this->tries) {
            if ($pcntlAvailable) {
                pcntl_alarm($this->maxTime);
            }

            try {
                return $this->handle($message);

                if ($pcntlAvailable) {
                    pcntl_alarm(0);
                }

                $this->amqpMessage = $message;
                break;
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt >= $this->tries) {
                    $this->log("Max attempts reached for consumer: " . static::class);
                    $this->logToDB($this->message, 'Max attempts reached for consumer. Error: ' . $e->getMessage());
                } else {
                    $this->log("Attempt #{$attempt} failed for consumer: " . static::class);
                }
                $this->log("Consumer " . static::class . " error: ", [$e->getMessage()]);
            }
        }

        if ($pcntlAvailable) {
            pcntl_alarm(0);
        }
    }

    /**
     * Should be implemented in app consumer class
     */
    public abstract function handle();

    /**
     * Logging timeout exceeding for message consuming
     *
     * @return void
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && $error['type'] === E_ERROR && strpos($error['message'], 'Maximum execution time') !== false)
        {
            $this->log('Worker timeout limit exceeded for job processing: ' . $this::class);
            Process::run(PHP_BINARY . " artisan messages:error --data='" . base64_encode(json_encode([
                    'error'   => $error['message'],
                    'payload' => $this->message,
                    'class'   => $this::class,
                ])) . "'");
        }
    }

    /**
     * Set timeout value in seconds
     *
     * @param int $maxTime
     */
    public function setMaxTime(int $maxTime): void
    {
        $this->maxTime = $maxTime;
    }

    /**
     * Set tries number before consider handling failed
     *
     * @param int $tries
     */
    public function setTries(int $tries): void
    {
        $this->tries = $tries;
    }

    /**
     * Log message consuming handling error
     *
     * @param string $message
     * @param array $payload
     * @return void
     */
    protected function log(string $message, array $payload = []): void
    {
        if (app('rabbit')->isLogEnabled())
        {
            Log::error($message, $payload);
        }
    }

    /**
     * Log message consuming handling error to database table
     *
     * @param string $payload
     * @param string $error
     * @param string|null $class
     * @return void
     */
    protected function logToDB(string $payload, string $error, string $class = null): void
    {
        if (config('smallrabbit.save_not_processed_messages'))
        {
            if (empty($class))
            {
                $class = $this::class;
            }
            DB::table('not_processed_messages')->insert([
                'payload'        => $payload,
                'error'          => $error,
                'consumer_class' => $class,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }


    }

}
