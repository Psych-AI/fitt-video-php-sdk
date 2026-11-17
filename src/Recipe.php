<?php

namespace Fitt\Video;

/**
 * The fields of `POST /sessions`, built fluently (spec 9.4). Immutable: every
 * setter returns a new recipe. `toArray()` keeps the order the setters were
 * called in, and a nested field (`context.question`) creates its parent.
 */
final class Recipe
{
    /**
     * The signal groups a session may record (spec 6.6). The platform refuses
     * any other name with 422, so `signals()` refuses it before the request.
     *
     * @var list<string>
     */
    public const array SIGNAL_GROUPS = ['attention', 'window', 'screen', 'fullscreen', 'keys', 'clipboard', 'input', 'device', 'net', 'page', 'recorder'];

    /** @var array<string, mixed> */
    private array $fields;

    private function __construct(string $name)
    {
        $this->fields = ['recipe' => $name];
    }

    public static function answer(): self
    {
        return new self('answer');
    }

    public static function monitor(): self
    {
        return new self('monitor');
    }

    public static function conversation(): self
    {
        return new self('conversation');
    }

    public static function file(): self
    {
        return new self('file');
    }

    /**
     * Records one audio track and no camera (Plan 10). For `answer` it keeps the
     * checks and options; for `conversation` the track carries the interviewer
     * on the right channel.
     *
     * @throws \LogicException on monitor and file
     */
    public function audioOnly(): self
    {
        if (! in_array($this->fields['recipe'], ['answer', 'conversation'], true)) {
            throw new \LogicException('audioOnly() is for the answer and conversation recipes.');
        }

        return $this->with('tracks', ['audio']);
    }

    /**
     * The signal groups the browser records, in place of the recipe's
     * defaults (`signals.groups`). The descriptor lists exactly these.
     *
     * @param  list<string>  $groups  names from SIGNAL_GROUPS
     *
     * @throws \InvalidArgumentException on an unknown group
     */
    public function signals(array $groups): self
    {
        foreach ($groups as $group) {
            if (! is_string($group) || ! in_array($group, self::SIGNAL_GROUPS, true)) {
                $shown = is_scalar($group) ? (string) $group : gettype($group);
                $known = implode(', ', self::SIGNAL_GROUPS);

                throw new \InvalidArgumentException("Unknown signal group '{$shown}'. The groups are: {$known}.");
            }
        }

        return $this->with('signals.groups', array_values($groups));
    }

    /**
     * Recording timings in milliseconds, merged over the recipe's: `countdown_ms`,
     * `min_duration_ms`, `max_duration_ms`, `extension_ms`, `warning_before_end_ms`.
     * The platform refuses an unknown key or a duration above the space's cap
     * with 422 `config_invalid`.
     *
     * @param  array<string, int>  $recording
     */
    public function recording(array $recording): self
    {
        return $this->with('recording', $recording);
    }

    /**
     * Capture settings for one track (`camera` or `audio`), merged over the
     * recipe's: `width`, `height`, `frame_rate`, `video_bitrate_bps`,
     * `audio_bitrate_bps`, `audio_channels` (the audio track takes the last two).
     *
     * @param  array<string, int>  $settings
     */
    public function capture(string $track, array $settings): self
    {
        return $this->with("capture.{$track}", $settings);
    }

    /**
     * The browser rules the SDK enforces, for example
     * `['stay_on_page' => ['grace_ms' => 5000]]`.
     *
     * @param  array<string, mixed>  $enforce
     */
    public function enforce(array $enforce): self
    {
        return $this->with('enforce', $enforce);
    }

    /**
     * Lower the session cap below the space's (`caps.session_cap_ms`, at least
     * 1000). A cap above the space's is refused with 422 `cap_exceeds_space`.
     */
    public function sessionCap(int $milliseconds): self
    {
        return $this->with('caps.session_cap_ms', $milliseconds);
    }

    public function question(string $question): self
    {
        return $this->with('context.question', $question);
    }

    public function speaker(string $name): self
    {
        return $this->with('context.speaker_name', $name);
    }

    public function context(string $context): self
    {
        return $this->with('context.context', $context);
    }

    public function language(string $language): self
    {
        return $this->with('context.language', $language);
    }

    public function outputLanguage(string $language): self
    {
        return $this->with('context.output_language', $language);
    }

    public function externalRef(string $externalRef): self
    {
        return $this->with('external_ref', $externalRef);
    }

    /**
     * @param  list<string>  $tags
     */
    public function tags(array $tags): self
    {
        return $this->with('tags', $tags);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): self
    {
        return $this->with('metadata', $metadata);
    }

    public function webhookUrl(string $url): self
    {
        return $this->with('webhook_url', $url);
    }

    public function playbackInStatus(bool $on = true): self
    {
        return $this->with('playback_in_status', $on);
    }

    /**
     * @param  list<string>  $options
     */
    public function options(array $options): self
    {
        return $this->with('options', $options);
    }

    public function exposeReason(bool $on = true): self
    {
        return $this->with('checks.expose_reason', $on);
    }

    /**
     * @param  list<string>  $reasons
     */
    public function rejectOn(array $reasons): self
    {
        return $this->with('checks.reject_on', $reasons);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fields;
    }

    private function with(string $path, mixed $value): self
    {
        $copy = clone $this;
        $target = &$copy->fields;

        foreach (explode('.', $path) as $key) {
            $target[$key] ??= [];
            $target = &$target[$key];
        }

        $target = $value;

        return $copy;
    }
}
