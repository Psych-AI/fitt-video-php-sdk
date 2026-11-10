<?php

namespace Fitt\Video\Exceptions;

/**
 * The API answered 422 (`validation_failed`). `$fields` maps each refused field
 * to its messages.
 */
final class ValidationException extends ApiException {}
