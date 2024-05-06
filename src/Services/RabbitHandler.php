<?php


namespace SmallRabbit\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use Illuminate\Support\Facades\Response;
use SmallRabbit\Utils\SmallRabbitException;
use SmallRabbit\Utils\AbstractConsumer;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Exception\AMQPIOException;
use Illuminate\Support\Facades\Log;

class RabbitHandler implements RabbitHandlerInterface {

    private static $instance = null;
    private ?AMQPStreamConnection $connection;
    private ?AMQPChannel $channel;
    private array $declaredQueues = [];
    private array $declaredExchanges = [];
    private array $declaredBindings = [];
    private array $consumers = [];
    private int $connectionAttempts = 0;
    private int $maxConnectionAttempts = 5;
    private int $totalDelay = 0;
    private int $maxTotalDelay = 15;

    private function __construct()
    {
        $this->connect();
    }

    /**
     * @return RabbitHandler
     */
    public static function getInstance(): RabbitHandler
    {
        if (self::$instance === null)
        {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __destruct()
    {
        $this->channel->close();
        $this->connection->close();
    }

    /**
     * Check if queue is already declared if not declare it
     *
     * @param $queueName
     * @return void
     */
    private function ensureQueue($queueName):void
    {
        if (!isset($this->declaredQueues[$queueName]))
        {
            $this->channel->queue_declare($queueName, false, true, false, false);
            $this->declaredQueues[$queueName] = true;
        }
    }

    /**
     * Check if exchange already declared, if not declare it
     *
     * @param $exchangeName
     * @param $exchangeType
     * @return void
     */
    private function ensureExchange($exchangeName, $exchangeType):void
    {
        if (!isset($this->declaredExchanges[$exchangeName]))
        {
            $this->channel->exchange_declare($exchangeName, $exchangeType, false, true, false);
            $this->declaredExchanges[$exchangeName] = true;
        }
    }

    /**
     * Check if binding for exchange exists if not bind it
     *
     * @param $queueName
     * @param $exchangeName
     * @param $exchangeType
     * @param $routingKey
     * @return void
     */
    public function ensureBinding($queueName, $exchangeName, $exchangeType, $routingKey): void
    {
        $this->ensureQueue($queueName);
        $this->ensureExchange($exchangeName, $exchangeType);

        $bindingKey = sprintf('%s|%s|%s', $exchangeName, $queueName, $routingKey);
        if (!isset($this->declaredBindings[$bindingKey]))
        {
            $this->channel->queue_bind($queueName, $exchangeName, $routingKey);
            $this->declaredBindings[$bindingKey] = true;
        }
    }

    /**
     * Send message to RabbitMQ queue
     *
     * @param string $message      Message payload
     * @param string $queueName    Name of the queue
     * @param string $exchangeName Name of the exchange
     * @param string $routingKey   Routing key
     * @param string $exchangeType Exchange type
     * @return void
     * @throws SmallRabbitException
     */
    public function send(string $message, string $queueName = '', string $exchangeName = '', string $routingKey = '', string $exchangeType = 'direct'):void
    {
        $this->validateSendArguments($message, $queueName, $exchangeName, $routingKey, $exchangeType);
        try
        {
            if (!empty($queueName) && !empty($exchangeName))
            {
                // if we have exchange and queue check binding
                $this->ensureBinding($queueName, $exchangeName, $exchangeType, $routingKey);
            } elseif (!empty($exchangeName))
            {
                // if we have only exchange check binding
                $this->ensureExchange($exchangeName, $exchangeType);
                if (!$this->checkExchangeBindings($exchangeName, $routingKey))
                {
                    throw new SmallRabbitException("Error: The exchange '$exchangeName' with routing key '$routingKey' is not bound to any queue!");
                }
            } elseif (!empty($queueName))
            {
                // usecase for default exchange
                $routingKey = $queueName;
                $this->ensureQueue($queueName);  // Убеждаемся, что очередь существует
            } else
            {
                throw new \Exception("Error: No exchange or queue specified for sending the message.");
            }

            $msg = new AMQPMessage($message, ['delivery_mode' => 2]);
            $this->channel->basic_publish($msg, $exchangeName, $routingKey);

        } catch (AMQPConnectionClosedException|AMQPTimeoutException|AMQPIOException $e)
        {
            $this->reconnect();
            $this->send($message, $queueName, $exchangeName, $routingKey, $exchangeType);
            $this->log('RabbitMQ connection error. Reconnection initialized');
        } catch (\Exception $e)
        {
            $this->log($e->getMessage());
            throw new SmallRabbitException($e->getMessage());
        }

    }

    /**
     * Check if existing combination exchangeName and routingKey exists or match existing routing which already bound
     *
     * @param $exchangeName
     * @param $routingKey
     * @return bool
     */
    private function checkExchangeBindings($exchangeName, $routingKey): bool
    {
        foreach ($this->declaredBindings as $key => $value)
        {
            list($boundExchange, $boundQueue, $boundRoutingKey) = explode('|', $key);
            if ($boundExchange === $exchangeName && $this->routingKeyMatches($boundRoutingKey, $routingKey))
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if key match existing routing which already bound
     *
     * @param $pattern
     * @param $routingKey
     * @return bool|int
     */
    private function routingKeyMatches($pattern, $routingKey): bool|int
    {
        $pattern = str_replace('.', '\.', $pattern);
        $pattern = str_replace('*', '[^.]+', $pattern);
        $pattern = str_replace('#', '.*', $pattern);

        return preg_match('/^' . $pattern . '$/', $routingKey);
    }

    /**
     * Check valid arguments values combination to send message
     *
     * @param $message
     * @param $queueName
     * @param $exchangeName
     * @param $routingKey
     * @param $exchangeType
     * @return void
     * @throws SmallRabbitException
     */
    private function validateSendArguments($message, $queueName, $exchangeName, $routingKey, $exchangeType): void
    {
        if (empty($message)) {
            throw new SmallRabbitException("Error: Message cannot be empty.");
        }

        // Невалидные комбинации:
        // 1. Отсутствует и имя обменника, и имя очереди
        if (empty($exchangeName) && empty($queueName)) {
            throw new SmallRabbitException("Error: Both exchange and queue cannot be empty.");
        }

        // 2. Указан ключ маршрутизации, но не указан обменник (необходим для direct и topic exchanges)
        if (!empty($routingKey) && empty($exchangeName)) {
            throw new SmallRabbitException("Error: Exchange name must be provided if routing key is specified.");
        }

        // 3. Неверный тип обменника
        if (!empty($exchangeName) && !in_array($exchangeType, ['direct', 'topic', 'headers', 'fanout'])) {
            throw new SmallRabbitException("Error: Invalid exchange type. Valid types are 'direct', 'topic', 'headers', 'fanout'.");
        }

        // 4. Необходимость ключа маршрутизации для direct и topic exchanges
        if (!empty($exchangeName) && empty($routingKey) && in_array($exchangeType, ['direct', 'topic'])) {
            throw new SmallRabbitException("Error: Routing key is required for 'direct' and 'topic' exchange types.");
        }

        // Дополнительная проверка для 'direct' и 'topic' обменников
        if (!empty($exchangeName) && empty($routingKey) && in_array($exchangeType, ['direct', 'topic'])) {
            throw new SmallRabbitException("Error: Routing key is required for 'direct' and 'topic' exchange types when an exchange is specified.");
        }
    }

    /**
     * Consume message from RabbitMQ queue
     *
     * @param string $queue_name Name of the queue
     * @param callable $callback Callback function to process the consumed message
     * @return void
     * @throws SmallRabbitException
     */
    public function consume(string $queue_name, callable $callback):void
    {
        try
        {
            $this->ensureQueue($queue_name);
            $this->channel->basic_consume($queue_name, '', false, true, false, false, $callback);
            try
            {
                while ($this->channel->is_consuming())
                {
                    $this->channel->wait();
                }
            } catch (\Exception $e)
            {
                echo "An error occurred: " . $e->getMessage();
            }
        } catch (AMQPConnectionClosedException|AMQPTimeoutException|AMQPIOException $e)
        {
            $this->reconnect();
            $this->consume($queue_name, $callback);
            $this->log('RabbitMQ connection error. Reconection initialized');
        } catch (\Exception $e)
        {
            $this->log($e->getMessage());
            throw new SmallRabbitException($e->getMessage());
        }
    }

    /**
     * Send message to RabbitMQ queue, convert message object to json before sending it
     *
     * @param object|array $payload Message payload, can be an object or an array
     * @param string $queueName Name of the queue
     * @param string $exchangeName Name of the exchange
     * @param string $routingKey Routing key
     * @param string $exchangeType Exchange type
     * @return void
     * @throws SmallRabbitException
     */
    public function toJsonSend(object|array $payload, string $queueName = '', string $exchangeName = '', string $routingKey = '', string $exchangeType = 'direct'): void
    {
        $jsonResponse = Response::json($payload);
        $this->send($jsonResponse->content(), $queueName = '', $exchangeName = '', $routingKey = '', $exchangeType);
    }

    /**
     * Set consumer classes for message consuming handling
     * example: setConsumers(['orders' => App\Consumers\OrderConsumer::class])
     *
     * @param array $consumers
     * @return void
     * @throws SmallRabbitException
     */
    public function setConsumers(array $consumers):void
    {
        foreach ($consumers as $consumerClass)
        {
            if (!class_exists($consumerClass))
            {
                throw new SmallRabbitException("Class $consumerClass not found.");
            }

            if (!is_subclass_of($consumerClass, AbstractConsumer::class))
            {
                throw new SmallRabbitException("Class $consumerClass should be subclass of AbstractConsumer.");
            }
        }

        $this->consumers = $consumers;
    }

    /**
     * Get consumer classes array which used for message consuming
     *
     * @return array
     */
    public function getConsumers(): array
    {

        return $this->consumers;
    }

    /**
     * Check if error logging enabled
     *
     * @return bool
     */
    public function isLogEnabled(): bool
    {
        return config('smallrabbit.log_errors');
    }

    /**
     * Close connection to RabbitMQ server
     * @return void
     * @throws SmallRabbitException
     */
    public function connect():void
    {
        $initialDelay = 2;
        while ($this->connectionAttempts < $this->maxConnectionAttempts && $this->totalDelay < $this->maxTotalDelay)
        {
            try
            {
                $this->connection = new AMQPStreamConnection(
                    config('smallrabbit.host'),
                    config('smallrabbit.port'),
                    config('smallrabbit.user'),
                    config('smallrabbit.password')
                );
                $this->channel = $this->connection->channel();
                $this->connectionAttempts = 0;
                $this->totalDelay = 0;

                return;
            } catch (\PhpAmqpLib\Exception\AMQPIOException $e)
            {
                $this->connectionAttempts++;
                $currentDelay = min($initialDelay * pow(2, $this->connectionAttempts - 1), $this->maxTotalDelay - $this->totalDelay);
                sleep($currentDelay);
                $this->totalDelay += $currentDelay;
            }
        }

        throw new SmallRabbitException("Unable to connect to RabbitMQ after $this->totalDelay seconds of trying.");
    }

    /**
     * @return void
     * @throws SmallRabbitException
     */
    private function reconnect(): void
    {
        $this->close();
        $this->connect();
    }
    /**
     * Close connection to RabbitMQ server
     * @return void
     */
    public function close():void
    {
        if ($this->channel)
        {
            $this->channel->close();
        }
        if ($this->connection)
        {
            $this->connection->close();
        }
    }

    /**
     * Log error message to log file
     *
     * @param string $message
     * @param array $payload
     * @return void
     */
    protected function log(string $message, array $payload = []): void
    {
        if ($this->isLogEnabled())
        {
            Log::error($message, $payload);
        }
    }
}
