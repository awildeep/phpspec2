<?php

namespace PHPSpec2\Stub\Verification;

use PHPSpec2\Matcher\MatchersCollection;
use PHPSpec2\Stub\ArgumentsResolver;

class Negative
{
    private $subject;
    private $matchers;
    private $resolver;

    public function __construct($subject, MatchersCollection $matchers, ArgumentsResolver $resolver)
    {
        $this->subject  = $subject;
        $this->matchers = $matchers;
        $this->resolver = $resolver;
    }

    public function __call($name, array $arguments = array())
    {
        $arguments = $this->resolver->resolve($arguments);

        $matcher = $this->matchers->find($name, $this->subject, $arguments);

        return $matcher->negativeMatch($name, $this->subject, $arguments);
    }
}