<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Echo — emails the site owner at each important step of the funnel, plus a morning
 * digest of yesterday's counters, so the owner never has to open the admin.
 *
 * `Bundle` rather than `AbstractBundle`, as in Sentinelle: the latter carries its own
 * configuration and ignores an extension class written alongside it.
 */
class EchoBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
