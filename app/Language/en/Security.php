<?php

// Overrides the framework's message: CSRF only guards the (Russian-language) web UI, where this
// text reaches the operator as a flash after the redirect back (Config\Security::$redirect).
return [
    'disallowedAction' => 'Форма устарела — обновите страницу и повторите действие.',
];
