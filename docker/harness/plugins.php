<?php

/**
 * Every gateway package: its slug (omnishield/<slug>, github.com/glitchr-omnisense/omnishield-<slug>),
 * its tests' namespace and its factory class.
 */
return [
    'altcha' => ['Omnishield\\Altcha\\Tests\\', 'Omnishield\\Altcha\\AltchaGatewayFactory'],
    'turnstile' => ['Omnishield\\Turnstile\\Tests\\', 'Omnishield\\Turnstile\\TurnstileGatewayFactory'],
    'recaptcha' => ['Omnishield\\Recaptcha\\Tests\\', 'Omnishield\\Recaptcha\\RecaptchaGatewayFactory'],
    'akismet' => ['Omnishield\\Akismet\\Tests\\', 'Omnishield\\Akismet\\AkismetGatewayFactory'],
    'stopforumspam' => ['Omnishield\\Stopforumspam\\Tests\\', 'Omnishield\\Stopforumspam\\StopforumspamGatewayFactory'],
    'disposable' => ['Omnishield\\Disposable\\Tests\\', 'Omnishield\\Disposable\\DisposableGatewayFactory'],
];
