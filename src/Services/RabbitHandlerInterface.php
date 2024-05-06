<?php


namespace SmallRabbit\Services;


use SmallRabbit\Utils\SmallRabbitException;

interface RabbitHandlerInterface {


    /**
     * Send message to RabbitMQ queue
     *
     * @param string $message Message payload
     * @param string $queueName Name of the queue
     * @param string $exchangeName Name of the exchange
     * @param string $routingKey Routing key
     * @param string $exchangeType Exchange type
     * @return void
     * @throws SmallRabbitException
     */
    public function send(string $message, string $queueName = '', string $exchangeName = '', string $routingKey = '', string $exchangeType = 'direct'):void;

    /**
     * Consume message from RabbitMQ queue
     *
     * @param string $queue_name Name of the queue
     * @param callable $callback Callback function to process the consumed message
     * @return void
     * @throws SmallRabbitException
     */
    public function consume(string $queue_name, callable $callback):void;

    /**
     * Close connection to RabbitMQ server
     * @return void
     */

    public function close():void;

    /**
     * Close connection to RabbitMQ server
     * @return void
     * @throws SmallRabbitException
     */
    public function connect():void;

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
    public function toJsonSend(object|array $payload, string $queueName = '', string $exchangeName = '', string $routingKey = '', string $exchangeType = 'direct'):void;

    /**
     * Get consumer classes array which used for message consuming
     *
     * @return array
     */
    public function getConsumers():array;

    /**
     * Set consumer classes for message consuming handling
     * example: setConsumers(['orders' => App\Consumers\OrderConsumer::class])
     *
     * @param array $consumers
     * @return void
     */
    public function setConsumers(array $consumers):void;

    /**
     * Check if error logging enabled
     *
     * @return bool
     */
    public function isLogEnabled(): bool;


}
