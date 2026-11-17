<?php

use Fitt\Video\Recipe;

test('the answer recipe of the spec example', function () {
    expect(Recipe::answer()->question('Why?')->speaker('Ann')->playbackInStatus()->toArray())
        ->toBe(['recipe' => 'answer', 'context' => ['question' => 'Why?', 'speaker_name' => 'Ann'], 'playback_in_status' => true]);
});

test('every setter lands where POST /sessions reads it', function () {
    expect(Recipe::conversation()->audioOnly()
        ->question('Q')->speaker('Ann')->context('Night shift lead')->language('af')->outputLanguage('en')
        ->externalRef('cand-7')->tags(['cohort:7'])->metadata(['stage' => 2])->webhookUrl('https://host.test/hooks')
        ->options(['transcribe', 'summarise'])->exposeReason()->rejectOn(['no_speech'])->playbackInStatus(false)
        ->toArray())->toBe([
            'recipe' => 'conversation',
            'tracks' => ['audio'],
            'context' => ['question' => 'Q', 'speaker_name' => 'Ann', 'context' => 'Night shift lead', 'language' => 'af', 'output_language' => 'en'],
            'external_ref' => 'cand-7',
            'tags' => ['cohort:7'],
            'metadata' => ['stage' => 2],
            'webhook_url' => 'https://host.test/hooks',
            'options' => ['transcribe', 'summarise'],
            'checks' => ['expose_reason' => true, 'reject_on' => ['no_speech']],
            'playback_in_status' => false,
        ]);
});

test('each recipe names itself, and a setter returns a new recipe', function () {
    $base = Recipe::answer();
    $asked = $base->question('Q');

    expect(Recipe::monitor()->toArray())->toBe(['recipe' => 'monitor'])
        ->and(Recipe::file()->toArray())->toBe(['recipe' => 'file'])
        ->and($base->toArray())->toBe(['recipe' => 'answer'])
        ->and($asked->toArray())->toBe(['recipe' => 'answer', 'context' => ['question' => 'Q']]);
});

test('audioOnly records the audio track alone for answer and conversation, and is refused for monitor and file', function () {
    expect(Recipe::answer()->audioOnly()->question('Q')->toArray())->toBe(['recipe' => 'answer', 'tracks' => ['audio'], 'context' => ['question' => 'Q']])
        ->and(Recipe::conversation()->audioOnly()->toArray())->toBe(['recipe' => 'conversation', 'tracks' => ['audio']]);

    expect(fn () => Recipe::monitor()->audioOnly())->toThrow(LogicException::class, 'audioOnly() is for the answer and conversation recipes.');
    expect(fn () => Recipe::file()->audioOnly())->toThrow(LogicException::class, 'audioOnly() is for the answer and conversation recipes.');
});
