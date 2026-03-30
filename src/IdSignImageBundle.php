<?php

declare(strict_types=1);

namespace IdSign\ImageBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class IdSignImageBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
