<?php

namespace App\Enums;

enum VirtualOrderMenuRestaurantStatus: string
{
    case RESERVED = 'reserved';
    case PARTIALLY_DELIVERED = 'partially_delivered';
    case DELIVERED = 'delivered';
}
