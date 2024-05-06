<?php

namespace SmallRabbit\Utils;

enum ChannelType:string {

    case Direct = 'direct';
    case Topic = 'topic';
    case Fanout = 'fanout';
    case Headers = 'headers';
}
