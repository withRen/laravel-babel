<?php

it('répond sur la route de santé', function () {
    $this->get('/up')->assertOk();
});

it('refuse /api/user sans authentification', function () {
    $this->getJson('/api/user')->assertUnauthorized();
});

it('redirige la racine vers le panneau Filament', function () {
    $this->get('/')->assertRedirect('/admin');
});
