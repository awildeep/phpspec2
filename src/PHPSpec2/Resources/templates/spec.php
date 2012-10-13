<?php

namespace %namespace%;

use PHPSpec2\ObjectBehavior;
use PHPSpec2\Exception\Example\PendingException;

class %class% extends ObjectBehavior
{
    function it_should_be_initializable()
    {
        $this->shouldHaveType('%subject%');
    }
}
