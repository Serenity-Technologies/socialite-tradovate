<?php

namespace SocialiteProviders\Tradovate;

use SocialiteProviders\Manager\SocialiteWasCalled;

class TradovateExtendSocialite
{
    public function handle(SocialiteWasCalled $event): void
    {
        $event->extendSocialite('tradovate', Provider::class);
        $event->extendSocialite('tradovate-demo', Provider::class);
    }
}
