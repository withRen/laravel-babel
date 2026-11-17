<?php

it('dispose de l\'extension GMP exigée par le moteur Babel', function () {
    expect(extension_loaded('gmp'))->toBeTrue();
});

it('tourne sur PHP 8.3 ou plus', function () {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80300);
});
