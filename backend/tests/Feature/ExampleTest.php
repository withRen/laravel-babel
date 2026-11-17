<?php

it('répond sur la route de santé', function () {
    $this->get('/up')->assertOk();
});

it('redirige la racine vers le panneau Filament', function () {
    $this->get('/')->assertRedirect('/admin');
});
