<?php


namespace SmallRabbit\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Class Rabbit
 * Facade for service SmallRabbi\Services\RabbitHandler::class
 *
 * @package SmallRabbit\Facades
 * @method static send(string $message, string $queueName = '', string $exchangeName = '', string $routingKey = '', string $exchangeType = 'direct');
 * @method static consume(string $queue_name, callable $callback);
 * @method static close();
 * @method static connect();
 * @method static toJsonSend(object|array $payload, string $queueName = '', string $exchangeName = '', string $routingKey = '', string $exchangeType = 'direct');
 * @method static getConsumers():array;
 * @method static setConsumers(array $consumers):void;
 * @method static isLogEnabled(): bool;
 */
class Rabbit extends Facade{

    protected static function getFacadeAccessor() {

        return 'rabbit';
    }
}
