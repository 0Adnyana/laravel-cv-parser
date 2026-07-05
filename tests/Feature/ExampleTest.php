<?php

test('root redirects to demo page', function () {
    $this->get('/')
        ->assertRedirect('/demo');
});
