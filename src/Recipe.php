<?php

namespace Fitt\Video;

/**
 * The fields of `POST /sessions`, built fluently (spec 9.4). Immutable: every
 * setter returns a new recipe. `toArray()` keeps the order the setters were
 * called in, and a nested field (`context.question`) creates its parent.
 */
final class Recipe
{
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
