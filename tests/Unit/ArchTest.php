<?php

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

it('depends on nothing of a host application')
    ->expect('Arzcode\FilamentDocs')
    ->not->toUse('App');
