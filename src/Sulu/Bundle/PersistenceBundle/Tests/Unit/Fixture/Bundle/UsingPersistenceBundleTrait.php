<?php
/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PersistenceBundle\Tests\Unit\Fixture\Bundle;

use Sulu\Bundle\PersistenceBundle\PersistenceBundleTrait;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class UsingPersistenceBundleTrait extends Bundle
{
    use PersistenceBundleTrait;

    public $modelInterfaces = array();

    /**
     * @return array
     */
    protected function getModelInterfaces()
    {
        return $this->modelInterfaces;
    }
}
