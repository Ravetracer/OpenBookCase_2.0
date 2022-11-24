<?php declare(strict_types=1);
/**
 * @author: Christian Nielebock <me@ravetracer.de>
 * Date: 17.11.22
 */

namespace App\Enums;

enum EntryType: string
{
    case Bookcase = 'bookcase';
    case Givebox = 'givebox';
}
