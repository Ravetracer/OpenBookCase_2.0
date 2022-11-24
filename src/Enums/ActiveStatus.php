<?php declare(strict_types=1);
/**
 * @author: Christian Nielebock <me@ravetracer.de>
 *
 * Date: 15.11.22
 */

namespace App\Enums;

enum ActiveStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
