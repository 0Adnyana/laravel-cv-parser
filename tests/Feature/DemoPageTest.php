<?php

test('demo page renders without authentication', function () {
    $this->get('/demo')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('demo'));
});

test('root redirects to demo page', function () {
    $this->get('/')
        ->assertRedirect('/demo');
});
